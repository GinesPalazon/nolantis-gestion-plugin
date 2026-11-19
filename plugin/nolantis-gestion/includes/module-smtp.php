<?php
/**
 * Modulo SMTP: envio de los correos de WordPress por un servidor SMTP.
 *
 * - smtp/settings.php  Ajustes, contrasena cifrada y proveedores predefinidos.
 * - smtp/mailer.php    Configuracion de PHPMailer (tiempo de espera, remitente, "Responder a").
 * - smtp/log.php       Registro de envios y explicacion de los errores.
 * - smtp/admin.php     Panel WP-Nolantis > SMTP: ajustes, pruebas y registro.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/smtp/settings.php';
require_once __DIR__ . '/smtp/mailer.php';
require_once __DIR__ . '/smtp/log.php';
require_once __DIR__ . '/smtp/admin.php';
