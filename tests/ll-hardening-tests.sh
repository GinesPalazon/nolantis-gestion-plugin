#!/usr/bin/env bash
# Pruebas del modulo de endurecimiento (Parte 6).
SP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PASSES=0; FAILS=0
ok()   { PASSES=$((PASSES+1)); echo "  OK    $1"; }
ko()   { FAILS=$((FAILS+1));  echo "  FALLO $1 :: $2"; }
check(){ if [[ "$2" == "$3" ]]; then ok "$1"; else ko "$1" "esperado [$3] obtenido [$2]"; fi; }
has()  { if [[ "$2" == *"$3"* ]]; then ok "$1"; else ko "$1" "no contiene [$3] en [${2:0:200}]"; fi; }
hasnt(){ if [[ "$2" != *"$3"* ]]; then ok "$1"; else ko "$1" "contiene [$3]"; fi; }
WPHP(){ docker exec -i -u www-data nolantis-gestion-wp php /dev/stdin <<<"<?php \$_SERVER['HTTP_HOST']='localhost:8090'; \$_SERVER['SERVER_SOFTWARE']='Apache'; require '/var/www/html/wp-load.php'; require_once ABSPATH.'wp-admin/includes/admin.php'; $1"; }
C=(docker exec nolantis-gestion-wp curl -s -H Host:localhost:8090)
J=(-c /tmp/hardjar.txt -b /tmp/hardjar.txt)
UP=/var/www/html/wp-content/uploads

