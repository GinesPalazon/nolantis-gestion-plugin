#!/usr/bin/env bash
# Pruebas del modulo SMTP (Parte 5) y de los comentarios, contra el WordPress local y el
# servidor SMTP de pruebas (fake-smtp.php) en 127.0.0.1:2525 (sin TLS) y :2526 (STARTTLS autofirmado).
SP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SP/ll-lib.sh"
PASSES=0; FAILS=0
ok()   { PASSES=$((PASSES+1)); echo "  OK    $1"; }
ko()   { FAILS=$((FAILS+1));  echo "  FALLO $1 :: $2"; }
check(){ if [[ "$2" == "$3" ]]; then ok "$1"; else ko "$1" "esperado [$3] obtenido [$2]"; fi; }
has()  { if [[ "$2" == *"$3"* ]]; then ok "$1"; else ko "$1" "no contiene [$3] en [${2:0:260}]"; fi; }
hasnt(){ if [[ "$2" != *"$3"* ]]; then ok "$1"; else ko "$1" "contiene [$3]"; fi; }
WPHP(){ docker exec -i nolantis-gestion-wp php /dev/stdin <<<"<?php \$_SERVER['HTTP_HOST']='localhost:8090'; require '/var/www/html/wp-load.php'; require_once ABSPATH.'wp-admin/includes/admin.php'; $1"; }
CURL=(docker exec nolantis-gestion-wp curl -s -H Host:localhost:8090 -c /tmp/smtpjar.txt -b /tmp/smtpjar.txt)
received(){ docker exec nolantis-gestion-wp sh -c 'tail -1 /tmp/fake-smtp-received.log 2>/dev/null'; }
GOOD='Clave Correcta 1'
# Configura SMTP: host puerto cifrado contrasena [log_mode] [reply_to]
cfg(){ WPHP "
update_option( NOLANTIS_SMTP_OPTION, array( 'enabled' => 1, 'host' => '$1', 'port' => $2, 'encryption' => '$3', 'auth' => 1, 'username' => 'envios@example.com', 'from_email' => 'no-reply@example.com', 'force_from_email' => 1, 'from_name' => 'Web Prueba', 'reply_to_original' => ${6:-1}, 'log_mode' => '${5:-all}', 'log_retention_days' => 30 ), false );
nolantis_update_smtp_password( '$4' );" >/dev/null; }
# Envia un correo y devuelve: RESULTADO|ULTIMO_LOG(status)|error|pista|debug
send(){ WPHP "
global \$wpdb; \$before = (int) \$wpdb->get_var( 'SELECT MAX(id) FROM ' . nolantis_mail_log_table() );
\$t = microtime( true );
\$r = wp_mail( 'cliente@example.net', 'Asunto de prueba', 'CUERPO-SECRETO-DEL-MENSAJE', array( ${1:-} ) );
\$secs = round( microtime( true ) - \$t );
\$row = \$wpdb->get_row( \$wpdb->prepare( 'SELECT * FROM ' . nolantis_mail_log_table() . ' WHERE id > %d ORDER BY id DESC LIMIT 1', \$before ) );
echo ( \$r ? 'ENVIADO' : 'FALLIDO' ), '|', ( \$row ? \$row->status . '/' . \$row->mailer : 'SIN_LOG' ), '|', ( \$row ? \$row->error : '' ), '|', ( \$row ? nolantis_smtp_explain_error( \$row->error, \$row->debug ) : '' ), '|SEGUNDOS=', \$secs, '|DEBUG=', ( \$row ? str_replace( \"\\n\", ' / ', \$row->debug ) : '' );"; }

echo "== Preparación"
WPHP "update_option( 'nolantis_test_smtp_backup', array( get_option( NOLANTIS_SMTP_OPTION ), get_option( NOLANTIS_SMTP_PASSWORD_OPTION ), get_option( 'nolantis_smtp_wizard_status' ), get_option( NOLANTIS_GENERAL_OPTION ) ), false ); echo 'copia de seguridad de tus ajustes SMTP guardada';"; echo
docker exec nolantis-gestion-wp sh -c ': > /tmp/fake-smtp-received.log; chmod 666 /tmp/fake-smtp-received.log'

echo "== 1. Migración desde la 1.0.17 (con tus datos reales)"
OUT=$(WPHP "
\$stored = get_option( NOLANTIS_SMTP_PASSWORD_OPTION );
\$v1 = nolantis_decrypt_smtp_password( \$stored );
nolantis_smtp_migrate_legacy_data();
\$new = get_option( NOLANTIS_SMTP_PASSWORD_OPTION );
echo 'ANTES=', substr( \$stored, 0, 16 ), '|DESPUES=', substr( \$new, 0, 16 ), '|MISMA=', ( \$v1 === nolantis_get_smtp_password() ? 'si' : 'NO' ), '|LONGITUD=', strlen( (string) \$v1 ), '|ASISTENTE=', var_export( get_option( 'nolantis_smtp_wizard_status' ), true );")
has "la contraseña antigua (v1) se pasa al cifrado nuevo (v2)" "$OUT" "DESPUES=nolantis_enc_v2:"
has "  ...y sigue siendo la misma contraseña" "$OUT" "MISMA=si"
has "la opción del asistente antiguo se borra" "$OUT" "ASISTENTE=false"

echo "== 2. Guardar ajustes (validación)"
OUT=$(WPHP "
\$s = nolantis_sanitize_smtp_settings( array( 'enabled' => 1, 'host' => ' ssl://SMTP.Example.COM/ ', 'port' => 465, 'encryption' => 'tls', 'auth' => 1, 'username' => '', 'password' => '  con espacios  ', 'from_email' => 'esto-no-es-email', 'force_from_email' => 1, 'log_mode' => 'raro', 'log_retention_days' => 9999 ) );
echo 'HOST=', \$s['host'], '|LOG=', \$s['log_mode'], '|RET=', \$s['log_retention_days'], '|PASS=[', nolantis_get_smtp_password(), ']|AVISOS=', implode( ' ## ', wp_list_pluck( get_settings_errors( NOLANTIS_SMTP_OPTION ), 'message' ) );")
has "limpia 'ssl://SMTP.Example.COM/'" "$OUT" "HOST=smtp.example.com|"
has "modo de registro inválido => 'all'" "$OUT" "LOG=all"
has "conservar 9999 días => 365" "$OUT" "RET=365"
has "contraseña con espacios intacta" "$OUT" "PASS=[  con espacios  ]"
has "aviso: 465 con TLS" "$OUT" "465 normalmente usa cifrado SSL"
has "aviso: falta usuario" "$OUT" "no has indicado el usuario"
has "error: remitente no válido" "$OUT" "remitente no es válido"
OUT=$(WPHP "nolantis_sanitize_smtp_settings( array( 'host' => 'x', 'clear_password' => 1 ) ); echo 'ESTADO=', nolantis_get_smtp_password_status();")
has "'Borrar la contraseña guardada' funciona" "$OUT" "ESTADO=empty"

echo "== 3. Envío real por SMTP"
cfg 127.0.0.1 2525 none "$GOOD"
r=$(send "'From: Visitante <visitante@example.org>'")
has "se envía" "$r" "ENVIADO|sent/smtp"
M=$(received)
has "remitente forzado" "$M" 'From: Web Prueba <no-reply@example.com>'
has "remitente del sobre (MAIL FROM) igual" "$M" 'MAIL FROM:<no-reply@example.com>'
has "el email del visitante queda como Responder a" "$M" 'Reply-To: Visitante <visitante@example.org>'
r=$(send); M=$(received)
hasnt "sin remitente propio => sin Responder a" "$M" 'Reply-To'
cfg 127.0.0.1 2525 none "$GOOD" all 0
send "'From: Visitante <visitante@example.org>'" >/dev/null; M=$(received)
hasnt "opción 'Responder a' desmarcada => no se añade" "$M" 'Reply-To'
cfg 127.0.0.1 2526 none "$GOOD"
r=$(send); has "'Sin cifrado' con un servidor que ofrece STARTTLS => envía sin intentar TLS" "$r" "ENVIADO|sent/smtp"

echo "== 4. Fallos y explicación"
cfg 127.0.0.1 2525 none 'Clave Mala'
r=$(send)
has "contraseña incorrecta => falla" "$r" "FALLIDO|failed/smtp"
has "  ...error de PHPMailer registrado (en el idioma de la web)" "$r" "failed/smtp|Error SMTP: no se pudo identificar"
has "  ...explicación en español" "$r" "rechaza el usuario o la contraseña"
has "  ...respuesta del servidor (535)" "$r" "SERVER -> CLIENT: 535"
hasnt "  ...el registro no contiene la contraseña" "$r" "Clave Mala"
hasnt "  ...ni la contraseña en base64" "$r" "$(printf 'Clave Mala' | base64)"
hasnt "  ...ni el contenido del mensaje" "$r" "CUERPO-SECRETO"
OUT=$(WPHP "echo '[', nolantis_smtp_explain_error( '', 'SERVER -> CLIENT: 250 SIZE 42100000' ), ']';")
check "'SIZE 42100000' no se confunde con un error 421" "$OUT" "[]"
cfg 127.0.0.1 2599 none "$GOOD"; r=$(send)
has "puerto cerrado => 'rechaza la conexión'" "$r" "rechaza la conexión"
cfg smtp.no-existe.invalid 587 tls "$GOOD"; r=$(send)
has "servidor inexistente => 'no existe'" "$r" "nombre del servidor SMTP no existe"
cfg 10.255.255.1 587 tls "$GOOD"; r=$(send)
has "servidor que no responde => 'no responde'" "$r" "El servidor SMTP no responde"
S=$(grep -oP 'SEGUNDOS=\K[0-9]+' <<<"$r"); if (( S <= 20 )); then ok "  ...y se rinde en $S s (antes podían ser 300)"; else ko "tiempo de espera" "$S s"; fi
cfg 127.0.0.1 2526 tls "$GOOD"; r=$(send)
has "certificado no válido => explicación SSL/TLS" "$r" "certificado SSL/TLS"

echo "== 4b. Registro exacto"
cfg 127.0.0.1 2525 none "$GOOD"
OUT=$(WPHP "
global \$wpdb; \$t = nolantis_mail_log_table();
wp_mail( 'Cliente Uno <cliente@example.net>', 'Primero', 'hola' );
\$ok = \$wpdb->get_row( \"SELECT * FROM \$t ORDER BY id DESC LIMIT 1\" );
wp_mail( 'esto no es un email', 'Segundo', 'hola' );
\$bad = \$wpdb->get_row( \"SELECT * FROM \$t ORDER BY id DESC LIMIT 1\" );
echo 'DEST=', \$ok->recipients, '|ESTADO2=', \$bad->status, '|DEBUG2=[', \$bad->debug, ']|PISTA2=', nolantis_smtp_explain_error( \$bad->error, \$bad->debug );")
has "destinatario 'Nombre <email>' se guarda completo" "$OUT" "DEST=Cliente Uno <cliente@example.net>"
has "dirección no válida => fallo registrado" "$OUT" "ESTADO2=failed"
has "  ...sin la conversación del correo anterior" "$OUT" "DEBUG2=[]"
has "  ...con explicación" "$OUT" "no es válida"
cfg 127.0.0.1 2525 none 'Clave Mala'; r=$(send)
has "MAIL FROM con la dirección completa en la conversación" "$(WPHP 'global $wpdb; echo $wpdb->get_var( "SELECT debug FROM " . nolantis_mail_log_table() . " WHERE status=\"failed\" ORDER BY id DESC LIMIT 1" );' | tr '\n' ' ')" "250-AUTH LOGIN"

echo "== 5. Modos del registro"
cfg 127.0.0.1 2525 none "$GOOD" errors; r=$(send)
has "'solo fallos': un envío correcto no se registra" "$r" "ENVIADO|SIN_LOG"
cfg 127.0.0.1 2525 none 'Clave Mala' errors; r=$(send)
has "'solo fallos': un fallo sí" "$r" "FALLIDO|failed"
cfg 127.0.0.1 2525 none 'Clave Mala' off; r=$(send)
has "'nada': no se registra ni el fallo" "$r" "FALLIDO|SIN_LOG"
OUT=$(WPHP "global \$wpdb; \$t = nolantis_mail_log_table(); \$wpdb->insert( \$t, array( 'created_at' => time() - 40 * DAY_IN_SECONDS, 'status' => 'sent', 'recipients' => 'viejo@example.com' ) ); nolantis_mail_log_purge(); echo 'VIEJOS=', \$wpdb->get_var( \"SELECT COUNT(*) FROM \$t WHERE recipients = 'viejo@example.com'\" );")
has "los registros de más de 30 días se borran" "$OUT" "VIEJOS=0"

echo "== 6. Contraseña ilegible (claves de wp-config.php cambiadas)"
OUT=$(WPHP "\$s = get_option( NOLANTIS_SMTP_PASSWORD_OPTION ); update_option( NOLANTIS_SMTP_PASSWORD_OPTION, substr( \$s, 0, -6 ) . 'AAAAAA', false ); echo 'ESTADO=', nolantis_get_smtp_password_status(), '|PASS=[', nolantis_get_smtp_password(), ']';")
has "se detecta como ilegible" "$OUT" "ESTADO=unreadable"
has "  ...y no se usa basura como contraseña" "$OUT" "PASS=[]"

echo "== 7. Panel (como administrador en el navegador)"
AP="Adm-$(openssl rand -hex 8)"
WPHP "wp_insert_user( array( 'user_login' => 'nolantis_smtp_admin', 'user_pass' => '$AP', 'user_email' => 'smtpadmin@example.invalid', 'role' => 'administrator' ) );" >/dev/null
docker exec nolantis-gestion-wp rm -f /tmp/smtpjar.txt
"${CURL[@]}" -o /dev/null --data-urlencode "log=nolantis_smtp_admin" --data-urlencode "pwd=$AP" http://localhost/probandoruta1/
pg(){ "${CURL[@]}" "http://localhost/wp-admin/$1"; }
post_action(){ local h n; h=$(pg "admin.php?page=nolantis-smtp&tab=$1"); n=$(tr '\n' ' ' <<<"$h" | grep -oP "name=\"_wpnonce\" value=\"\K[^\"]+(?=\"[^<]*(<input type=\"hidden\" name=\"_wp_http_referer\"[^>]*>\s*)?<input type=\"hidden\" name=\"action\" value=\"$2\")" | head -1); "${CURL[@]}" -o /dev/null -d "action=$2&_wpnonce=$n" "${@:3}" http://localhost/wp-admin/admin-post.php; }
has "aviso de contraseña ilegible en el panel" "$(pg 'admin.php?page=nolantis-smtp')" "La contraseña SMTP guardada no se puede leer"
cfg 127.0.0.1 2525 none "$GOOD"
h=$(pg 'admin.php?page=nolantis-smtp')
has "pestaña Ajustes: proveedores" "$h" 'data-host="smtp.gmail.com"'
hasnt "  ...sin el asistente antiguo" "$h" "Usar ajustes Nolantis"
hasnt "  ...la contraseña no aparece en la página" "$h" "$GOOD"
post_action probar nolantis_send_test_email --data-urlencode "test_recipient=destino@example.com"
h=$(pg 'admin.php?page=nolantis-smtp&tab=probar'); has "correo de prueba correcto" "$h" "Correo de prueba enviado a destino@example.com"
post_action probar nolantis_smtp_test_connection
h=$(pg 'admin.php?page=nolantis-smtp&tab=probar'); has "probar conexión correcta" "$h" "usuario y la contraseña son válidos"
cfg 127.0.0.1 2525 none 'Clave Mala'
post_action probar nolantis_send_test_email --data-urlencode "test_recipient=destino@example.com"
h=$(pg 'admin.php?page=nolantis-smtp&tab=probar')
has "correo de prueba fallido => motivo" "$h" "Causa probable:"
has "  ...con la conversación del servidor" "$h" "SERVER -&gt; CLIENT: 535"
hasnt "  ...sin la contraseña" "$h" "Clave Mala"
post_action probar nolantis_smtp_test_connection
h=$(pg 'admin.php?page=nolantis-smtp&tab=probar'); has "probar conexión fallida => motivo" "$h" "rechaza el usuario o la contraseña"
WPHP "update_option( NOLANTIS_SMTP_PASSWORD_OPTION, '', false );" >/dev/null
post_action probar nolantis_smtp_test_connection
h=$(pg 'admin.php?page=nolantis-smtp&tab=probar'); has "probar conexión sin contraseña => mensaje claro" "$h" "no hay ninguna contraseña guardada"
cfg 127.0.0.1 2525 none "$GOOD"
WPHP "wp_mail( 'x@example.com', '<script>alert(1)</script>Asunto', 'hola' );" >/dev/null
h=$(pg 'admin.php?page=nolantis-smtp&tab=registro')
has "registro: se ve el fallo con '¿Por qué ha fallado?'" "$h" "¿Por qué ha fallado?"
hasnt "registro: asunto con código no se ejecuta" "$h" "<script>alert(1)"
has "aviso en el escritorio por correos fallidos" "$(pg 'index.php')" "no se han podido enviar en las últimas 24 horas"
post_action registro nolantis_clear_mail_log
check "vaciar registro" "$(WPHP 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM " . nolantis_mail_log_table() );')" 0
h=$(pg 'admin.php?page=nolantis-smtp&tab=ajustes'); N=$(grep -oP 'name="_wpnonce" value="\K[^"]+' <<<"$h" | head -1)
"${CURL[@]}" -o /dev/null -d "option_page=nolantis_settings_group&action=update&_wpnonce=$N" -d "nolantis_smtp_settings[enabled]=1&nolantis_smtp_settings[host]=127.0.0.1&nolantis_smtp_settings[port]=2525&nolantis_smtp_settings[encryption]=none&nolantis_smtp_settings[auth]=1&nolantis_smtp_settings[username]=envios@example.com&nolantis_smtp_settings[password]=&nolantis_smtp_settings[from_email]=no-reply@example.com&nolantis_smtp_settings[force_from_email]=1&nolantis_smtp_settings[log_mode]=all&nolantis_smtp_settings[log_retention_days]=30" http://localhost/wp-admin/options.php
check "guardar con la contraseña en blanco la mantiene" "$(WPHP 'echo nolantis_get_smtp_password();')" "$GOOD"

echo "== 8. Comentarios"
OUT=$(WPHP "
\$post = wp_insert_post( array( 'post_title' => 'C post', 'post_status' => 'publish', 'post_type' => 'post', 'comment_status' => 'open' ) );
\$page = wp_insert_post( array( 'post_title' => 'C page', 'post_status' => 'publish', 'post_type' => 'page', 'comment_status' => 'open' ) );
update_option( 'nolantis_test_comment_ids', array( \$post, \$page ) );
update_option( NOLANTIS_GENERAL_OPTION, array( 'disable_post_comments' => 0, 'disable_page_comments' => 1, 'disable_attachment_comments' => 1, 'hide_comments_ui' => 0 ) );
clean_post_cache( \$post ); clean_post_cache( \$page );
\$new = wp_insert_post( array( 'post_title' => 'C page nueva', 'post_status' => 'publish', 'post_type' => 'page', 'comment_status' => 'open' ) );
update_option( 'nolantis_test_comment_ids', array( \$post, \$page, \$new ) );
echo 'PAGINA=', get_post_field( 'comment_status', \$page ), '|ENTRADA=', get_post_field( 'comment_status', \$post ), '|NUEVA=', get_post_field( 'comment_status', \$new ), '|ABIERTA_PAG=', ( comments_open( \$page ) ? 'si' : 'no' ), '|ABIERTA_ENT=', ( comments_open( \$post ) ? 'si' : 'no' ), '|PAGE_ID=', \$page;")
has "páginas marcadas => cerradas en la base de datos" "$OUT" "PAGINA=closed"
has "entradas sin marcar => no se tocan" "$OUT" "ENTRADA=open"
has "página nueva => nace cerrada" "$OUT" "NUEVA=closed"
has "comments_open() de la página => no" "$OUT" "ABIERTA_PAG=no"
has "comments_open() de la entrada => sí" "$OUT" "ABIERTA_ENT=si"
PID=$(grep -oP 'PAGE_ID=\K[0-9]+' <<<"$OUT")
check "comentario enviado a la página => rechazado" "$("${CURL[@]}" -o /dev/null -w '%{http_code}' -d "comment_post_ID=$PID&comment=hola&author=a&email=a@example.com" http://localhost/wp-comments-post.php)" 403
WPHP "update_option( NOLANTIS_GENERAL_OPTION, array( 'disable_post_comments' => 0, 'disable_page_comments' => 0, 'disable_attachment_comments' => 0, 'hide_comments_ui' => 0 ) );" >/dev/null
check "al desmarcar no se reabren (como avisa el panel)" "$(WPHP "echo get_post_field( 'comment_status', $PID );")" "closed"
WPHP "update_option( NOLANTIS_GENERAL_OPTION, array( 'disable_post_comments' => 1, 'disable_page_comments' => 1, 'disable_attachment_comments' => 1, 'hide_comments_ui' => 1 ) );" >/dev/null
h=$(pg 'index.php')
hasnt "ocultar del panel: sin menú Comentarios" "$h" 'id="menu-comments"'
has "  ...ni en los widgets del escritorio" "$h" '#latest-comments { display: none'
hasnt "  ...sin icono en la barra superior" "$h" 'id="wp-admin-bar-comments"'
hasnt "  ...sin feed de comentarios en la web" "$("${CURL[@]}" http://localhost/)" 'comments/feed'
has "pantalla Ajustes generales carga" "$(pg 'admin.php?page=nolantis-general-settings')" "Adjuntos (páginas de imágenes"

echo "== Limpieza y restauración de tus ajustes"
WPHP "
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( (array) get_option( 'nolantis_test_comment_ids' ) as \$id ) { wp_delete_post( \$id, true ); }
\$u = get_user_by( 'login', 'nolantis_smtp_admin' ); if ( \$u ) { wp_delete_user( \$u->ID ); }
list( \$s, \$p, \$w, \$g ) = get_option( 'nolantis_test_smtp_backup' );
update_option( NOLANTIS_SMTP_OPTION, \$s, false ); update_option( NOLANTIS_SMTP_PASSWORD_OPTION, \$p, false );
if ( false !== \$w ) { update_option( 'nolantis_smtp_wizard_status', \$w, false ); }
if ( false === \$g ) { delete_option( NOLANTIS_GENERAL_OPTION ); } else { update_option( NOLANTIS_GENERAL_OPTION, \$g ); }
delete_option( 'nolantis_test_smtp_backup' ); delete_option( 'nolantis_test_comment_ids' ); nolantis_mail_log_clear();
echo 'restaurado';"; echo
docker exec nolantis-gestion-wp rm -f /tmp/smtpjar.txt
echo; echo "RESULTADO SMTP Y COMENTARIOS: $PASSES OK, $FAILS FALLOS"
