<?php
/**
 * MÓDULO PERFIL
 * Archivo: /php/modules/perfil.php
 * Codificación: UTF-8
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/sesion.php';

header('Content-Type: application/json; charset=utf-8');

Sesion::requerirAutenticacion();

$accion = Sanitizar::texto(
    (json_decode(file_get_contents('php://input'), true) ?? $_POST)['accion'] ?? $_GET['accion'] ?? ''
);

match ($accion) {
    'obtener'          => obtenerPerfil(),
    'actualizar'       => actualizarPerfil(),
    'cambiar_contrasena' => cambiarContrasena(),
    default            => responderJSON(false, 'Acción no reconocida.', [], 400),
};

function obtenerPerfil(): never {
    $pdo  = Conexion::obtener();
    $stmt = $pdo->prepare(
        'SELECT u.id_usuario, u.nombre, u.apellido, u.correo, u.creado_en, u.ultimo_acceso,
                r.nombre AS nombre_rol, r.nivel
         FROM `usuarios` u JOIN `roles` r ON r.id_rol = u.id_rol
         WHERE u.id_usuario = :id LIMIT 1'
    );
    $stmt->execute([':id' => Sesion::idUsuario()]);
    $perfil = $stmt->fetch();
    if (!$perfil) responderJSON(false, 'Usuario no encontrado.', [], 404);
    responderJSON(true, 'OK', ['perfil' => $perfil]);
}

function actualizarPerfil(): never {
    $datos = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (!CSRF::verificar($datos['csrf_token'] ?? '', 'perfil')) {
        responderJSON(false, 'Token CSRF inválido.', [], 403);
    }

    $nombre   = Sanitizar::texto($datos['nombre']   ?? '');
    $apellido = Sanitizar::texto($datos['apellido'] ?? '');
    $errores  = [];

    if (strlen($nombre)   < 2) $errores['nombre']   = 'Nombre demasiado corto.';
    if (strlen($apellido) < 2) $errores['apellido'] = 'Apellido demasiado corto.';
    if (!empty($errores)) responderJSON(false, 'Errores de validación.', ['errores' => $errores], 422);

    Conexion::obtener()->prepare(
        'UPDATE `usuarios` SET `nombre`=:n, `apellido`=:a WHERE id_usuario=:id'
    )->execute([':n'=>$nombre, ':a'=>$apellido, ':id'=>Sesion::idUsuario()]);

    // Actualizar sesión
    $_SESSION['nombre']   = $nombre;
    $_SESSION['apellido'] = $apellido;

    Bitacora::registrar('actualizar_perfil', 'Perfil actualizado.');
    responderJSON(true, 'Perfil actualizado correctamente.');
}

function cambiarContrasena(): never {
    $datos   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (!CSRF::verificar($datos['csrf_token'] ?? '', 'cambiar_contra')) {
        responderJSON(false, 'Token CSRF inválido.', [], 403);
    }

    $actual   = $datos['contrasena_actual'] ?? '';
    $nueva    = $datos['contrasena_nueva']  ?? '';
    $confirmar = $datos['confirmar']        ?? '';

    $pdo  = Conexion::obtener();
    $stmt = $pdo->prepare('SELECT contrasena_hash FROM `usuarios` WHERE id_usuario=:id');
    $stmt->execute([':id' => Sesion::idUsuario()]);
    $hash = $stmt->fetchColumn();

    if (!Verificador_Contra($actual, $hash)) {
        responderJSON(false, 'La contraseña actual es incorrecta.', [], 401);
    }
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $nueva)) {
        responderJSON(false, 'La nueva contraseña no cumple los requisitos de seguridad.', [], 422);
    }
    if ($nueva !== $confirmar) {
        responderJSON(false, 'Las contraseñas no coinciden.', [], 422);
    }

    $pdo->prepare('UPDATE `usuarios` SET `contrasena_hash`=:h WHERE id_usuario=:id')
        ->execute([':h' => Contra_hash($nueva), ':id' => Sesion::idUsuario()]);

    Bitacora::registrar('cambiar_contrasena', 'Contraseña actualizada desde perfil.');
    responderJSON(true, 'Contraseña actualizada correctamente.');
}
