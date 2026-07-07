<?php
/**
 * MÓDULO DE GESTIÓN DE USUARIOS (Admin)
 * Archivo: /php/modules/usuarios.php
 * Codificación: UTF-8
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/sesion.php';

header('Content-Type: application/json; charset=utf-8');

Sesion::requerirNivel(4); // propietario+

$metodo = $_SERVER['REQUEST_METHOD'];
$accion = Sanitizar::texto($_GET['accion'] ?? '');

match ($metodo) {
    'GET'    => manejarLecturaUsuarios($accion),
    'POST'   => manejarCrearUsuario(),
    'PUT'    => manejarActualizarUsuario(),
    'DELETE' => manejarEliminarUsuario(),
    default  => responderJSON(false, 'Método no permitido.', [], 405),
};

// ─────────────────────────────────────────────────────────────────────────────

function manejarLecturaUsuarios(string $accion): never {
    match ($accion) {
        'listar'       => listarUsuarios(),
        'detalle'      => obtenerUsuario(Sanitizar::entero($_GET['id'] ?? 0)),
        'estadisticas' => obtenerEstadisticas(),
        'bitacora'     => obtenerBitacora(),
        default        => listarUsuarios(),
    };
}

function listarUsuarios(): never {
    $pdo    = Conexion::obtener();
    $pagina = max(1, Sanitizar::entero($_GET['pagina'] ?? 1));
    $limite = min(50, max(5, Sanitizar::entero($_GET['limite'] ?? 20)));
    $offset = ($pagina - 1) * $limite;
    $buscar = Sanitizar::texto($_GET['buscar'] ?? '');

    $cond   = [];
    $params = [];

    if ($buscar) {
        $cond[]           = '(u.nombre LIKE :b OR u.apellido LIKE :b OR u.correo LIKE :b)';
        $params[':b']     = "%$buscar%";
    }
    if (isset($_GET['id_rol'])) {
        $cond[]           = 'u.id_rol = :rol';
        $params[':rol']   = Sanitizar::entero($_GET['id_rol']);
    }

    $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';

    $total  = $pdo->prepare("SELECT COUNT(*) FROM `usuarios` u $where");
    $total->execute($params);
    $totalReg = (int)$total->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT u.id_usuario, u.nombre, u.apellido, u.correo, u.activo,
                u.ultimo_acceso, u.creado_en, r.nombre AS nombre_rol, r.nivel
         FROM `usuarios` u
         JOIN `roles` r ON r.id_rol = u.id_rol
         $where
         ORDER BY u.creado_en DESC
         LIMIT :lim OFFSET :off"
    );
    $params[':lim'] = $limite;
    $params[':off'] = $offset;
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();

    responderJSON(true, 'OK', [
        'usuarios'      => $usuarios,
        'total'         => $totalReg,
        'pagina'        => $pagina,
        'total_paginas' => (int)ceil($totalReg / $limite),
    ]);
}

function obtenerUsuario(int $id): never {
    if ($id <= 0) responderJSON(false, 'ID inválido.', [], 400);
    $pdo  = Conexion::obtener();
    $stmt = $pdo->prepare(
        'SELECT u.id_usuario, u.nombre, u.apellido, u.correo, u.activo, u.creado_en,
                u.ultimo_acceso, r.nombre AS nombre_rol, r.nivel, r.id_rol
         FROM `usuarios` u JOIN `roles` r ON r.id_rol = u.id_rol
         WHERE u.id_usuario = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $u = $stmt->fetch();
    if (!$u) responderJSON(false, 'Usuario no encontrado.', [], 404);
    responderJSON(true, 'OK', ['usuario' => $u]);
}

function obtenerEstadisticas(): never {
    $pdo  = Conexion::obtener();

    $stats = [];

    // Usuarios por rol
    $stats['por_rol'] = $pdo->query(
        'SELECT r.nombre AS rol, COUNT(*) AS total
         FROM `usuarios` u JOIN `roles` r ON r.id_rol = u.id_rol
         GROUP BY r.id_rol, r.nombre'
    )->fetchAll();

    // Registros últimos 30 días
    $stats['registros_mes'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM `usuarios` WHERE creado_en >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    )->fetchColumn();

    // Activos vs inactivos
    $stats['activos']   = (int)$pdo->query("SELECT COUNT(*) FROM `usuarios` WHERE activo = 1")->fetchColumn();
    $stats['inactivos'] = (int)$pdo->query("SELECT COUNT(*) FROM `usuarios` WHERE activo = 0")->fetchColumn();

    // Total productos
    $stats['total_productos'] = (int)$pdo->query("SELECT COUNT(*) FROM `productos` WHERE activo = 1")->fetchColumn();

    // Total pedidos
    $stats['total_pedidos'] = (int)$pdo->query("SELECT COUNT(*) FROM `pedidos`")->fetchColumn();

    responderJSON(true, 'OK', ['estadisticas' => $stats]);
}

function obtenerBitacora(): never {
    Sesion::requerirNivel(5); // solo admin
    $pdo    = Conexion::obtener();
    $limite = min(100, Sanitizar::entero($_GET['limite'] ?? 50));
    $pagina = max(1, Sanitizar::entero($_GET['pagina'] ?? 1));
    $offset = ($pagina - 1) * $limite;

    $filas = $pdo->prepare(
        'SELECT b.*, CONCAT(u.nombre, " ", u.apellido) AS nombre_usuario
         FROM `bitacora` b
         LEFT JOIN `usuarios` u ON u.id_usuario = b.id_usuario
         ORDER BY b.creado_en DESC
         LIMIT :lim OFFSET :off'
    );
    $filas->execute([':lim' => $limite, ':off' => $offset]);
    responderJSON(true, 'OK', ['registros' => $filas->fetchAll()]);
}

// ─────────────────────────────────────────────────────────────────────────────

function manejarCrearUsuario(): never {
    Sesion::requerirNivel(5); // solo admin

    $datos = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $csrf  = $datos['csrf_token'] ?? '';
    if (!CSRF::verificar($csrf, 'crear_usuario')) {
        responderJSON(false, 'Token CSRF inválido.', [], 403);
    }

    $nombre     = Sanitizar::texto($datos['nombre']     ?? '');
    $apellido   = Sanitizar::texto($datos['apellido']   ?? '');
    $correo     = Sanitizar::correo($datos['correo']    ?? '');
    $contrasena = $datos['contrasena'] ?? '';
    $idRol      = Sanitizar::entero($datos['id_rol']    ?? 2);

    $errores = [];
    if (strlen($nombre) < 2)   $errores['nombre']     = 'Nombre demasiado corto.';
    if (strlen($apellido) < 2) $errores['apellido']   = 'Apellido demasiado corto.';
    if (!$correo)              $errores['correo']     = 'Correo inválido.';
    if (strlen($contrasena) < 8) $errores['contrasena'] = 'Contraseña muy corta.';

    if (!empty($errores)) responderJSON(false, 'Errores de validación.', ['errores' => $errores], 422);

    $pdo = Conexion::obtener();
    $existe = $pdo->prepare('SELECT id_usuario FROM `usuarios` WHERE correo = :c LIMIT 1');
    $existe->execute([':c' => $correo]);
    if ($existe->fetch()) responderJSON(false, 'Correo ya registrado.', [], 409);

    $pdo->prepare(
        'INSERT INTO `usuarios` (`nombre`, `apellido`, `correo`, `contrasena_hash`, `id_rol`)
         VALUES (:n, :a, :c, :h, :r)'
    )->execute([
        ':n' => $nombre, ':a' => $apellido,
        ':c' => $correo, ':h' => Contra_hash($contrasena),
        ':r' => $idRol,
    ]);

    $id = (int)$pdo->lastInsertId();
    Bitacora::registrar('admin_crear_usuario', "Admin creó usuario #$id: $correo");
    responderJSON(true, 'Usuario creado.', ['id_usuario' => $id], 201);
}

// ─────────────────────────────────────────────────────────────────────────────

function manejarActualizarUsuario(): never {
    $datos  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id     = Sanitizar::entero($datos['id_usuario'] ?? 0);
    $csrf   = $datos['csrf_token'] ?? '';
    if ($id <= 0) responderJSON(false, 'ID inválido.', [], 400);
    if (!CSRF::verificar($csrf, 'editar_usuario')) {
        responderJSON(false, 'Token CSRF inválido.', [], 403);
    }

    // No puede cambiar su propio rol
    if ($id === Sesion::idUsuario() && isset($datos['id_rol'])) {
        responderJSON(false, 'No puede cambiar su propio rol.', [], 403);
    }

    $pdo    = Conexion::obtener();
    $campos = [];
    $params = [':id' => $id];

    if (isset($datos['nombre']))    { $campos[] = '`nombre` = :n';    $params[':n'] = Sanitizar::texto($datos['nombre']); }
    if (isset($datos['apellido']))  { $campos[] = '`apellido` = :a';  $params[':a'] = Sanitizar::texto($datos['apellido']); }
    if (isset($datos['activo']))    { $campos[] = '`activo` = :act';  $params[':act'] = (int)$datos['activo']; }
    if (isset($datos['id_rol']) && Sesion::nivelRol() >= 5) {
        $campos[] = '`id_rol` = :r'; $params[':r'] = Sanitizar::entero($datos['id_rol']);
    }

    if (empty($campos)) responderJSON(false, 'Nada que actualizar.', [], 400);

    $pdo->prepare('UPDATE `usuarios` SET ' . implode(', ', $campos) . ' WHERE id_usuario = :id')
        ->execute($params);

    Bitacora::registrar('admin_editar_usuario', "Usuario #$id actualizado.");
    responderJSON(true, 'Usuario actualizado.');
}

// ─────────────────────────────────────────────────────────────────────────────

function manejarEliminarUsuario(): never {
    Sesion::requerirNivel(5); // solo admin

    $datos = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = Sanitizar::entero($datos['id_usuario'] ?? 0);
    $csrf  = $datos['csrf_token'] ?? '';

    if ($id <= 0) responderJSON(false, 'ID inválido.', [], 400);
    if ($id === Sesion::idUsuario()) responderJSON(false, 'No puede eliminarse a sí mismo.', [], 403);
    if (!CSRF::verificar($csrf, 'eliminar_usuario')) {
        responderJSON(false, 'Token CSRF inválido.', [], 403);
    }

    $pdo = Conexion::obtener();
    $pdo->prepare('UPDATE `usuarios` SET `activo` = 0 WHERE id_usuario = :id')
        ->execute([':id' => $id]);

    Bitacora::registrar('admin_eliminar_usuario', "Usuario #$id desactivado.");
    responderJSON(true, 'Usuario desactivado correctamente.');
}
