#!/usr/bin/env bash
# Pruebas de actualizacion desde la 1.0.17 (ruta oculta mal colocada en .htaccess) y de desinstalacion.
# La desinstalacion NO borra el plugin (en Docker la carpeta es el codigo de trabajo): ejecuta solo
# uninstall.php con copia previa de los ajustes y .htaccess, y lo restaura todo despues.
# Los registros de intentos de acceso y de correos se pierden (son datos de pruebas).
PASSES=0; FAILS=0
ok()   { PASSES=$((PASSES+1)); echo "  OK    $1"; }
ko()   { FAILS=$((FAILS+1));  echo "  FALLO $1 :: $2"; }
check(){ if [[ "$2" == "$3" ]]; then ok "$1"; else ko "$1" "esperado [$3] obtenido [$2]"; fi; }
has()  { if [[ "$2" == *"$3"* ]]; then ok "$1"; else ko "$1" "no contiene [$3] en [${2:0:200}]"; fi; }
WPHP(){ docker exec -i -u www-data nolantis-gestion-wp php /dev/stdin <<<"<?php \$_SERVER['HTTP_HOST']='localhost:8090'; \$_SERVER['SERVER_SOFTWARE']='Apache'; require '/var/www/html/wp-load.php'; require_once ABSPATH.'wp-admin/includes/admin.php'; $1"; }
C=(docker exec nolantis-gestion-wp curl -s -H Host:localhost:8090)
perma(){ WPHP "global \$wp_rewrite; \$wp_rewrite->set_permalink_structure('$1'); flush_rewrite_rules(true);" >/dev/null; }

echo "== 1. Actualización desde la 1.0.17 con la ruta oculta rota"
SLUG=$(WPHP 'echo nolantis_get_custom_login_slug();')
docker exec -u www-data nolantis-gestion-wp cp /var/www/html/.htaccess /tmp/htaccess.bak
perma '/%postname%/'
# Estado de la 1.0.17: regla propia DETRAS del bloque de WordPress y sin registro de version.
WPHP "
\$f = ABSPATH . '.htaccess';
\$c = preg_replace( '/# BEGIN Nolantis Admin Access.*?# END Nolantis Admin Access\n*/s', '', file_get_contents( \$f ) );
\$c = rtrim( \$c ) . \"\n\n# BEGIN Nolantis Admin Access\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^$SLUG/?\$ wp-login.php?nolantis_admin_access=1 [QSA,L]\n</IfModule>\n# END Nolantis Admin Access\n\";
file_put_contents( \$f, \$c );
delete_option( 'nolantis_plugin_version' );" >/dev/null
HT=$(docker exec nolantis-gestion-wp cat /var/www/html/.htaccess)
if (( $(grep -n "^# BEGIN Nolantis" <<<"$HT" | cut -d: -f1) > $(grep -n "^# BEGIN WordPress" <<<"$HT" | cut -d: -f1) )); then ok "simulado: regla de la 1.0.17 detrás de la de WordPress"; else ko "simulación" "$HT"; fi
has "primera visita tras actualizar: la ruta muestra el formulario" "$("${C[@]}" http://localhost/$SLUG/)" 'id="loginform"'
HT=$(docker exec nolantis-gestion-wp cat /var/www/html/.htaccess)
if (( $(grep -n "^# BEGIN Nolantis" <<<"$HT" | cut -d: -f1) < $(grep -n "^# BEGIN WordPress" <<<"$HT" | cut -d: -f1) )); then ok "  ...y la regla se ha movido delante de la de WordPress"; else ko "orden tras actualizar" "$HT"; fi
has "  ...con el formato nuevo" "$HT" "RewriteRule ^$SLUG/?\$ wp-login.php [QSA,L]"
check "  ...versión registrada" "$(WPHP "echo get_option( 'nolantis_plugin_version' );")" "$(WPHP 'echo NOLANTIS_VERSION;')"
check "/wp-login.php sigue oculto" "$("${C[@]}" -o /dev/null -w '%{http_code}' http://localhost/wp-login.php)" 404
check "/login no revela la ruta" "$("${C[@]}" -o /dev/null -w '%{http_code}' http://localhost/login)" 404
perma ''
docker exec -u www-data nolantis-gestion-wp cp /tmp/htaccess.bak /var/www/html/.htaccess
WPHP 'nolantis_sync_admin_access_htaccess();' >/dev/null

