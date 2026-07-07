<?php
/**
 * MÓDULO ROLES (solo lectura)
 * Archivo: /php/modules/roles.php
 * Codificación: UTF-8
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/sesion.php';

header('Content-Type: application/json; charset=utf-8');

Sesion::requerirNivel(4);

$pdo   = Conexion::obtener();
$roles = $pdo->query(
    'SELECT r.*, COUNT(u.id_usuario) AS total_usuarios
     FROM `roles` r
     LEFT JOIN `usuarios` u ON u.id_rol = r.id_rol AND u.activo = 1
     GROUP BY r.id_rol
     ORDER BY r.nivel'
)->fetchAll();

responderJSON(true, 'OK', ['roles' => $roles]);
