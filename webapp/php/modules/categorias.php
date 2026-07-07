<?php
/**
 * MÓDULO CRUD - CATEGORÍAS
 * Archivo: /php/modules/categorias.php
 * Codificación: UTF-8
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/sesion.php';

header('Content-Type: application/json; charset=utf-8');

Sesion::requerirAutenticacion();

$metodo = $_SERVER['REQUEST_METHOD'];

match ($metodo) {
    'GET'    => listarCategorias(),
    'POST'   => manejarCrear(),
    'PUT'    => manejarActualizar(),
    'DELETE' => manejarEliminar(),
    default  => responderJSON(false, 'Método no permitido.', [], 405),
};

function listarCategorias(): never {
    $pdo  = Conexion::obtener();
    $solo = Sanitizar::entero($_GET['activas'] ?? 0);
    $cond = $solo ? 'WHERE activa = 1' : '';

    $filas = $pdo->query(
        "SELECT c.*, COUNT(p.id_producto) AS total_productos
         FROM `categorias` c
         LEFT JOIN `productos` p ON p.id_categoria = c.id_categoria AND p.activo = 1
         $cond
         GROUP BY c.id_categoria
         ORDER BY c.nombre"
    )->fetchAll();

    responderJSON(true, 'OK', ['categorias' => $filas]);
}

function manejarCrear(): never {
    Sesion::requerirNivel(3);
    $datos = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (!CSRF::verificar($datos['csrf_token'] ?? '', 'categorias')) {
        responderJSON(false, 'Token CSRF inválido.', [], 403);
    }

    $nombre = Sanitizar::texto($datos['nombre'] ?? '');
    $desc   = Sanitizar::texto($datos['descripcion'] ?? '');

    if (strlen($nombre) < 2) responderJSON(false, 'El nombre debe tener al menos 2 caracteres.', [], 422);

    $pdo = Conexion::obtener();
    $existe = $pdo->prepare('SELECT id_categoria FROM `categorias` WHERE nombre = :n LIMIT 1');
    $existe->execute([':n' => $nombre]);
    if ($existe->fetch()) responderJSON(false, 'Ya existe una categoría con ese nombre.', [], 409);

    $pdo->prepare('INSERT INTO `categorias` (`nombre`,`descripcion`) VALUES (:n,:d)')
        ->execute([':n' => $nombre, ':d' => $desc]);

    $id = (int)$pdo->lastInsertId();
    Bitacora::registrar('crear_categoria', "Categoría #$id: $nombre");
    responderJSON(true, 'Categoría creada.', ['id_categoria' => $id], 201);
}

function manejarActualizar(): never {
    Sesion::requerirNivel(3);
    $datos  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id     = Sanitizar::entero($datos['id_categoria'] ?? 0);
    $nombre = Sanitizar::texto($datos['nombre'] ?? '');
    $desc   = Sanitizar::texto($datos['descripcion'] ?? '');
    $activa = (int)($datos['activa'] ?? 1);

    if ($id <= 0 || strlen($nombre) < 2) responderJSON(false, 'Datos inválidos.', [], 422);
    if (!CSRF::verificar($datos['csrf_token'] ?? '', 'categorias')) {
        responderJSON(false, 'Token CSRF inválido.', [], 403);
    }

    Conexion::obtener()->prepare(
        'UPDATE `categorias` SET `nombre`=:n,`descripcion`=:d,`activa`=:a WHERE id_categoria=:id'
    )->execute([':n'=>$nombre,':d'=>$desc,':a'=>$activa,':id'=>$id]);

    Bitacora::registrar('actualizar_categoria', "Categoría #$id actualizada.");
    responderJSON(true, 'Categoría actualizada.');
}

function manejarEliminar(): never {
    Sesion::requerirNivel(4);
    $datos = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = Sanitizar::entero($datos['id_categoria'] ?? 0);
    if ($id <= 0) responderJSON(false, 'ID inválido.', [], 400);
    if (!CSRF::verificar($datos['csrf_token'] ?? '', 'categorias')) {
        responderJSON(false, 'Token CSRF inválido.', [], 403);
    }

    // Verificar que no tenga productos activos
    $tiene = (int)Conexion::obtener()->prepare(
        'SELECT COUNT(*) FROM `productos` WHERE id_categoria=:id AND activo=1'
    )->execute([':id'=>$id]) ? Conexion::obtener()->query("SELECT COUNT(*) FROM `productos` WHERE id_categoria=$id AND activo=1")->fetchColumn() : 0;

    if ($tiene > 0) responderJSON(false, "No se puede eliminar: tiene $tiene producto(s) activo(s).", [], 409);

    Conexion::obtener()->prepare('UPDATE `categorias` SET `activa`=0 WHERE id_categoria=:id')
        ->execute([':id'=>$id]);
    Bitacora::registrar('eliminar_categoria', "Categoría #$id desactivada.");
    responderJSON(true, 'Categoría eliminada.');
}