echo "== Preparación"
docker exec -i nolantis-gestion-wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/zz-nolantis-audit.php' < "$SP/zz-nolantis-audit.php"
WPHP "update_option( 'nolantis_test_hardening_backup', get_option( NOLANTIS_HARDENING_OPTION, '__none__' ) ); delete_option( NOLANTIS_HARDENING_OPTION );" >/dev/null
AP="Adm-$(openssl rand -hex 8)"
WPHP "wp_insert_user( array( 'user_login' => 'nolantis_hard_admin', 'user_pass' => '$AP', 'user_email' => 'hard@example.invalid', 'role' => 'administrator' ) );" >/dev/null
docker exec nolantis-gestion-wp rm -f /tmp/hardjar.txt
"${C[@]}" -o /dev/null "${J[@]}" --data-urlencode "log=nolantis_hard_admin" --data-urlencode "pwd=$AP" http://localhost/probandoruta1/
docker exec nolantis-gestion-wp sh -c "printf 'hola' > $UP/nolantis-prueba.txt; printf '<?php echo \"PHP-\" . \"EJECUTADO\";' > $UP/nolantis-prueba.php; chown www-data:www-data $UP/nolantis-prueba.*"
save(){ local h n; h=$("${C[@]}" "${J[@]}" 'http://localhost/wp-admin/admin.php?page=nolantis-hardening'); n=$(grep -oP 'name="_wpnonce" value="\K[^"]+' <<<"$h" | head -1); "${C[@]}" -o /dev/null "${J[@]}" -d "option_page=nolantis_hardening_group&action=update&_wpnonce=$n" "$@" http://localhost/wp-admin/options.php; }
page(){ "${C[@]}" "${J[@]}" 'http://localhost/wp-admin/admin.php?page=nolantis-hardening&settings-updated=true'; }

echo "== 1. Por defecto no cambia nada"
H=$("${C[@]}" -i http://localhost/)
has "la etiqueta generator sigue (todo desactivado)" "$H" 'name="generator"'
hasnt "sin cabeceras nuevas" "$H" 'X-Content-Type-Options'
check "el editor de archivos sigue permitido" "$(WPHP "echo user_can( get_user_by( 'login', 'nolantis_hard_admin' ), 'edit_themes' ) ? 'si' : 'no';")" "si"
check "PHP en uploads se ejecuta (como antes)" "$("${C[@]}" http://localhost/wp-content/uploads/nolantis-prueba.php)" "PHP-EJECUTADO"

echo "== 2. Activar todo desde el panel"
save -d "nolantis_hardening_settings[disable_file_edit]=1&nolantis_hardening_settings[hide_versions]=1&nolantis_hardening_settings[security_headers]=1&nolantis_hardening_settings[hsts]=1&nolantis_hardening_settings[block_php_uploads]=1"
P=$(page)
has "HSTS rechazado porque la web no usa HTTPS" "$P" "HSTS solo se puede activar si la web funciona por HTTPS"
has "uploads comprobado tras activarlo" "$P" "Comprobado: las imágenes y archivos de uploads se siguen sirviendo"
check "ajustes guardados" "$(WPHP 'echo wp_json_encode( get_option( NOLANTIS_HARDENING_OPTION ) );')" '{"disable_file_edit":1,"hide_versions":1,"security_headers":1,"hsts":0,"block_php_uploads":1}'
H=$("${C[@]}" -i http://localhost/)
hasnt "sin etiqueta generator" "$H" 'name="generator"'
has "cabecera nosniff" "$H" 'X-Content-Type-Options: nosniff'
has "cabecera X-Frame-Options" "$H" 'X-Frame-Options: SAMEORIGIN'
has "cabecera Referrer-Policy" "$H" 'Referrer-Policy: strict-origin-when-cross-origin'
hasnt "sin X-Powered-By (versión de PHP)" "$H" 'X-Powered-By'
hasnt "sin HSTS en HTTP" "$H" 'Strict-Transport-Security'
WPV=$(WPHP 'echo $GLOBALS["wp_version"];')
L=$("${C[@]}" http://localhost/probandoruta1/)
hasnt "la pantalla de acceso no muestra ?ver=$WPV" "$L" "ver=$WPV"
has "  ...pero sigue llevando un ?ver= para la caché" "$L" "login.min.css?ver="
hasnt "el feed RSS no revela la versión" "$("${C[@]}" 'http://localhost/?feed=rss2')" "<generator>"
check "editor de archivos desactivado" "$(WPHP "echo user_can( get_user_by( 'login', 'nolantis_hard_admin' ), 'edit_themes' ) ? 'si' : 'no';")" "no"
check "PHP en uploads bloqueado" "$("${C[@]}" -o /dev/null -w '%{http_code}' http://localhost/wp-content/uploads/nolantis-prueba.php)" 403
check "los archivos normales de uploads se sirven" "$("${C[@]}" http://localhost/wp-content/uploads/nolantis-prueba.txt)" "hola"
check "mayúsculas (.PHP) también bloqueado" "$(docker exec nolantis-gestion-wp sh -c "cp $UP/nolantis-prueba.php $UP/nolantis-prueba2.PHP"; "${C[@]}" -o /dev/null -w '%{http_code}' http://localhost/wp-content/uploads/nolantis-prueba2.PHP)" 403
check "no deja archivos de comprobación en uploads" "$(docker exec nolantis-gestion-wp sh -c "ls $UP | grep -c nolantis-check")" 0
has "el panel de admin sigue funcionando" "$("${C[@]}" "${J[@]}" http://localhost/wp-admin/)" "wpadminbar"

echo "== 3. Si al bloquear se rompieran las imágenes, se deshace"
save -d "nolantis_hardening_settings[block_php_uploads]=0"
WPHP "update_option( 'nolantis_test_loopback_404', 1 );" >/dev/null
save -d "nolantis_hardening_settings[block_php_uploads]=1"
WPHP "delete_option( 'nolantis_test_loopback_404' );" >/dev/null
has "aviso: se ha deshecho" "$(page)" "Por seguridad se ha deshecho el cambio"
check "opción desactivada de nuevo" "$(WPHP 'echo nolantis_hardening_enabled( "block_php_uploads" ) ? 1 : 0;')" 0
check "regla quitada de uploads/.htaccess" "$(docker exec nolantis-gestion-wp sh -c "cat $UP/.htaccess 2>/dev/null | grep -c 'Require all denied'")" 0

echo "== 4. Desactivar todo vuelve al estado normal"
save -d "nolantis_hardening_settings[block_php_uploads]=0"
H=$("${C[@]}" -i http://localhost/)
has "generator de vuelta" "$H" 'name="generator"'
hasnt "sin cabeceras" "$H" 'X-Content-Type-Options'
check "editor de nuevo permitido" "$(WPHP "echo user_can( get_user_by( 'login', 'nolantis_hard_admin' ), 'edit_themes' ) ? 'si' : 'no';")" "si"

echo "== 5. Desactivar y reactivar el plugin con el bloqueo activo"
save -d "nolantis_hardening_settings[block_php_uploads]=1"
WPHP "deactivate_plugins( 'nolantis-gestion/nolantis-gestion-plugin.php' );" >/dev/null
check "desactivado: regla de uploads quitada" "$(docker exec nolantis-gestion-wp sh -c "cat $UP/.htaccess 2>/dev/null | grep -c 'Require all denied'")" 0
WPHP "activate_plugin( 'nolantis-gestion/nolantis-gestion-plugin.php' );" >/dev/null
check "reactivado: regla restaurada" "$(docker exec nolantis-gestion-wp sh -c "grep -c 'Require all denied' $UP/.htaccess")" 1
check "  ...y PHP bloqueado otra vez" "$("${C[@]}" -o /dev/null -w '%{http_code}' http://localhost/wp-content/uploads/nolantis-prueba.php)" 403

echo "== Limpieza"
WPHP "
\$b = get_option( 'nolantis_test_hardening_backup' );
if ( '__none__' === \$b ) { delete_option( NOLANTIS_HARDENING_OPTION ); } else { update_option( NOLANTIS_HARDENING_OPTION, \$b ); }
nolantis_hardening_write_uploads_rules( nolantis_hardening_enabled( 'block_php_uploads' ) );
delete_option( 'nolantis_test_hardening_backup' );
require_once ABSPATH . 'wp-admin/includes/user.php'; \$u = get_user_by( 'login', 'nolantis_hard_admin' ); if ( \$u ) { wp_delete_user( \$u->ID ); }
echo 'ajustes de endurecimiento: ', wp_json_encode( get_option( NOLANTIS_HARDENING_OPTION ) );"; echo
docker exec nolantis-gestion-wp rm -f /tmp/hardjar.txt /var/www/html/wp-content/uploads/nolantis-prueba.txt /var/www/html/wp-content/uploads/nolantis-prueba.php /var/www/html/wp-content/uploads/nolantis-prueba2.PHP /var/www/html/wp-content/mu-plugins/zz-nolantis-audit.php
echo; echo "RESULTADO ENDURECIMIENTO: $PASSES OK, $FAILS FALLOS"
