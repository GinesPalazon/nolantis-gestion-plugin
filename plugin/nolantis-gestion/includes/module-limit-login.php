<?php
/**
 * Modulo Limit Login: proteccion contra ataques de fuerza bruta.
 *
 * - settings.php   Ajustes y valores por defecto.
 * - ip.php         Deteccion de la IP real (directa, Cloudflare o proxy de confianza) y rangos CIDR.
 * - store.php       Tablas propias, registro de intentos, bloqueos y purga diaria.
 * - accounts.php    Usuario atacado, IPs conocidas de cada usuario y usuarios tipicos de bots.
 * - protection.php  Contador de fallos, bloqueos por IP, modo protegido por cuenta y corte temprano.
 * - turnstile.php   CAPTCHA opcional con Cloudflare Turnstile.
 * - lostpassword.php Limite y respuesta generica de "¿Has olvidado tu contrasena?".
 * - hardening.php   XML-RPC, enumeracion de usuarios y mensajes de error genericos.
 * - alerts.php      Avisos por email.
 * - admin.php       Panel WP-Nolantis > Seguridad de acceso.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/limit-login/settings.php';
require_once __DIR__ . '/limit-login/ip.php';
require_once __DIR__ . '/limit-login/store.php';
require_once __DIR__ . '/limit-login/accounts.php';
require_once __DIR__ . '/limit-login/protection.php';
require_once __DIR__ . '/limit-login/turnstile.php';
require_once __DIR__ . '/limit-login/lostpassword.php';
require_once __DIR__ . '/limit-login/hardening.php';
require_once __DIR__ . '/limit-login/alerts.php';
require_once __DIR__ . '/limit-login/admin.php';
