#!/usr/bin/env bash
# Bateria de pruebas del modulo Limit Login contra el WordPress local de Docker.
# Las peticiones salen de dentro del contenedor; el mod_remoteip de la imagen oficial acepta
# X-Forwarded-For desde loopback, lo que permite simular atacantes con IPs publicas reales.
SP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SP/ll-lib.sh"
PASSES=0; FAILS=0
ok()   { PASSES=$((PASSES+1)); echo "  OK    $1"; }
ko()   { FAILS=$((FAILS+1));  echo "  FALLO $1 :: $2"; }
check(){ if [[ "$2" == "$3" ]]; then ok "$1"; else ko "$1" "esperado [$3] obtenido [$2]"; fi; }
has()  { if [[ "$2" == *"$3"* ]]; then ok "$1"; else ko "$1" "no contiene [$3] en [${2:0:160}]"; fi; }
hasnt(){ if [[ "$2" != *"$3"* ]]; then ok "$1"; else ko "$1" "contiene [$3]"; fi; }
sql()  { docker exec nolantis-gestion-db sh -c "mysql -u\"\$MYSQL_USER\" -p\"\$MYSQL_PASSWORD\" \"\$MYSQL_DATABASE\" -N -e \"$1\" 2>/dev/null"; }
fails(){ sql "SELECT COUNT(*) FROM wp_nolantis_login_log WHERE ip='$1' AND event='failed'"; }
locks(){ sql "SELECT COUNT(*) FROM wp_nolantis_login_lockouts WHERE ip='$1'"; }
active(){ sql "SELECT COUNT(*) FROM wp_nolantis_login_lockouts WHERE ip='$1' AND locked_until > UNIX_TIMESTAMP()"; }
level(){ sql "SELECT level FROM wp_nolantis_login_lockouts WHERE ip='$1' ORDER BY id DESC LIMIT 1"; }
from() { local ip="$1"; shift; login "$@" -H "X-Forwarded-For: $ip"; }

PASS="T3st-$(openssl rand -hex 8)"
helper reset >/dev/null; helper deluser >/dev/null; helper mkuser "$PASS" >/dev/null
helper set user_protection_enabled 0 >/dev/null  # esta bateria prueba la logica por IP

echo "== 1. Secuencial desde IP pública (203.0.113.10)"
IP=203.0.113.10
r=$(from $IP nolantis_test mala1); has "mensaje genérico" "$r" "Usuario o contraseña incorrectos"; has "intentos restantes" "$r" "Te quedan 4"
r=$(from $IP no_existe_xyz x);     has "usuario inexistente => mismo mensaje" "$r" "Usuario o contraseña incorrectos"
for i in 3 4; do from $IP nolantis_test mala$i >/dev/null; done
r=$(from $IP nolantis_test mala5); has "5º fallo bloquea" "$r" "Tu IP está bloqueada"
r=$(from $IP nolantis_test "$PASS"); has "contraseña correcta rechazada durante bloqueo" "$r" "logged_in_cookie=0"
check "fallos registrados" "$(fails $IP)" 5
check "un solo bloqueo" "$(locks $IP)" 1
check "duración nivel 1 = 1800s" "$(sql "SELECT locked_until-locked_at FROM wp_nolantis_login_lockouts WHERE ip='$IP'")" 1800

echo "== 2. IP interna (127.0.0.1) nunca se bloquea"
for i in 1 2 3 4 5 6 7; do login nolantis_test mala >/dev/null; done
check "fallos registrados" "$(fails 127.0.0.1)" 7
check "sin bloqueo" "$(locks 127.0.0.1)" 0
r=$(login nolantis_test "$PASS"); has "puede entrar" "$r" "logged_in_cookie=1"

echo "== 3. Ataque en paralelo: 30 peticiones simultáneas desde 198.51.100.20"
IP=198.51.100.20
seq 1 30 | xargs -P 30 -I{} docker exec nolantis-gestion-wp curl -s -o /dev/null -H 'Host: localhost:8090' -H "X-Forwarded-For: $IP" --data 'log=nolantis_test&pwd=mala{}' http://localhost/probandoruta1/
check "solo 5 contraseñas comprobadas" "$(fails $IP)" 5
check "un único bloqueo" "$(locks $IP)" 1
check "nivel 1 (no escalado corrupto)" "$(level $IP)" 1
check "los otros 25 rechazados sin comprobar contraseña" "$(sql "SELECT blocked_attempts FROM wp_nolantis_login_lockouts WHERE ip='$IP'")" 25

