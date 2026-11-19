#!/usr/bin/env bash
# Ejecuta todas las baterias de pruebas contra el WordPress local de Docker (ver tests/README.md).
# Las pruebas hacen copia de los ajustes del plugin y los restauran al terminar.
set -u
T="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
START=$(date -u +%Y-%m-%dT%H:%M:%SZ)

if ! docker ps --format '{{.Names}}' | grep -q '^nolantis-gestion-wp$'; then
    echo "El contenedor nolantis-gestion-wp no esta en marcha: ejecuta 'docker compose up -d'." >&2
    exit 1
fi

# Servidor SMTP de pruebas (solo dentro del contenedor, se apaga solo a los 15 minutos).
docker exec -i nolantis-gestion-wp sh -c 'cat > /tmp/fake-smtp.php' < "$T/fake-smtp.php"
docker exec -d nolantis-gestion-wp php /tmp/fake-smtp.php 2525 envios@example.com 'Clave Correcta 1'
docker exec -d nolantis-gestion-wp php /tmp/fake-smtp.php 2526 envios@example.com 'Clave Correcta 1' tls
sleep 2

run() { echo "### $1"; bash "$T/$2" 2>&1 | grep -E "FALLO|RESULTADO"; }

run "Limit Login (fase 1)"                  ll-tests.sh
run "Proteccion avanzada (fase 2)"          ll-tests2.sh
run "Panel de seguridad"                    ll-e2e-panel.sh
run "Auditoria: ruta oculta y modulos"      run-audit.sh
run "SMTP y comentarios"                    ll-smtp-tests.sh
run "Endurecimiento"                        ll-hardening-tests.sh
run "Cloudflare e integridad"               ll-integrity-cf-tests.sh
run "Actualizacion desde 1.0.17 y desinstalacion" ll-upgrade-uninstall-tests.sh
echo "### Actualizacion segura"
docker exec -i -u www-data nolantis-gestion-wp php /dev/stdin < "$T/ll-autoupdate-tests.php" 2>&1 | grep -E "FALLO|RESULTADO"

docker exec nolantis-gestion-wp pkill -f /tmp/fake-smtp.php
docker exec nolantis-gestion-wp rm -f /tmp/fake-smtp.php /tmp/fake-smtp-2525.pem /tmp/fake-smtp-2526.pem /tmp/fake-smtp-received.log

echo "### Avisos de PHP durante las pruebas"
docker logs --since "$START" nolantis-gestion-wp 2>&1 | grep -E "PHP (Warning|Notice|Fatal|Deprecated)" | grep -v "/proc/" | head -10
echo "(fin)"
