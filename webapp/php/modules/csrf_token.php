<?php
/**
 * ENDPOINT: Generar tokens CSRF
 * Archivo: /php/modules/csrf_token.php
 * Codificación: UTF-8
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/sesion.php';

header('Content-Type: application/json; charset=utf-8');

$formulario = Sanitizar::texto($_GET['formulario'] ?? 'general');
$token      = CSRF::generar($formulario);

echo json_encode([
    'exito'       => true,
    'csrf_token'  => $token,
    'formulario'  => $formulario,
]);
