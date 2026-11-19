=== WP-Gestion-integral Nolantis ===
Contributors: nolantis
Tags: seguridad, login, smtp, fuerza bruta, mantenimiento
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later

Gestión y seguridad para las webs gestionadas por Nolantis.

== Description ==

* Protección contra ataques de fuerza bruta: bloqueo progresivo por IP, protección de cuentas atacadas desde muchas IPs, bloqueo inmediato de usuarios típicos de bots, CAPTCHA opcional (Cloudflare Turnstile) y avisos por email.
* Ruta de acceso personalizada: oculta wp-login.php y wp-admin.
* Envío de correo por SMTP con registro de envíos y explicación de los errores.
* Comprobación diaria de la integridad de los archivos de WordPress.
* Endurecimiento: editor de archivos, versiones, cabeceras de seguridad y bloqueo de PHP en uploads.
* Cierre de comentarios y pantalla de acceso personalizada.

== Changelog ==

= 2.0.1 =
* Tope de tamaño para el registro de intentos de acceso (100.000 filas) y el historial de bloqueos (50.000): ante ataques enormes las tablas ya no crecen sin límite entre limpiezas diarias. Los bloqueos activos nunca se borran.

= 2.0.0 =
* Nuevo nombre: WP-Gestion-integral Nolantis. Menú del panel: WP-Nolantis.
* IMPORTANTE: corregida la "Ruta acceso admin", que en webs con enlaces permanentes impedía iniciar sesión y revelaba la ruta secreta en /login y /wp-signup.php. Ahora funciona en Apache, LiteSpeed y Nginx, y se comprueba antes de activarla.
* Limit Login rehecho: detección correcta de la IP (Cloudflare automático, proxies, IPv6), bloqueos progresivos, panel con IPs bloqueadas y registro, lista blanca y acceso de emergencia.
* Protección de cuentas frente a botnets, bloqueo de usuarios típicos de bots y límite en "¿Has olvidado tu contraseña?".
* XML-RPC desactivado por defecto (con casilla para activarlo) y bloqueo de la enumeración de usuarios.
* CAPTCHA opcional con Cloudflare Turnstile y avisos por email (desactivados por defecto).
* SMTP: registro de envíos con el motivo de cada fallo, prueba de conexión, proveedores predefinidos, tiempo de espera de 15 s y contraseña cifrada con comprobación de integridad.
* Comentarios: se pueden cerrar también en páginas y adjuntos, y ocultar del panel.
* Nuevo: comprobación diaria de los archivos de WordPress (archivos modificados y PHP sospechosos).
* Nuevo: endurecimiento (editor de archivos, versiones, cabeceras de seguridad, PHP en uploads). Todo desactivado por defecto.
* Rangos de Cloudflare actualizados automáticamente cada semana.
* El plugin se actualiza solo, únicamente desde su repositorio oficial y verificando la huella SHA-256 de cada versión.
* Pantalla de acceso con el nuevo color y corregido el contraste de la verificación del correo de administración.
* Al borrar el plugin se eliminan todos sus datos.
