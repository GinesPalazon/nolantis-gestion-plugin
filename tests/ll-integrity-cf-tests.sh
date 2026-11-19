#!/usr/bin/env bash
# Pruebas de: rangos de Cloudflare actualizados solos (7) y comprobacion de integridad (3).
SP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PASSES=0; FAILS=0
ok()   { PASSES=$((PASSES+1)); echo "  OK    $1"; }
ko()   { FAILS=$((FAILS+1));  echo "  FALLO $1 :: $2"; }
check(){ if [[ "$2" == "$3" ]]; then ok "$1"; else ko "$1" "esperado [$3] obtenido [$2]"; fi; }
has()  { if [[ "$2" == *"$3"* ]]; then ok "$1"; else ko "$1" "no contiene [$3] en [${2:0:220}]"; fi; }
hasnt(){ if [[ "$2" != *"$3"* ]]; then ok "$1"; else ko "$1" "contiene [$3]"; fi; }
WPHP(){ docker exec -i -u www-data nolantis-gestion-wp php /dev/stdin <<<"<?php \$_SERVER['HTTP_HOST']='localhost:8090'; require '/var/www/html/wp-load.php'; require_once ABSPATH.'wp-admin/includes/admin.php'; $1"; }
D=(docker exec -u www-data nolantis-gestion-wp)
C=(docker exec nolantis-gestion-wp curl -s -H Host:localhost:8090 -c /tmp/intjar.txt -b /tmp/intjar.txt)
W=/var/www/html

echo "== Preparación"
WPHP "update_option( 'nolantis_test_cf_backup', get_option( NOLANTIS_LL_CF_OPTION, '__none__' ) ); update_option( 'nolantis_test_int_backup', get_option( NOLANTIS_INTEGRITY_OPTION, '__none__' ) ); update_option( 'nolantis_test_ll_backup', get_option( NOLANTIS_LL_OPTION, '__none__' ) ); delete_option( NOLANTIS_LL_CF_OPTION );" >/dev/null
docker exec -i nolantis-gestion-wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/zz-nolantis-audit.php' < "$SP/zz-nolantis-audit.php"
docker exec nolantis-gestion-wp sh -c ': > /tmp/nolantis-mails.log; chmod 666 /tmp/nolantis-mails.log'