echo "== 4. Escalado 30 min -> 24 h -> 7 días -> 7 días (45.90.12.34)"
IP=45.90.12.34
f5(){ for i in 1 2 3 4 5; do from $IP nolantis_test mala >/dev/null; done; }
f5; check "nivel 1" "$(level $IP)" 1
helper expire $IP >/dev/null; f5; check "reincide => nivel 2" "$(level $IP)" 2
check "duración 24h" "$(sql "SELECT locked_until-locked_at FROM wp_nolantis_login_lockouts WHERE ip='$IP' ORDER BY id DESC LIMIT 1")" 86400
helper expire $IP >/dev/null; f5; check "reincide => nivel 3" "$(level $IP)" 3
check "duración 7 días" "$(sql "SELECT locked_until-locked_at FROM wp_nolantis_login_lockouts WHERE ip='$IP' ORDER BY id DESC LIMIT 1")" 604800
helper expire $IP >/dev/null; f5; check "sigue en nivel 3" "$(level $IP)" 3

echo "== 5. Desbloqueo manual reinicia contador y escalado"
helper unlock $IP >/dev/null
check "sin bloqueo activo" "$(active $IP)" 0
r=$(from $IP nolantis_test "$PASS"); has "puede entrar" "$r" "logged_in_cookie=1"
for i in 1 2 3 4; do from $IP nolantis_test mala >/dev/null; done
check "4 fallos no bloquean" "$(active $IP)" 0
f5 >/dev/null; check "nuevo bloqueo empieza en nivel 1" "$(level $IP)" 1

echo "== 6. IPv6: rotar direcciones dentro del mismo /64 no sirve"
for i in 1 2 3 4 5; do from 2a01:4f8:aa:bb::$i nolantis_test mala >/dev/null; done
check "bloqueo por /64" "$(active 2a01:4f8:aa:bb::/64)" 1
r=$(from 2a01:4f8:aa:bb::9999 nolantis_test "$PASS"); has "otra dirección del mismo /64 bloqueada" "$r" "Tu IP está bloqueada"
r=$(from 2a01:4f8:aa:cc::1 nolantis_test "$PASS"); has "otro /64 distinto puede entrar" "$r" "logged_in_cookie=1"

echo "== 7. Lista blanca (incluye rango CIDR)"
helper set whitelist 192.0.2.0/24 >/dev/null
for i in 1 2 3 4 5 6 7; do from 192.0.2.55 nolantis_test mala >/dev/null; done
check "fallos registrados" "$(fails 192.0.2.55)" 7
check "sin bloqueo" "$(locks 192.0.2.55)" 0
helper set whitelist "" >/dev/null

