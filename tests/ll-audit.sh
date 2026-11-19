#!/usr/bin/env bash
# Auditoria: ruta de acceso oculta + resto de modulos, contra el WordPress local (Apache, enlaces permanentes).
SP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SP/ll-lib.sh"
PASSES=0; FAILS=0
ok()   { PASSES=$((PASSES+1)); echo "  OK    $1"; }
ko()   { FAILS=$((FAILS+1));  echo "  FALLO $1 :: $2"; }
check(){ if [[ "$2" == "$3" ]]; then ok "$1"; else ko "$1" "esperado [$3] obtenido [$2]"; fi; }
has()  { if [[ "$2" == *"$3"* ]]; then ok "$1"; else ko "$1" "no contiene [$3] en [${2:0:220}]"; fi; }
hasnt(){ if [[ "$2" != *"$3"* ]]; then ok "$1"; else ko "$1" "contiene [$3]"; fi; }
not404(){ if [[ "$2" != "404" ]]; then ok "$1 ($2)"; else ko "$1" "404"; fi; }
WPHP(){ docker exec -i nolantis-gestion-wp php /dev/stdin <<<"<?php \$_SERVER['HTTP_HOST']='localhost:8090'; \$_SERVER['SERVER_SOFTWARE']='Apache'; require '/var/www/html/wp-load.php'; require_once ABSPATH.'wp-admin/includes/admin.php'; $1"; }
CURL=(docker exec nolantis-gestion-wp curl -s -H Host:localhost:8090)
code(){ "${CURL[@]}" -o /dev/null -w '%{http_code}' "$@"; }
loc(){ "${CURL[@]}" -o /dev/null -w '%{redirect_url}' "$@"; }
get(){ "${CURL[@]}" "$@"; }
JAR=(-c /tmp/auditjar.txt -b /tmp/auditjar.txt)
SLUG=probandoruta1
mails(){ docker exec nolantis-gestion-wp cat /tmp/nolantis-mails.log 2>/dev/null; }

echo "== Preparación"
docker exec nolantis-gestion-wp rm -f /tmp/nolantis-mails.log /tmp/nolantis-phpmailer.log /tmp/auditjar.txt
docker exec nolantis-gestion-wp sh -c 'touch /tmp/nolantis-mails.log /tmp/nolantis-phpmailer.log && chmod 666 /tmp/nolantis-mails.log /tmp/nolantis-phpmailer.log' 
helper reset >/dev/null
UP="Aud-$(openssl rand -hex 8)"; AP="Adm-$(openssl rand -hex 8)"
WPHP "
wp_insert_user( array( 'user_login' => 'nolantis_audit', 'user_pass' => '$UP', 'user_email' => 'audit@example.invalid', 'role' => 'editor' ) );
wp_insert_user( array( 'user_login' => 'nolantis_audit_admin', 'user_pass' => '$AP', 'user_email' => 'auditadmin@example.invalid', 'role' => 'administrator' ) );
\$p = wp_insert_post( array( 'post_title' => 'Auditoria post', 'post_name' => 'auditoria-post', 'post_status' => 'publish', 'post_type' => 'post', 'comment_status' => 'open', 'post_content' => 'Contenido del post' ) );
\$g = wp_insert_post( array( 'post_title' => 'Auditoria protegida', 'post_name' => 'auditoria-protegida', 'post_status' => 'publish', 'post_type' => 'page', 'post_password' => 'clave123', 'comment_status' => 'open', 'post_content' => 'CONTENIDO-SECRETO-DE-LA-PAGINA' ) );
update_option( 'nolantis_test_ids', array( \$p, \$g ) );
echo 'ok';" >/dev/null
echo "   ajustes de ruta oculta del usuario: $(WPHP 'echo wp_json_encode( nolantis_get_admin_access_settings() );')"

