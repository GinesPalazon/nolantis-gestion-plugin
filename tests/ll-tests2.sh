#!/usr/bin/env bash
# Bateria de pruebas de la Fase 2 (proteccion por cuenta, bots, recuperacion de contrasena,
# Turnstile, avisos por email y 2FA) contra el WordPress local de Docker.
SP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SP/ll-lib.sh"
PASSES=0; FAILS=0
ok()   { PASSES=$((PASSES+1)); echo "  OK    $1"; }
ko()   { FAILS=$((FAILS+1));  echo "  FALLO $1 :: $2"; }
check(){ if [[ "$2" == "$3" ]]; then ok "$1"; else ko "$1" "esperado [$3] obtenido [$2]"; fi; }
has()  { if [[ "$2" == *"$3"* ]]; then ok "$1"; else ko "$1" "no contiene [$3] en [${2:0:200}]"; fi; }
hasnt(){ if [[ "$2" != *"$3"* ]]; then ok "$1"; else ko "$1" "contiene [$3]"; fi; }
sql()  { docker exec nolantis-gestion-db sh -c "mysql -u\"\$MYSQL_USER\" -p\"\$MYSQL_PASSWORD\" \"\$MYSQL_DATABASE\" -N -e \"$1\" 2>/dev/null"; }
from() { local ip="$1"; shift; login "$@" -H "X-Forwarded-For: $ip"; }
mails(){ $W sh -c 'cat /tmp/nolantis-mails.log 2>/dev/null' ; }
mailcount(){ mails | grep -c "$1"; }

# Captura de emails (solo dentro del contenedor, se borra al final).
$W sh -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cat > /var/www/html/wp-content/mu-plugins/zz-test-mail-capture.php' <<'PHP'
<?php
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
    file_put_contents( '/tmp/nolantis-mails.log', 'SUBJECT: ' . $atts['subject'] . ' | TO: ' . ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] ) . "\n", FILE_APPEND );
    return true;
}, 10, 2 );
PHP
$W sh -c 'rm -f /tmp/nolantis-mails.log'

PASS="T3st-$(openssl rand -hex 8)"
helper reset >/dev/null; helper deluser >/dev/null; helper mkuser "$PASS" >/dev/null

echo "== 0. Avisos por email desactivados por defecto"
check "por defecto: aviso de cuenta atacada desactivado" "$($W php -r '$_SERVER["HTTP_HOST"]="localhost:8090"; require "/var/www/html/wp-load.php"; echo (int) nolantis_ll_get_setting("alert_user_attack"), (int) nolantis_ll_get_setting("alert_long_lockout"), (int) nolantis_ll_get_setting("alert_admin_new_ip");')" "000"
for i in 1 2 3 4 5 6 7 8 9 10; do from 45.94.0.$i fantasma0 x >/dev/null; done
check "cuenta atacada con avisos por defecto => no se envía nada" "$(mails | wc -l | tr -d ' ')" 0
helper reset >/dev/null; helper deluser >/dev/null; helper mkuser "$PASS" >/dev/null
helper set alert_user_attack 1 >/dev/null

echo "== 1. Protección por cuenta: botnet (20 IPs distintas en paralelo, 1 intento cada una)"
from 203.0.113.200 nolantis_test "$PASS" >/dev/null   # acceso previo correcto: 203.0.113.200 queda como IP conocida
seq 1 20 | xargs -P 20 -I{} docker exec nolantis-gestion-wp curl -s -o /dev/null -H 'Host: localhost:8090' -H "X-Forwarded-For: 45.91.{}.1" --data 'log=nolantis_test&pwd=mala{}' http://localhost/probandoruta1/
check "ninguna IP bloqueada (1 fallo por IP)" "$(sql "SELECT COUNT(*) FROM wp_nolantis_login_lockouts WHERE kind='ip'")" 0
check "un único modo protegido para la cuenta" "$(sql "SELECT COUNT(*) FROM wp_nolantis_login_lockouts WHERE kind='user' AND username='nolantis_test'")" 1
r=$(from 45.91.99.1 nolantis_test "$PASS"); has "IP nueva con contraseña correcta => rechazada" "$r" "protegida temporalmente"
has "  ...sin sesión" "$r" "logged_in_cookie=0"
r=$(from 203.0.113.200 nolantis_test "$PASS"); has "IP conocida => entra" "$r" "logged_in_cookie=1"
r=$(login test@example.invalid "$PASS" -H "X-Forwarded-For: 45.91.99.2"); has "atacar por email cuenta para la misma cuenta" "$r" "protegida temporalmente"
helper set whitelist 192.0.2.0/24 >/dev/null
r=$(from 192.0.2.10 nolantis_test "$PASS"); has "IP en lista blanca => entra" "$r" "logged_in_cookie=1"
helper set whitelist "" >/dev/null
before=$(sql "SELECT locked_until FROM wp_nolantis_login_lockouts WHERE kind='user'"); sleep 2
from 45.91.99.3 nolantis_test mala >/dev/null
after=$(sql "SELECT locked_until FROM wp_nolantis_login_lockouts WHERE kind='user'")
if (( after > before )); then ok "un ataque continuado renueva el modo protegido"; else ko "renovación" "$before -> $after"; fi
check "aviso por email enviado una sola vez" "$(mailcount 'Ataque contra la cuenta')" 1
ID=$(sql "SELECT id FROM wp_nolantis_login_lockouts WHERE kind='user'")
$W php -r '$_SERVER["HTTP_HOST"]="localhost:8090"; require "/var/www/html/wp-load.php"; nolantis_ll_unlock_by_id('"$ID"');'
r=$(from 45.91.99.4 nolantis_test "$PASS"); has "tras desbloquear la cuenta, IP nueva entra" "$r" "logged_in_cookie=1"