echo "== A. Rangos de Cloudflare"
OUT=$(WPHP "echo 'BUILTIN=', count( nolantis_ll_get_cloudflare_ranges() ), '|ESTADO=', nolantis_ll_get_cloudflare_ranges_status()['source'];")
has "sin descargar todavía => usa los incluidos" "$OUT" "ESTADO=builtin"
OUT=$(WPHP "\$ok = nolantis_ll_update_cloudflare_ranges(); \$s = nolantis_ll_get_cloudflare_ranges_status(); \$r = nolantis_ll_get_cloudflare_ranges(); echo 'OK=', \$ok ? 1 : 0, '|FUENTE=', \$s['source'], '|N=', \$s['count'], '|ERR=[', \$s['last_error'], ']|TIENE=', in_array( '173.245.48.0/20', \$r, true ) ? 1 : 0, '|V6=', count( preg_grep( '/:/', \$r ) );")
has "descarga real desde la API de Cloudflare" "$OUT" "OK=1|FUENTE=downloaded"
has "  ...sin error" "$OUT" "ERR=[]"
has "  ...incluye rangos conocidos" "$OUT" "TIENE=1"
N=$(grep -oP '\|N=\K[0-9]+' <<<"$OUT"); V6=$(grep -oP 'V6=\K[0-9]+' <<<"$OUT"); if (( N >= 15 && V6 >= 3 )); then ok "  ...$N rangos ($V6 IPv6)"; else ko "número de rangos" "$OUT"; fi
OUT=$(WPHP "
\$v = 'nolantis_ll_cloudflare_ranges_are_valid'; \$b = nolantis_ll_get_builtin_cloudflare_ranges();
echo 'BUENA=', \$v( \$b ) ? 1 : 0;
echo '|TODO_INTERNET=', \$v( array_merge( \$b, array( '0.0.0.0/0' ) ) ) ? 1 : 0;
echo '|RANGO_ENORME=', \$v( array_merge( \$b, array( '10.0.0.0/8' ) ) ) ? 1 : 0;
echo '|IPV6_ENORME=', \$v( array_merge( \$b, array( '2000::/3' ) ) ) ? 1 : 0;
echo '|BASURA=', \$v( array_merge( \$b, array( 'hola' ) ) ) ? 1 : 0;
echo '|IP_SUELTA=', \$v( array_merge( \$b, array( '1.2.3.4' ) ) ) ? 1 : 0;
echo '|POCOS=', \$v( array( '173.245.48.0/20', '2400:cb00::/32' ) ) ? 1 : 0;")
has "lista oficial => válida" "$OUT" "BUENA=1"
has "con 0.0.0.0/0 (todo Internet) => rechazada" "$OUT" "TODO_INTERNET=0"
has "con un /8 => rechazada" "$OUT" "RANGO_ENORME=0"
has "con un IPv6 enorme => rechazada" "$OUT" "IPV6_ENORME=0"
has "con texto basura => rechazada" "$OUT" "BASURA=0"
has "con una IP suelta => rechazada" "$OUT" "IP_SUELTA=0"
has "con muy pocos rangos => rechazada" "$OUT" "POCOS=0"
OUT=$(WPHP "
\$antes = nolantis_ll_get_cloudflare_ranges();
add_filter( 'pre_http_request', function () { return array( 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null, 'body' => wp_json_encode( array( 'success' => true, 'result' => array( 'ipv4_cidrs' => array( '0.0.0.0/0', '1.0.0.0/8', '2.0.0.0/8', '3.0.0.0/8', '4.0.0.0/8' ), 'ipv6_cidrs' => array( '::/0', '2000::/3', '2001::/16' ) ) ) ) ); }, 1 );
\$ok = nolantis_ll_update_cloudflare_ranges();
echo 'OK=', \$ok ? 1 : 0, '|IGUAL=', ( \$antes === nolantis_ll_get_cloudflare_ranges() ? 1 : 0 ), '|ERR=', nolantis_ll_get_cloudflare_ranges_status()['last_error'];")
has "respuesta manipulada => rechazada" "$OUT" "OK=0"
has "  ...se mantienen los rangos anteriores" "$OUT" "IGUAL=1"
has "  ...y se explica el motivo" "$OUT" "no supera las comprobaciones de seguridad"
OUT=$(WPHP "
\$antes = nolantis_ll_get_cloudflare_ranges();
add_filter( 'pre_http_request', function () { return new WP_Error( 'http_request_failed', 'sin conexion (simulado)' ); }, 1 );
echo 'OK=', nolantis_ll_update_cloudflare_ranges() ? 1 : 0, '|IGUAL=', ( \$antes === nolantis_ll_get_cloudflare_ranges() ? 1 : 0 );")
has "sin conexión => falla sin perder los rangos" "$OUT" "OK=0|IGUAL=1"
OUT=$(WPHP "\$s = get_option( NOLANTIS_LL_CF_OPTION ); \$s['ranges'][] = '0.0.0.0/0'; update_option( NOLANTIS_LL_CF_OPTION, \$s ); echo 'USA=', ( nolantis_ll_get_cloudflare_ranges() === nolantis_ll_get_builtin_cloudflare_ranges() ? 'incluidos' : 'guardados' );")
has "lista guardada alterada en la base de datos => vuelve a los incluidos" "$OUT" "USA=incluidos"
WPHP "nolantis_ll_update_cloudflare_ranges();" >/dev/null
OUT=$(WPHP "\$s = nolantis_ll_get_settings(); \$s['ip_source'] = 'auto'; update_option( NOLANTIS_LL_OPTION, \$s ); \$_SERVER['REMOTE_ADDR'] = '173.245.48.10'; \$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7'; echo 'IP=', nolantis_get_request_ip();")
has "la detección de IP usa los rangos descargados" "$OUT" "IP=198.51.100.7"
check "tarea semanal programada" "$(WPHP "echo wp_get_schedule( NOLANTIS_LL_CF_HOOK );")" "weekly"

echo "== B. Integridad: web limpia"
OUT=$(WPHP "\$r = nolantis_integrity_scan(); echo 'ERR=[', \$r['error'], ']|COMPROBADOS=', \$r['checked'], '|MOD=', \$r['modified_total'], '|DESC=', implode( ',', \$r['unknown'] ), '|UPL=', \$r['uploads_php_total'], '|FALTAN=', implode( ',', \$r['missing'] );")
has "descarga la lista oficial de WordPress.org" "$OUT" "ERR=[]"
C1=$(grep -oP 'COMPROBADOS=\K[0-9]+' <<<"$OUT"); if (( C1 > 1000 )); then ok "  ...$C1 archivos comprobados"; else ko "archivos comprobados" "$OUT"; fi
has "ningún archivo de WordPress modificado" "$OUT" "MOD=0"
has "ningún PHP en uploads" "$OUT" "UPL=0"
echo "   (PHP desconocidos en esta instalación Docker: $(grep -oP 'DESC=\K[^|]*' <<<"$OUT"))"
BASE_UNKNOWN=$(grep -oP 'DESC=\K[^|]*' <<<"$OUT")

echo "== C. Integridad: simulación de hackeo"
"${D[@]}" sh -c "cp $W/readme.html /tmp/readme.html.bak && echo '<!-- inyectado -->' >> $W/readme.html && mv $W/license.txt /tmp/license.txt.bak && printf '<?php /* prueba */ eval(\$_POST[\"x\"]);' > $W/wp-includes/zz-prueba-malware.php && mkdir -p $W/wp-content/uploads/2026/11 && printf '<?php /* prueba */' > $W/wp-content/uploads/2026/11/zz-shell.php && printf '<?php // Silence is golden.' > $W/wp-content/uploads/2026/index.php"
OUT=$(WPHP "\$r = nolantis_integrity_scan(); echo 'MOD=', implode( ',', \$r['modified'] ), '|DESC=', implode( ',', \$r['unknown'] ), '|UPL=', implode( ',', \$r['uploads_php'] ), '|FALTAN=', implode( ',', \$r['missing'] ), '|PROBLEMAS=', nolantis_integrity_count_issues( \$r );")
has "archivo de WordPress modificado detectado" "$OUT" "MOD=readme.html"
has "PHP desconocido en wp-includes detectado" "$OUT" "wp-includes/zz-prueba-malware.php"
has "PHP en uploads detectado" "$OUT" "wp-content/uploads/2026/11/zz-shell.php"
hasnt "el index.php 'Silence is golden' no se marca" "$OUT" "uploads/2026/index.php"
has "archivo que falta detectado" "$OUT" "FALTAN=license.txt"
check "aviso por email: desactivado por defecto => no se envía" "$(docker exec nolantis-gestion-wp grep -c 'sospechosos' /tmp/nolantis-mails.log)" 0
WPHP "\$s = nolantis_ll_get_settings(); \$s['alert_integrity'] = 1; update_option( NOLANTIS_LL_OPTION, \$s ); nolantis_integrity_scan();" >/dev/null
check "aviso por email activado => se envía" "$(docker exec nolantis-gestion-wp grep -c 'Posibles archivos sospechosos' /tmp/nolantis-mails.log)" 1
WPHP "nolantis_integrity_scan();" >/dev/null
check "  ...y no se repite si no cambia nada" "$(docker exec nolantis-gestion-wp grep -c 'Posibles archivos sospechosos' /tmp/nolantis-mails.log)" 1
check "comprobación diaria programada" "$(WPHP "echo wp_get_schedule( NOLANTIS_INTEGRITY_HOOK );")" "daily"

echo "== D. Panel"
AP="Adm-$(openssl rand -hex 8)"; WPHP "wp_insert_user( array( 'user_login' => 'nolantis_int_admin', 'user_pass' => '$AP', 'user_email' => 'int@example.invalid', 'role' => 'administrator' ) );" >/dev/null
docker exec nolantis-gestion-wp rm -f /tmp/intjar.txt
"${C[@]}" -o /dev/null --data-urlencode "log=nolantis_int_admin" --data-urlencode "pwd=$AP" http://localhost/probandoruta1/
pg(){ "${C[@]}" "http://localhost/wp-admin/$1"; }
H=$(pg 'admin.php?page=nolantis-limit-login&tab=integridad')
has "pestaña Integridad muestra el problema" "$H" "posibles problemas"
has "  ...con el archivo sospechoso" "$H" "wp-includes/zz-prueba-malware.php"
H=$(pg 'admin.php?page=nolantis-limit-login&tab=estado')
has "revisión de seguridad avisa" "$H" "posibles archivos sospechosos"
has "estado de los rangos de Cloudflare" "$H" "descargados de Cloudflare el"
N=$(tr '\n' ' ' <<<"$H" | grep -oP 'name="_wpnonce" value="\K[^"]+(?="[^<]*(<input type="hidden" name="_wp_http_referer"[^>]*>\s*)?<input type="hidden" name="action" value="nolantis_ll_update_cf")' | head -1)
check "botón 'Actualizar rangos ahora'" "$("${C[@]}" -o /dev/null -w '%{redirect_url}' -d "action=nolantis_ll_update_cf&_wpnonce=$N" http://localhost/wp-admin/admin-post.php | grep -o 'cf_updated')" "cf_updated"

echo "== E. Restaurar los archivos y comprobar que vuelve a salir limpio"
"${D[@]}" sh -c "cp /tmp/readme.html.bak $W/readme.html && mv /tmp/license.txt.bak $W/license.txt"
docker exec nolantis-gestion-wp rm -f /var/www/html/wp-includes/zz-prueba-malware.php /var/www/html/wp-content/uploads/2026/11/zz-shell.php /var/www/html/wp-content/uploads/2026/index.php /tmp/readme.html.bak
docker exec nolantis-gestion-wp rmdir /var/www/html/wp-content/uploads/2026/11 /var/www/html/wp-content/uploads/2026
H=$(pg 'admin.php?page=nolantis-limit-login&tab=integridad')
N=$(grep -oP 'name="_wpnonce" value="\K[^"]+' <<<"$H" | tail -1)
"${C[@]}" -o /dev/null -d "action=nolantis_integrity_scan&_wpnonce=$N" http://localhost/wp-admin/admin-post.php
OUT=$(WPHP "\$r = nolantis_integrity_get_results(); echo 'MOD=', \$r['modified_total'], '|DESC=', implode( ',', \$r['unknown'] ), '|UPL=', \$r['uploads_php_total'], '|FALTAN=', \$r['missing_total'];")
has "'Comprobar ahora' tras restaurar => vuelve a estar limpio" "$OUT" "MOD=0|DESC=$BASE_UNKNOWN|UPL=0|FALTAN=0"

echo "== Limpieza"
WPHP "
foreach ( array( 'nolantis_test_cf_backup' => NOLANTIS_LL_CF_OPTION, 'nolantis_test_int_backup' => NOLANTIS_INTEGRITY_OPTION, 'nolantis_test_ll_backup' => NOLANTIS_LL_OPTION ) as \$backup => \$option ) {
    \$b = get_option( \$backup ); if ( '__none__' === \$b ) { delete_option( \$option ); } else { update_option( \$option, \$b ); } delete_option( \$backup );
}
require_once ABSPATH . 'wp-admin/includes/user.php'; \$u = get_user_by( 'login', 'nolantis_int_admin' ); if ( \$u ) { wp_delete_user( \$u->ID ); }
echo 'restaurado';"; echo
docker exec nolantis-gestion-wp rm -f /tmp/intjar.txt /tmp/nolantis-mails.log /var/www/html/wp-content/mu-plugins/zz-nolantis-audit.php
echo; echo "RESULTADO CLOUDFLARE E INTEGRIDAD: $PASSES OK, $FAILS FALLOS"
