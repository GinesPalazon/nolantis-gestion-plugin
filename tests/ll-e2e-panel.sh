#!/usr/bin/env bash
# Prueba de extremo a extremo del panel, logueado como administrador (como en el navegador).
SP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SP/ll-lib.sh"
helper reset >/dev/null; helper deluser >/dev/null
APASS="Adm-$(openssl rand -hex 8)"; helper mkuser2 nolantis_test_admin "$APASS" administrator >/dev/null
docker exec nolantis-gestion-wp rm -f /tmp/adminjar.txt
C=(docker exec nolantis-gestion-wp curl -s -H Host:localhost:8090 -c /tmp/adminjar.txt -b /tmp/adminjar.txt)
wpphp(){ docker exec nolantis-gestion-wp php -r '$_SERVER["HTTP_HOST"]="localhost:8090"; require "/var/www/html/wp-load.php"; '"$1"; }
"${C[@]}" -o /dev/null --data-urlencode "log=nolantis_test_admin" --data-urlencode "pwd=$APASS" http://localhost/probandoruta1/
pg(){ "${C[@]}" "http://localhost/wp-admin/$1"; }
ok=0; ko=0
t(){ if [[ "$2" == *"$3"* ]]; then ok=$((ok+1)); echo "  OK    $1"; else ko=$((ko+1)); echo "  FALLO $1 [${2:0:120}]"; fi; }
nonce_for(){ tr '\n' ' ' <<<"$1" | grep -oP 'name="_wpnonce" value="\K[^"]+(?="[^<]*(<input type="hidden" name="_wp_http_referer"[^>]*>\s*)?<input type="hidden" name="action" value="'"$2"'")' | head -1; }

for tab in estado registro ajustes avisos; do t "pestaña $tab carga (logueado)" "$(pg "admin.php?page=nolantis-limit-login&tab=$tab")" 'nav-tab-active'; done
t "título nuevo del menú" "$(pg 'admin.php?page=nolantis-limit-login')" "Seguridad de acceso"

echo "-- guardar desde 'Ajustes' (max_attempts=7, protección por cuenta desmarcada)"
h=$(pg "admin.php?page=nolantis-limit-login&tab=ajustes")
N=$(grep -oP 'name="_wpnonce" value="\K[^"]+' <<<"$h" | head -1)
"${C[@]}" -o /dev/null -d "option_page=nolantis_limit_login_group&action=update&_wpnonce=$N" \
  -d "nolantis_limit_login_settings[enabled]=0&nolantis_limit_login_settings[enabled]=1&nolantis_limit_login_settings[max_attempts]=7&nolantis_limit_login_settings[user_protection_enabled]=0" http://localhost/wp-admin/options.php
echo "-- guardar desde 'CAPTCHA y avisos' (solo campos de esa pestaña)"
h=$(pg "admin.php?page=nolantis-limit-login&tab=avisos")
N=$(grep -oP 'name="_wpnonce" value="\K[^"]+' <<<"$h" | head -1)
"${C[@]}" -o /dev/null -d "option_page=nolantis_limit_login_group&action=update&_wpnonce=$N" \
  -d "nolantis_limit_login_settings[alert_email]=seguridad@example.invalid&nolantis_limit_login_settings[alert_admin_new_ip]=0&nolantis_limit_login_settings[alert_admin_new_ip]=1" http://localhost/wp-admin/options.php
S=$(wpphp '$s=nolantis_ll_get_settings(); echo $s["max_attempts"],"|",$s["user_protection_enabled"],"|",$s["alert_email"],"|",$s["alert_admin_new_ip"],"|",$s["window_minutes"];')
t "guardar una pestaña no borra las demás" "$S" "7|0|seguridad@example.invalid|1|15"

echo "-- botón 'Cambiar slug' de la revisión de seguridad"
UIDA=$(helper uid nolantis_test_admin)
h=$(pg "admin.php?page=nolantis-limit-login&tab=estado")
t "la revisión marca el slug" "$h" "Revela el usuario"
N=$(nonce_for "$h" nolantis_ll_fix_nicename)
"${C[@]}" -o /dev/null -d "action=nolantis_ll_fix_nicename&_wpnonce=$N&user_id=$UIDA" http://localhost/wp-admin/admin-post.php
NN=$(wpphp 'echo get_user_by("login","nolantis_test_admin")->user_nicename;')
if [[ -n "$NN" && "$NN" != *nolantis_test_admin* && "$NN" != *nolantis-test-admin* ]]; then ok=$((ok+1)); echo "  OK    slug cambiado y sin el login: $NN"; else ko=$((ko+1)); echo "  FALLO slug: $NN (nonce: ${N:-vacío})"; fi

echo "-- perfil sin 2FA"
h=$(pg "profile.php")
t "el perfil carga" "$h" 'id="your-profile"'
if [[ "$h" != *"dos pasos"* && "$h" != *"otpauth"* ]]; then ok=$((ok+1)); echo "  OK    sin rastro de 2FA en el perfil"; else ko=$((ko+1)); echo "  FALLO queda 2FA en el perfil"; fi

docker exec nolantis-gestion-wp rm -f /tmp/adminjar.txt
helper reset >/dev/null; helper deluser >/dev/null
echo "RESULTADO E2E PANEL: $ok OK, $ko FALLOS"