echo "== 2. Usuarios típicos de bots"
helper reset >/dev/null
r=$(from 203.0.113.210 admin cualquiera); has "probar 'admin' (no existe) => bloqueo inmediato" "$r" "Tu IP está bloqueada"
check "motivo registrado" "$(sql "SELECT reason FROM wp_nolantis_login_lockouts WHERE ip='203.0.113.210'")" "bot_user"
r=$(from 203.0.113.211 Administrator x); has "mayúsculas también" "$r" "Tu IP está bloqueada"
helper mkuser2 demo "Demo-$(openssl rand -hex 6)" >/dev/null
r=$(from 203.0.113.212 demo mala); has "'demo' existe de verdad => fallo normal, sin bloqueo" "$r" "Te quedan"
r=$(from 203.0.113.213 usuario_raro x); has "usuario fuera de la lista => fallo normal" "$r" "Te quedan"
helper set bot_usernames_enabled 0 >/dev/null
r=$(from 203.0.113.214 admin x); has "con la opción desactivada => fallo normal" "$r" "Te quedan"
helper set bot_usernames_enabled 1 >/dev/null

echo "== 3. Recuperación de contraseña"
helper reset >/dev/null
lp(){ $W curl -s -i "${H[@]}" -H "X-Forwarded-For: $1" --data-urlencode "user_login=$2" -d 'wp-submit=x' 'http://localhost/probandoruta1/?action=lostpassword' | tr '\n' ' '; }
loc(){ grep -o 'Location: [^ ]*' <<<"$1" | head -1; }
err(){ grep -o '<div id="login_error"[^>]*>.*</div>' <<<"$1" | sed -E 's/<[^>]+>/ /g; s/ +/ /g' | cut -c1-120; }
for i in 1 2 3; do r=$(lp 203.0.113.220 "noexiste$i"); done
has "3 solicitudes desde una IP => normal" "$(loc "$r")" "checkemail=confirm"
r=$(lp 203.0.113.220 noexiste4); has "4ª desde la misma IP => límite" "$(err "$r")" "demasiados restablecimientos"
for i in 1 2 3; do lp 203.0.113.23$i nolantis_test >/dev/null; done
r=$(lp 203.0.113.239 nolantis_test); E1=$(err "$r"); has "4ª para la misma cuenta desde otra IP => límite" "$E1" "demasiados restablecimientos"
for i in 1 2 3; do lp 203.0.113.24$i fantasma >/dev/null; done
r=$(lp 203.0.113.249 fantasma); E2=$(err "$r")
check "límite: misma respuesta exista o no la cuenta" "$E2" "$E1"
check "al superar el límite no se envían más correos de restablecimiento" "$(sql "SELECT COUNT(*) FROM wp_nolantis_login_log WHERE event='reset' AND username='nolantis_test'")" 3