echo "== A. Rutas y fugas (Apache con regla en .htaccess)"
has  "/$SLUG/ muestra el formulario" "$(get http://localhost/$SLUG/)" 'id="loginform"'
check "/wp-login.php => 404" "$(code http://localhost/wp-login.php)" 404
check "/wp-login.php?action=lostpassword => 404" "$(code 'http://localhost/wp-login.php?action=lostpassword')" 404
check "/wp-admin/ => 404" "$(code http://localhost/wp-admin/)" 404
check "/wp-admin/options.php => 404" "$(code http://localhost/wp-admin/options.php)" 404
check "/wp-admin (sin barra) => redirige a /wp-admin/ (que da 404)" "$(loc http://localhost/wp-admin)" "http://localhost:8090/wp-admin/"
for p in login admin dashboard wp-signup.php wp-activate.php; do hasnt "/$p no revela la ruta" "$(loc http://localhost/$p) $(get -L http://localhost/$p)" "$SLUG"; done
for p in "" auditoria-post/ wp-json/ feed/ robots.txt "?p=1" "wp-json/wp/v2/posts"; do hasnt "/$p no contiene la ruta secreta" "$(get "http://localhost/$p")" "$SLUG"; done
not404 "admin-ajax.php sin sesión sigue funcionando" "$(code -d action=heartbeat http://localhost/wp-admin/admin-ajax.php)"
not404 "admin-post.php sin sesión sigue funcionando" "$(code http://localhost/wp-admin/admin-post.php)"
check "wp-cron.php funciona" "$(code http://localhost/wp-cron.php)" 200
check "REST API funciona" "$(code http://localhost/wp-json/wp/v2/posts)" 200

