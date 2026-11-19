#!/usr/bin/env bash
# Mide consultas a la base de datos, tiempo y memoria de varias paginas con y sin el plugin.
# Uso: bash tests/perf-measure.sh   (el plugin de medicion zz-perf.php se instala y se quita solo)
SP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
docker exec -i nolantis-gestion-wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/zz-perf.php' < "$SP/zz-perf.php"
docker exec -i -u www-data nolantis-gestion-wp php /dev/stdin <<<"<?php \$_SERVER['HTTP_HOST']='localhost:8090'; require '/var/www/html/wp-load.php'; if ( ! get_user_by( 'login', 'nolantis_perf' ) ) { wp_insert_user( array( 'user_login' => 'nolantis_perf', 'user_pass' => 'Perf-12345-xyz', 'user_email' => 'perf@example.invalid', 'role' => 'administrator' ) ); }" >/dev/null
C=(docker exec nolantis-gestion-wp curl -s -o /dev/null -H Host:localhost:8090 -c /tmp/perfjar.txt -b /tmp/perfjar.txt)
docker exec nolantis-gestion-wp rm -f /tmp/perfjar.txt /tmp/nolantis-perf.log
"${C[@]}" --data-urlencode "log=nolantis_perf" --data-urlencode "pwd=Perf-12345-xyz" http://localhost/probandoruta1/
docker exec nolantis-gestion-wp sh -c ': > /tmp/nolantis-perf.log; chmod 666 /tmp/nolantis-perf.log'
for url in "/" "/?p=1" "/?feed=rss2" "/?rest_route=/wp/v2/posts" "/probandoruta1/" "/wp-admin/index.php" "/wp-admin/edit.php"; do
  for mode in on off; do
    for i in $(seq 1 12); do
      if [[ "$url" == /wp-admin* ]]; then J=(-c /tmp/perfjar.txt -b /tmp/perfjar.txt); else J=(); fi
      if [[ $mode == off ]]; then docker exec nolantis-gestion-wp curl -s -o /dev/null -H Host:localhost:8090 -H 'X-Nolantis-Off: 1' "${J[@]}" "http://localhost$url"; else docker exec nolantis-gestion-wp curl -s -o /dev/null -H Host:localhost:8090 "${J[@]}" "http://localhost$url"; fi
    done
  done
done
OUT=$(mktemp); docker exec nolantis-gestion-wp cat /tmp/nolantis-perf.log > "$OUT"
docker exec nolantis-gestion-wp rm -f /var/www/html/wp-content/mu-plugins/zz-perf.php /tmp/perfjar.txt /tmp/nolantis-perf.log
python3 - "$OUT" <<'PY'
import json,sys,statistics as st
rows=[json.loads(l) for l in open(sys.argv[1]) if l.strip()]
urls=[]
for r in rows:
    if r['uri'] not in urls: urls.append(r['uri'])
print(f"{'página':32} {'consultas con/sin':>18} {'del plugin':>10} {'ms con':>8} {'ms sin':>8} {'MB con/sin':>11}")
for u in urls:
    on=[r for r in rows if r['uri']==u and not r['off']][2:]; off=[r for r in rows if r['uri']==u and r['off']][2:]
    if not on or not off: continue
    print(f"{u[:32]:32} {on[0]['total']:>8} / {off[0]['total']:<8} {len(on[0]['ours']):>10} {st.median([r['ms'] for r in on]):>8.1f} {st.median([r['ms'] for r in off]):>8.1f} {on[0]['mem']:>5}/{off[0]['mem']:<5}")
print("\nConsultas del plugin por página:")
seen=set()
for u in urls:
    on=[r for r in rows if r['uri']==u and not r['off']]
    for q in (on[-1]['ours'] if on else []):
        print(f"  [{u[:24]}] {q}")
PY
rm -f "$OUT"; docker exec -i -u www-data nolantis-gestion-wp php /dev/stdin <<<"<?php \$_SERVER['HTTP_HOST']='localhost:8090'; require '/var/www/html/wp-load.php'; require_once ABSPATH.'wp-admin/includes/user.php'; \$u = get_user_by( 'login', 'nolantis_perf' ); if ( \$u ) { wp_delete_user( \$u->ID ); }"