echo "== 8. XML-RPC"
X='<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>'
check "desactivado por defecto => 403" "$($W curl -s -o /dev/null -w '%{http_code}' "${H[@]}" -d "$X" http://localhost/xmlrpc.php)" 403
helper set enable_xmlrpc 1 >/dev/null
check "activado con la casilla => 200" "$($W curl -s -o /dev/null -w '%{http_code}' "${H[@]}" -d "$X" http://localhost/xmlrpc.php)" 200
IP=203.0.113.30
x(){ $W curl -s "${H[@]}" -H "X-Forwarded-For: $IP" -d "<?xml version=\"1.0\"?><methodCall><methodName>wp.getUsersBlogs</methodName><params><param><value><string>nolantis_test</string></value></param><param><value><string>$1</string></value></param></params></methodCall>" http://localhost/xmlrpc.php; }
for i in 1 2 3 4 5; do x mala$i >/dev/null; done
check "5 fallos XML-RPC => bloqueo" "$(active $IP)" 1
r=$(x "$PASS"); hasnt "contraseña correcta rechazada" "$r" "isAdmin"
helper set enable_xmlrpc 0 >/dev/null

echo "== 9. REST con contraseñas de aplicación"
r=$(helper apppass 203.0.113.40 6)
check "bloqueo tras 5 fallos" "$(active 203.0.113.40)" 1
has "la buena tampoco entra estando bloqueada" "$r" "good (while locked?) -> NULL"

echo "== 10. Detección de IP"
t(){ check "$1" "$(helper ipdetect "${@:3}")" "$2"; }
t "auto sin proxy"                          203.0.113.50  auto 203.0.113.50
t "auto ignora XFF falsa"                   203.0.113.50  auto 203.0.113.50 "" "1.1.1.1"
t "auto ignora CF-Connecting-IP falsa"      203.0.113.50  auto 203.0.113.50 "1.1.1.1"
t "auto + Cloudflare real => IP visitante"  198.51.100.7  auto 173.245.48.10 "198.51.100.7"
t "auto + Cloudflare IPv6"                  2001:db8::abcd auto 2606:4700::1 "2001:db8::abcd"
t "solo directa ignora Cloudflare"          173.245.48.10 remote_addr 173.245.48.10 "198.51.100.7"
t "proxy de confianza"                      198.51.100.7  proxy 10.0.0.5 "" "198.51.100.7" "10.0.0.0/8"
t "proxy: ignora IPs falsas a la izquierda" 198.51.100.7  proxy 10.0.0.5 "" "6.6.6.6, 198.51.100.7, 10.0.0.9" "10.0.0.0/8"
t "proxy + Cloudflare en la cadena"         198.51.100.7  proxy 10.0.0.5 "" "6.6.6.6, 198.51.100.7, 173.245.48.10" "10.0.0.0/8"
t "proxy: petición que no viene del proxy"  203.0.113.50  proxy 203.0.113.50 "" "1.1.1.1" "10.0.0.0/8"
t "IPv4 mapeada en IPv6"                    192.0.2.1     auto ::ffff:192.0.2.1
helper set ip_source auto >/dev/null; helper set trusted_proxies "" >/dev/null

echo "== 11. Enumeración de usuarios"
c(){ $W curl -s -o /dev/null -w '%{http_code}' "${H[@]}" "http://localhost/$1"; }
check "/?author=1 => 404" "$(c '?author=1')" 404
check "REST users => 404" "$(c '?rest_route=/wp/v2/users')" 404
check "REST users/1 => 404" "$(c '?rest_route=/wp/v2/users/1')" 404
check "REST posts sigue funcionando" "$(c '?rest_route=/wp/v2/posts')" 200
POST_URL=$($W curl -s "${H[@]}" 'http://localhost/?rest_route=/wp/v2/posts&per_page=1' | grep -o '"link":"[^"]*"' | head -1 | cut -d'"' -f4 | sed 's#\\/#/#g')
if [[ -n "$POST_URL" ]]; then
  r=$($W curl -s -G "${H[@]}" --data-urlencode "url=$POST_URL" 'http://localhost/?rest_route=/oembed/1.0/embed')
  has "oEmbed responde" "$r" '"title"'; hasnt "oEmbed sin author_url" "$r" 'author_url'
else ko "oEmbed" "no hay ninguna entrada publicada para probar"; fi

echo "== 12. ¿Has olvidado tu contraseña?"
lp(){ $W curl -s -o /dev/null -w '%{redirect_url}' "${H[@]}" --data-urlencode "user_login=$1" -d 'wp-submit=x' 'http://localhost/probandoruta1/?action=lostpassword'; }
has "usuario inexistente => misma respuesta" "$(lp no_existe_xyz)" "checkemail=confirm"
has "email inexistente => misma respuesta" "$(lp nadie@example.invalid)" "checkemail=confirm"

echo "== 13. Ver el formulario no cuenta como intento"
for i in $(seq 1 10); do $W curl -s -o /dev/null "${H[@]}" -H "X-Forwarded-For: 203.0.113.60" http://localhost/probandoruta1/; done
check "0 fallos" "$(fails 203.0.113.60)" 0

echo "== 14. Panel"
for tab in estado registro ajustes avisos; do has "pestaña $tab sin errores" "$(helper render $tab)" "rendered"; done

echo "== Limpieza"
helper reset >/dev/null; helper deluser >/dev/null
echo; echo "RESULTADO: $PASSES OK, $FAILS FALLOS"