echo "== 4. Cloudflare Turnstile (claves de prueba oficiales de Cloudflare)"
helper reset >/dev/null
helper set turnstile_site_key 1x00000000000000000000AA >/dev/null
helper set turnstile_secret_key 1x0000000000000000000000000000000AA >/dev/null
helper set turnstile_after_failures 0 >/dev/null
page=$($W curl -s "${H[@]}" -H 'X-Forwarded-For: 203.0.113.250' http://localhost/probandoruta1/)
has "widget en el formulario" "$page" 'class="cf-turnstile"'
has "script de Turnstile cargado" "$page" 'challenges.cloudflare.com/turnstile/v0/api.js'
r=$(from 203.0.113.250 nolantis_test "$PASS"); has "sin resolver el CAPTCHA => rechazado" "$r" "verificación de seguridad"
r=$(from 203.0.113.250 nolantis_test "$PASS" --data-urlencode 'cf-turnstile-response=XXXX.DUMMY.TOKEN.XXXX'); has "CAPTCHA válido => entra" "$r" "logged_in_cookie=1"
helper set turnstile_secret_key 2x0000000000000000000000000000000AA >/dev/null
r=$(from 203.0.113.251 nolantis_test "$PASS" --data-urlencode 'cf-turnstile-response=XXXX.DUMMY.TOKEN.XXXX'); has "CAPTCHA rechazado por Cloudflare => no entra" "$r" "verificación de seguridad"
helper set turnstile_secret_key 1x0000000000000000000000000000000AA >/dev/null
helper set turnstile_after_failures 2 >/dev/null
page=$($W curl -s "${H[@]}" -H 'X-Forwarded-For: 203.0.113.252' http://localhost/probandoruta1/)
hasnt "modo 'a partir de 2 fallos': sin fallos no aparece" "$page" 'class="cf-turnstile"'
for i in 1 2; do from 203.0.113.252 nolantis_test mala >/dev/null; done
page=$($W curl -s "${H[@]}" -H 'X-Forwarded-For: 203.0.113.252' http://localhost/probandoruta1/)
has "tras 2 fallos aparece" "$page" 'class="cf-turnstile"'
echo "   -- cuenta en modo protegido + CAPTCHA permite entrar desde una red nueva"
helper set user_max_failures 3 >/dev/null
for i in 1 2 3; do from 45.91.50.$i nolantis_test mala >/dev/null; done
check "cuenta protegida" "$(helper userlock nolantis_test)" "active"
r=$(from 45.91.60.1 nolantis_test "$PASS"); has "red nueva sin CAPTCHA => pide verificación" "$r" "completando la verificación de seguridad"
page=$($W curl -s "${H[@]}" -H 'X-Forwarded-For: 45.91.60.1' http://localhost/probandoruta1/)
has "  ...y ahora le muestra el CAPTCHA" "$page" 'class="cf-turnstile"'
r=$(from 45.91.60.1 nolantis_test "$PASS" --data-urlencode 'cf-turnstile-response=XXXX.DUMMY.TOKEN.XXXX'); has "  ...resolviéndolo entra" "$r" "logged_in_cookie=1"
helper set turnstile_site_key "" >/dev/null; helper set turnstile_secret_key "" >/dev/null; helper set user_max_failures 10 >/dev/null
page=$($W curl -s "${H[@]}" http://localhost/probandoruta1/)
hasnt "sin claves no se carga nada externo" "$page" 'challenges.cloudflare.com'

echo "== 5. Avisos por email"
helper reset >/dev/null; $W sh -c 'rm -f /tmp/nolantis-mails.log'
helper set lockout_1_minutes 1 >/dev/null; helper set alert_long_lockout 1 >/dev/null
IP=45.90.12.77
for n in 1 2 3; do for i in 1 2 3 4 5; do from $IP nolantis_test mala >/dev/null; done; helper expire $IP >/dev/null; done
check "aviso de bloqueo nivel 3" "$(mailcount 'IP bloqueada por reincidencia')" 1
helper set alert_admin_new_ip 1 >/dev/null
APASS="Adm-$(openssl rand -hex 8)"; helper mkuser2 nolantis_test_admin "$APASS" administrator >/dev/null
from 203.0.113.30 nolantis_test_admin "$APASS" >/dev/null
check "primer acceso de un admin (sin historial) => sin aviso" "$(mailcount 'Nuevo acceso de administrador')" 0
from 203.0.113.30 nolantis_test_admin "$APASS" >/dev/null
check "admin desde IP conocida => sin aviso" "$(mailcount 'Nuevo acceso de administrador')" 0
from 203.0.113.31 nolantis_test_admin "$APASS" >/dev/null
check "admin desde IP nueva => aviso" "$(mailcount 'Nuevo acceso de administrador')" 1
from 203.0.113.32 nolantis_test "$PASS" >/dev/null
check "un usuario que no es admin => sin aviso" "$(mailcount 'Nuevo acceso de administrador')" 1
helper set alert_email avisos@example.invalid >/dev/null; helper set alert_user_attack 1 >/dev/null; helper set user_max_failures 3 >/dev/null
for i in 1 2 3; do from 45.91.70.$i fantasma2 x >/dev/null; done
has "destinatario personalizado" "$(mails | grep 'Ataque contra la cuenta')" "TO: avisos@example.invalid"
helper set alert_user_attack 0 >/dev/null
for i in 1 2 3; do from 45.91.71.$i fantasma3 x >/dev/null; done
check "aviso desactivado => no se envía" "$(mailcount 'fantasma3')" 0

echo "== 6. Panel"
for tab in estado registro ajustes avisos; do has "pestaña $tab sin errores" "$(helper render $tab)" "rendered"; done
html=$(helper render estado x); hasnt "sin rastro de 2FA en el panel" "$html" "dos pasos"
html=$(helper render estado x); has "revisión: avisa del slug que revela el usuario" "$html" "Revela el usuario"

echo "== Limpieza"
$W sh -c 'rm -f /var/www/html/wp-content/mu-plugins/zz-test-mail-capture.php /tmp/nolantis-mails.log /tmp/jar.txt'
helper reset >/dev/null; helper deluser >/dev/null
echo; echo "RESULTADO FASE 2: $PASSES OK, $FAILS FALLOS"
