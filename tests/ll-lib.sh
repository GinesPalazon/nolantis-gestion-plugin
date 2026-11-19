#!/usr/bin/env bash
# Funciones de prueba. Todas las peticiones salen desde DENTRO del contenedor (IP 127.0.0.1),
# para no bloquear la IP del navegador del usuario (172.22.0.1).
SP="$(dirname "${BASH_SOURCE[0]}")"
W="docker exec -i nolantis-gestion-wp"
H=(-H 'Host: localhost:8090')

helper() { $W php /dev/stdin "$@" < "$SP/ll-helper.php" 2>&1; }

# login <usuario> <password> [cabeceras curl extra...]
login() {
    local user="$1" pass="$2"; shift 2
    $W curl -s -i "${H[@]}" "$@" \
        --data-urlencode "log=$user" --data-urlencode "pwd=$pass" --data-urlencode "wp-submit=Acceder" \
        http://localhost/probandoruta1/ > "$SP/last.html"
    local code cookie msg
    code=$(head -1 "$SP/last.html" | awk '{print $2}')
    cookie=$(grep -ci '^set-cookie: wordpress_logged_in' "$SP/last.html")
    msg=$(tr '\n' ' ' < "$SP/last.html" | grep -o '<div id="login_error"[^>]*>.*</div>' | sed -E 's/<[^>]+>/ /g; s/ +/ /g' | cut -c1-230)
    echo "HTTP $code logged_in_cookie=$cookie | ${msg:-<sin error>}"
}
