<?php
/**
 * PUNTO DE ENTRADA
 * Si hay sesión activa → dashboard por rol
 * Si no hay sesión     → landing page pública
 */
declare(strict_types=1);

require_once __DIR__ . '/php/includes/sesion.php';

if (!Sesion::estaAutenticado()) {
    header('Location: /html/index.html');
    exit;
}

$destinos = [
    5 => '/html/admin/dashboard.html',
    4 => '/html/propietario/dashboard.html',
    3 => '/html/gestor/dashboard.html',
    2 => '/html/usuario/dashboard.html',
    1 => '/html/usuario/dashboard.html',
];

header('Location: ' . ($destinos[Sesion::nivelRol()] ?? '/html/login.html'));
exit;
