# Pruebas del plugin

Baterías de pruebas automáticas que se ejecutan contra el WordPress local de Docker
(`docker-compose.yml`). **No van dentro del ZIP del plugin.**

## Requisitos

- El entorno de Docker en marcha: `docker compose up -d`.
- El plugin activo en ese WordPress, con la **ruta de acceso personalizada activada con el slug
  `probandoruta1`** (las pruebas entran por `http://localhost:8090/probandoruta1/`).
- Conexión a Internet desde el contenedor (se prueban la API de Cloudflare, las sumas de
  WordPress.org y las releases de GitHub).

## Ejecutar

```bash
bash tests/run-all.sh
```

Cada batería termina con `RESULTADO: N OK, 0 FALLOS`. Al final se listan los avisos de PHP que
hayan aparecido durante las pruebas (debe quedar vacío).

Las pruebas crean usuarios, contenidos y ajustes temporales y **lo restauran todo al terminar**.
También instalan temporalmente un plugin de apoyo (`zz-nolantis-audit.php`) y un servidor SMTP de
pruebas (`fake-smtp.php`) **solo dentro del contenedor**, y los quitan después.

## Baterías

| Archivo | Qué prueba |
|---|---|
| `ll-tests.sh` | Bloqueo por IP: fallos, escalado, desbloqueo, IPv6, lista blanca, XML-RPC, REST, detección de IP, enumeración de usuarios |
| `ll-tests2.sh` | Protección de cuentas (botnets), usuarios de bots, recuperación de contraseña, Turnstile, avisos por email |
| `ll-e2e-panel.sh` | Panel de seguridad como administrador: guardar pestañas, revisión de seguridad |
| `run-audit.sh` + `ll-audit.sh` | Ruta de acceso oculta (con enlaces permanentes), pantalla de acceso, SMTP, comentarios, actualizador |
| `ll-smtp-tests.sh` | SMTP real contra el servidor de pruebas: envíos, errores explicados, registro, panel; comentarios |
| `ll-hardening-tests.sh` | Endurecimiento: cabeceras, versiones, editor de archivos, PHP en uploads |
| `ll-integrity-cf-tests.sh` | Rangos de Cloudflare actualizados y comprobación de integridad (simula un hackeo y lo restaura) |
| `ll-autoupdate-tests.php` | Actualización automática segura (sin instalar nada) |
| `ll-upgrade-uninstall-tests.sh` | Actualización desde la 1.0.17 (ruta oculta mal colocada) y desinstalación completa (sin borrar la carpeta del plugin; restaura los ajustes) |

## Rendimiento

`bash tests/perf-measure.sh` mide, en varias páginas, las consultas a la base de datos, el tiempo y la
memoria con y sin el plugin, y lista las consultas que hace el plugin. En las visitas a la web debe
salir **0** en "del plugin".

## Archivos de apoyo

- `ll-lib.sh`, `ll-helper.php`: funciones comunes.
- `fake-smtp.php`: servidor SMTP mínimo para pruebas.
- `zz-nolantis-audit.php`: plugin de apoyo temporal (peticiones de la web a sí misma dentro de
  Docker, captura de emails, constante de emergencia).

## Notas del entorno

- Dentro del contenedor, `localhost:8090` no existe (es el puerto del ordenador): el plugin de apoyo
  redirige esas peticiones a `localhost:80`.
- La imagen de WordPress acepta `X-Forwarded-For` desde IPs internas; las pruebas lo aprovechan
  para simular atacantes con IPs públicas.