echo "== 2. Desinstalación"
WPHP "
global \$wpdb;
\$original_hardening = get_option( NOLANTIS_HARDENING_OPTION, '__none__' );
\$backup = array(
    'hardening' => \$original_hardening,
    'options' => \$wpdb->get_results( \"SELECT option_name, option_value, autoload FROM {\$wpdb->options} WHERE option_name LIKE 'nolantis%' OR option_name LIKE 'external_updates-nolantis%'\", ARRAY_A ),
    'meta'    => \$wpdb->get_results( \"SELECT user_id, meta_value FROM {\$wpdb->usermeta} WHERE meta_key = '_nolantis_known_ips'\", ARRAY_A ),
);
file_put_contents( '/tmp/nolantis-uninstall-backup.json', wp_json_encode( \$backup ) );
\$s = nolantis_get_hardening_settings(); \$s['block_php_uploads'] = 1; update_option( NOLANTIS_HARDENING_OPTION, \$s );" >/dev/null
docker exec -u www-data nolantis-gestion-wp sh -c 'cp /var/www/html/.htaccess /tmp/htaccess.bak; cp /var/www/html/wp-content/uploads/.htaccess /tmp/uploads-htaccess.bak 2>/dev/null; true'
OUT=$(WPHP "
global \$wpdb;
update_user_meta( 1, '_nolantis_known_ips', array( '203.0.113.1' ) );
set_transient( 'nolantis_ll_alert_prueba', 1, 60 );
\$antes = (int) \$wpdb->get_var( \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '{\$wpdb->prefix}nolantis%'\" );
define( 'WP_UNINSTALL_PLUGIN', 'nolantis-gestion/nolantis-gestion-plugin.php' );
include WP_PLUGIN_DIR . '/nolantis-gestion/uninstall.php';
wp_cache_flush();
echo 'TABLAS_ANTES=', \$antes;
echo '|TABLAS=', \$wpdb->get_var( \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '{\$wpdb->prefix}nolantis%'\" );
echo '|OPCIONES=', \$wpdb->get_var( \"SELECT COUNT(*) FROM {\$wpdb->options} WHERE option_name LIKE 'nolantis%' OR option_name LIKE '%transient%nolantis%' OR option_name LIKE 'external_updates-nolantis%'\" );
echo '|META=', \$wpdb->get_var( \"SELECT COUNT(*) FROM {\$wpdb->usermeta} WHERE meta_key LIKE '%nolantis%'\" );
\$crons = 0; foreach ( _get_cron_array() as \$t => \$hooks ) { foreach ( array_keys( \$hooks ) as \$h ) { if ( false !== strpos( \$h, 'nolantis' ) ) { \$crons++; } } }
echo '|CRON=', \$crons;
echo '|HTACCESS=', substr_count( file_get_contents( ABSPATH . '.htaccess' ), 'Nolantis' );
\$u = wp_get_upload_dir(); echo '|UPLOADS=', file_exists( \$u['basedir'] . '/.htaccess' ) ? substr_count( file_get_contents( \$u['basedir'] . '/.htaccess' ), 'Nolantis' ) : 0;")
has "había tablas del plugin antes" "$OUT" "TABLAS_ANTES=3"
has "tablas borradas" "$OUT" "|TABLAS=0"
has "ajustes y temporales borrados" "$OUT" "|OPCIONES=0"
has "datos de usuarios borrados" "$OUT" "|META=0"
has "tareas programadas borradas" "$OUT" "|CRON=0"
has "regla de la ruta de acceso quitada de .htaccess" "$OUT" "|HTACCESS=0"
has "regla de uploads quitada" "$OUT" "|UPLOADS=0"

echo "== Restaurar tus ajustes"
WPHP "
global \$wpdb;
\$b = json_decode( file_get_contents( '/tmp/nolantis-uninstall-backup.json' ), true );
\$wpdb->query( \"DELETE FROM {\$wpdb->options} WHERE option_name LIKE 'nolantis%' OR option_name LIKE 'external_updates-nolantis%'\" );
foreach ( \$b['options'] as \$o ) { \$wpdb->insert( \$wpdb->options, \$o ); }
foreach ( \$b['meta'] as \$m ) { \$wpdb->insert( \$wpdb->usermeta, array( 'user_id' => \$m['user_id'], 'meta_key' => '_nolantis_known_ips', 'meta_value' => \$m['meta_value'] ) ); }
wp_cache_flush();
nolantis_ll_install(); nolantis_mail_log_install();
if ( '__none__' === \$b['hardening'] ) { delete_option( NOLANTIS_HARDENING_OPTION ); } else { update_option( NOLANTIS_HARDENING_OPTION, \$b['hardening'] ); }
nolantis_hardening_write_uploads_rules( nolantis_hardening_enabled( 'block_php_uploads' ) );
echo 'restaurado';" ; echo
docker exec -u www-data nolantis-gestion-wp sh -c 'cp /tmp/htaccess.bak /var/www/html/.htaccess; rm -f /tmp/htaccess.bak /tmp/uploads-htaccess.bak /tmp/nolantis-uninstall-backup.json'
WPHP 'nolantis_sync_admin_access_htaccess();' >/dev/null
has "tu ruta de acceso vuelve a funcionar" "$("${C[@]}" http://localhost/$SLUG/)" 'id="loginform"'

echo; echo "RESULTADO ACTUALIZACIÓN Y DESINSTALACIÓN: $PASSES OK, $FAILS FALLOS"