echo "== B. Ciclo completo con la ruta oculta: entrar, salir y restablecer contraseña"
r=$("${CURL[@]}" -i "${JAR[@]}" --data-urlencode "log=nolantis_audit" --data-urlencode "pwd=$UP" http://localhost/$SLUG/)
has "login por la ruta => sesión" "$r" "wordpress_logged_in"
has "  ...redirige al escritorio" "$(grep -i '^Location' <<<"$r")" "/wp-admin/"
has "/wp-admin/ con sesión => carga" "$(get "${JAR[@]}" http://localhost/wp-admin/)" "wpadminbar"
LOGOUT=$(get "${JAR[@]}" http://localhost/wp-admin/ | grep -oP "href=['\"]\K[^'\"]*action=logout[^'\"]*" | head -1 | sed 's/&amp;/\&/g; s/&#038;/\&/g')
has "enlace de cerrar sesión usa la ruta" "$LOGOUT" "/$SLUG/"
hasnt "  ...y no wp-login.php" "$LOGOUT" "wp-login.php"
r=$(loc "${JAR[@]}" "${LOGOUT/localhost:8090/localhost}")
has "cerrar sesión => vuelve a la ruta con loggedout" "$r" "/$SLUG/?loggedout=true"
check "tras cerrar sesión /wp-admin/ => 404" "$(code "${JAR[@]}" http://localhost/wp-admin/)" 404
r=$(loc --data-urlencode "user_login=nolantis_audit" -d 'wp-submit=x' "http://localhost/$SLUG/?action=lostpassword")
has "pedir contraseña nueva => redirige a la ruta (no a un 404)" "$r" "/$SLUG/?checkemail=confirm"
has "  ...y esa página carga" "$(get "${r/localhost:8090/localhost}")" "login"
MAIL=$(mails | grep -i "restablec\|reset\|contrase" | tail -1)
has "el email de restablecimiento lleva la ruta" "$(grep -oP "http://localhost:8090/$SLUG/\?login=\S+" <<<"$MAIL")" "action=rp"
hasnt "  ...y no wp-login.php" "$MAIL" "wp-login.php"
RP=$(grep -oP "http://localhost:8090/$SLUG/\?login=\S+" <<<"$MAIL" | head -1)
docker exec nolantis-gestion-wp rm -f /tmp/rpjar.txt
RJ=(-c /tmp/rpjar.txt -b /tmp/rpjar.txt)
r=$(loc "${RJ[@]}" "${RP/localhost:8090/localhost}")
has "enlace del email => abre el formulario de nueva contraseña" "$r" "/$SLUG/?action=rp"
page=$(get "${RJ[@]}" "${r/localhost:8090/localhost}")
has "  ...formulario visible" "$page" 'id="resetpassform"'
RPKEY=$(grep -oP 'name="rp_key" value="\K[^"]+' <<<"$page")
NEWP="Nueva-$(openssl rand -hex 8)"
r=$(get "${RJ[@]}" --data-urlencode "pass1=$NEWP" --data-urlencode "pass2=$NEWP" --data-urlencode "rp_key=$RPKEY" -d 'wp-submit=x' "http://localhost/$SLUG/?action=resetpass")
has "guardar la nueva contraseña => confirmación" "$r" "restablecid"
r=$("${CURL[@]}" -i --data-urlencode "log=nolantis_audit" --data-urlencode "pwd=$NEWP" http://localhost/$SLUG/)
has "entrar con la contraseña nueva" "$r" "wordpress_logged_in"
U=$(WPHP "echo wp_login_url( 'http://localhost:8090/wp-admin/edit.php?post_type=page&x=1' );")
check "redirect_to con & bien codificado" "$U" "http://localhost:8090/$SLUG/?redirect_to=http%3A%2F%2Flocalhost%3A8090%2Fwp-admin%2Fedit.php%3Fpost_type%3Dpage%26x%3D1"

echo "== C. Página protegida con contraseña (visitante)"
page=$(get http://localhost/auditoria-protegida/)
ACTION=$(grep -oP 'action="\K[^"]*postpass[^"]*' <<<"$page" | head -1 | sed 's/&#038;/\&/g; s/&amp;/\&/g')
has "el formulario apunta a wp-login.php?action=postpass" "$ACTION" "wp-login.php?action=postpass"
hasnt "la página no revela la ruta secreta" "$page" "$SLUG"
docker exec nolantis-gestion-wp rm -f /tmp/ppjar.txt
PJ=(-c /tmp/ppjar.txt -b /tmp/ppjar.txt)
r=$("${CURL[@]}" -i "${PJ[@]}" -H 'Referer: http://localhost:8090/auditoria-protegida/' -d 'post_password=clave123&Submit=Entrar' "${ACTION/localhost:8090/localhost}")
has "enviar la contraseña de la página => cookie de acceso" "$r" "wp-postpass_"
has "  ...y se ve el contenido" "$(get "${PJ[@]}" http://localhost/auditoria-protegida/)" "CONTENIDO-SECRETO-DE-LA-PAGINA"

echo "== D. Respaldo PHP (como en Nginx: sin regla propia en .htaccess)"
WPHP 'nolantis_admin_access_write_htaccess( array() );' >/dev/null
check "regla quitada" "$(docker exec nolantis-gestion-wp grep -c "RewriteRule ^$SLUG" /var/www/html/.htaccess)" 0
has "/$SLUG/ sigue mostrando el formulario" "$(get http://localhost/$SLUG/)" 'id="loginform"'
has "/$SLUG/?action=lostpassword también" "$(get "http://localhost/$SLUG/?action=lostpassword")" 'id="lostpasswordform"'
r=$("${CURL[@]}" -i --data-urlencode "log=nolantis_audit" --data-urlencode "pwd=$NEWP" http://localhost/$SLUG/)
has "login por el respaldo => sesión" "$r" "wordpress_logged_in"
check "/wp-login.php sigue en 404" "$(code http://localhost/wp-login.php)" 404
WPHP 'nolantis_sync_admin_access_htaccess();' >/dev/null
check "regla restaurada" "$(docker exec nolantis-gestion-wp grep -c "RewriteRule ^$SLUG" /var/www/html/.htaccess)" 1
HT=$(docker exec nolantis-gestion-wp cat /var/www/html/.htaccess)
if (( $(grep -n "^# BEGIN Nolantis" <<<"$HT" | cut -d: -f1) < $(grep -n "^# BEGIN WordPress" <<<"$HT" | cut -d: -f1) )); then ok "nuestra regla va antes que la de WordPress"; else ko "orden .htaccess" "$HT"; fi

echo "== E. Guardar ajustes desde el panel (como en el navegador)"
docker exec nolantis-gestion-wp rm -f /tmp/auditjar.txt
"${CURL[@]}" -o /dev/null "${JAR[@]}" --data-urlencode "log=nolantis_audit_admin" --data-urlencode "pwd=$AP" http://localhost/$SLUG/
save(){ # save enabled slug
  local h n; h=$(get "${JAR[@]}" 'http://localhost/wp-admin/admin.php?page=nolantis-admin-access')
  n=$(grep -oP 'name="_wpnonce" value="\K[^"]+' <<<"$h" | head -1)
  "${CURL[@]}" -o /dev/null "${JAR[@]}" -d "option_page=nolantis_admin_access_group&action=update&_wpnonce=$n" -d "nolantis_admin_access_settings[enabled]=$1" --data-urlencode "nolantis_admin_access_settings[slug]=$2" http://localhost/wp-admin/options.php
}
settings(){ WPHP 'echo wp_json_encode( get_option( NOLANTIS_ADMIN_ACCESS_OPTION ) );'; }
notice(){ get "${JAR[@]}" 'http://localhost/wp-admin/admin.php?page=nolantis-admin-access'; }
WPHP 'delete_option( NOLANTIS_ADMIN_ACCESS_OPTION ); nolantis_sync_admin_access_htaccess();' >/dev/null
check "sin la opción, wp-login.php vuelve a estar accesible" "$(code http://localhost/wp-login.php)" 200
save 1 acceso-auditoria
check "primera vez que se guarda (opción nueva): se activa" "$(settings)" '{"enabled":1,"slug":"acceso-auditoria"}'
check "  ...y escribe la regla en .htaccess" "$(docker exec nolantis-gestion-wp grep -cF 'RewriteRule ^acceso\-auditoria/?$' /var/www/html/.htaccess)" 1
has "  ...aviso de ruta comprobada" "$(notice)" "Ruta comprobada"
has "/acceso-auditoria/ funciona" "$(get http://localhost/acceso-auditoria/)" 'id="loginform"'
check "/wp-login.php => 404" "$(code http://localhost/wp-login.php)" 404
WPHP "update_option( 'nolantis_test_loopback_404', 1 );" >/dev/null
save 1 ruta-rota
check "si la ruta no responde => NO se activa" "$(settings)" '{"enabled":0,"slug":"ruta-rota"}'
has "  ...aviso de error" "$(notice)" "NO se ha activado"
check "  ...y wp-login.php sigue accesible" "$(code http://localhost/wp-login.php)" 200
WPHP "delete_option( 'nolantis_test_loopback_404' ); update_option( 'nolantis_test_loopback_error', 1 );" >/dev/null
save 1 ruta-sin-comprobar
check "si no se puede comprobar => se activa" "$(settings)" '{"enabled":1,"slug":"ruta-sin-comprobar"}'
has "  ...con aviso para probarla en incógnito" "$(notice)" "incógnito"
WPHP "delete_option( 'nolantis_test_loopback_error' );" >/dev/null
save 1 login
check "slug reservado 'login' => rechazado" "$(settings)" '{"enabled":0,"slug":""}'
save 1 auditoria-protegida
check "slug de una página existente => rechazado" "$(settings)" '{"enabled":0,"slug":""}'

echo "== F. Constante de emergencia"
save 1 $SLUG
check "ruta del usuario restaurada" "$(settings)" "{\"enabled\":1,\"slug\":\"$SLUG\"}"
WPHP "update_option( 'nolantis_test_emergency_admin_access', 1 );" >/dev/null
has "con NOLANTIS_ADMIN_ACCESS_DISABLE, wp-login.php vuelve" "$(get http://localhost/wp-login.php)" 'id="loginform"'
has "  ...y el panel lo avisa" "$(notice)" "desactivada de emergencia"
WPHP "delete_option( 'nolantis_test_emergency_admin_access' );" >/dev/null
check "sin la constante, wp-login.php => 404" "$(code http://localhost/wp-login.php)" 404

echo "== G. Desactivar y reactivar el plugin"
WPHP "deactivate_plugins( 'nolantis-gestion/nolantis-gestion-plugin.php' );" >/dev/null
check "desactivado: regla quitada" "$(docker exec nolantis-gestion-wp grep -c "RewriteRule ^$SLUG" /var/www/html/.htaccess)" 0
check "desactivado: wp-login.php accesible" "$(code http://localhost/wp-login.php)" 200
WPHP "activate_plugin( 'nolantis-gestion/nolantis-gestion-plugin.php' );" >/dev/null
check "reactivado: regla de nuevo" "$(docker exec nolantis-gestion-wp grep -c "RewriteRule ^$SLUG" /var/www/html/.htaccess)" 1
check "reactivado: wp-login.php => 404" "$(code http://localhost/wp-login.php)" 404
has "reactivado: la ruta funciona" "$(get http://localhost/$SLUG/)" 'id="loginform"'

echo "== H. Personalización del login (nombre con tildes y caracteres peligrosos)"
WPHP "update_option( 'nolantis_test_blogname', 'Peluquería Ñandú & \"Cía\" </style><script>alert(1)</script>' );" >/dev/null
page=$(get http://localhost/$SLUG/)
has "el nombre con tildes se escribe tal cual en el CSS" "$page" 'content: "Peluquería Ñandú & \22 Cía\22  \3c /style\3e'
check "sin secuencias \\u00XX (tildes rotas)" "$(grep -cF "$(printf '%s' '\\u00')" <<<"$page")" 0
hasnt "no se puede inyectar código" "$page" '<script>alert(1)'
WPHP "delete_option( 'nolantis_test_blogname' );" >/dev/null

echo "== I. SMTP"
OUT=$(WPHP "
\$old_s = get_option( NOLANTIS_SMTP_OPTION ); \$old_p = get_option( NOLANTIS_SMTP_PASSWORD_OPTION );
\$s = nolantis_sanitize_smtp_settings( array( 'enabled' => 1, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => 1, 'username' => 'envios@example.com', 'password' => ' clave con espacios ', 'from_email' => 'no-reply@example.com', 'force_from_email' => 1, 'from_name' => 'Web Prueba' ) );
update_option( NOLANTIS_SMTP_OPTION, \$s, false );
echo 'GUARDADA=', ( 0 === strpos( get_option( NOLANTIS_SMTP_PASSWORD_OPTION ), NOLANTIS_SMTP_PASSWORD_PREFIX ) ? 'cifrada' : 'EN CLARO' ), '|';
echo 'LEIDA=[', nolantis_get_smtp_password(), ']|';
echo 'EN_OPCION=', ( false === strpos( wp_json_encode( get_option( NOLANTIS_SMTP_OPTION ) ), 'clave' ) ? 'no' : 'SI' ), '|';
update_option( 'nolantis_test_mail_passthrough', 1 );
wp_mail( 'destino@example.com', 'Prueba', 'Hola' );
delete_option( 'nolantis_test_mail_passthrough' );
update_option( NOLANTIS_SMTP_OPTION, \$old_s, false ); update_option( NOLANTIS_SMTP_PASSWORD_OPTION, \$old_p, false );
")
has "contraseña guardada cifrada" "$OUT" "GUARDADA=cifrada"
has "contraseña con espacios se conserva exacta" "$OUT" "LEIDA=[ clave con espacios ]"
has "la contraseña no está en la opción de ajustes" "$OUT" "EN_OPCION=no"
PM=$(docker exec nolantis-gestion-wp tail -1 /tmp/nolantis-phpmailer.log)
has "PHPMailer usa SMTP" "$PM" '"Mailer":"smtp"'
has "  ...servidor" "$PM" '"Host":"smtp.example.com"'
has "  ...puerto y cifrado" "$PM" '"Port":587'
has "  ...TLS" "$PM" '"SMTPSecure":"tls"'
has "  ...usuario" "$PM" '"Username":"envios@example.com"'
has "  ...contraseña descifrada" "$PM" '"Password":" clave con espacios "'
has "  ...remitente forzado" "$PM" '"From":"no-reply@example.com"'
has "  ...remitente del sobre" "$PM" '"Sender":"no-reply@example.com"'
has "  ...nombre" "$PM" '"FromName":"Web Prueba"'

echo "== J. Ajustes generales: cerrar comentarios"
OUT=$(WPHP "
list( \$post, \$page ) = get_option( 'nolantis_test_ids' );
\$old = get_option( NOLANTIS_GENERAL_OPTION ); update_option( 'nolantis_test_general_backup', false === \$old ? '__none__' : \$old );
update_option( NOLANTIS_GENERAL_OPTION, nolantis_sanitize_general_settings( array( 'disable_post_comments' => 1 ) ) );
clean_post_cache( \$post );
echo 'BD=', get_post_field( 'comment_status', \$post ), '|POST_ABIERTO=', ( comments_open( \$post ) ? 'si' : 'no' ), '|PAGINA_ABIERTA=', ( comments_open( \$page ) ? 'si' : 'no' ), '|ID=', \$post;
")
has "al activarlo se cierran en la base de datos" "$OUT" "BD=closed"
has "comments_open() de la entrada => no" "$OUT" "POST_ABIERTO=no"
has "las páginas no se tocan" "$OUT" "PAGINA_ABIERTA=si"
PID=$(grep -oP 'ID=\K[0-9]+' <<<"$OUT")
check "enviar un comentario igualmente => rechazado" "$(code -d "comment_post_ID=$PID&comment=hola&author=a&email=a@example.com" http://localhost/wp-comments-post.php)" 403
WPHP "\$b = get_option( 'nolantis_test_general_backup' ); if ( '__none__' === \$b ) { delete_option( NOLANTIS_GENERAL_OPTION ); } else { update_option( NOLANTIS_GENERAL_OPTION, \$b ); } delete_option( 'nolantis_test_general_backup' );" >/dev/null

echo "== K. Actualizador (consulta real a GitHub)"
OUT=$(WPHP "
\$c = nolantis_get_update_checker();
\$u = \$c ? \$c->requestUpdate() : null;
echo 'CHECKER=', ( \$c ? 'si' : 'no' ), '|REMOTA=', ( \$u ? \$u->version : '-' ), '|ZIP=', ( \$u ? \$u->download_url : '-' ), '|ERRORES=', count( \$c ? \$c->getLastRequestApiErrors() : array( 1 ) );
")
has "el comprobador se inicializa" "$OUT" "CHECKER=si"
has "lee la última release de GitHub" "$OUT" "REMOTA=1.0.17"
has "  ...con el ZIP adjunto" "$OUT" "nolantis-gestion-1.0.17.zip"
has "  ...sin errores" "$OUT" "ERRORES=0"

echo "== Limpieza"
WPHP "
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( (array) get_option( 'nolantis_test_ids' ) as \$id ) { wp_delete_post( \$id, true ); }
foreach ( array( 'nolantis_audit', 'nolantis_audit_admin' ) as \$l ) { \$u = get_user_by( 'login', \$l ); if ( \$u ) { wp_delete_user( \$u->ID ); } }
delete_option( 'nolantis_test_ids' );" >/dev/null
docker exec nolantis-gestion-wp rm -f /tmp/auditjar.txt /tmp/rpjar.txt /tmp/ppjar.txt
helper reset >/dev/null
echo "   ruta oculta del usuario: $(settings)"
echo; echo "RESULTADO AUDITORÍA: $PASSES OK, $FAILS FALLOS"
