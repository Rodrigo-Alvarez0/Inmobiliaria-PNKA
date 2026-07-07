<?php
/**
 * MÓDULO CRUD - PRODUCTOS
 * Archivo: /php/modules/productos.php
 * Codificación: UTF-8
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/sesion.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Sesion::requerirAutenticacion();

$metodo = $_SERVER['REQUEST_METHOD'];
$accion = Sanitizar::texto($_GET['accion'] ?? '');

match ($metodo) {
    'GET'    => manejarLectura($accion),
    'POST'   => manejarCrear(),
    'PUT'    => manejarActualizar(),
    'DELETE' => manejarEliminar(),
    default  => responderJSON(false, 'Método no permitido.', [], 405),
};

// ─────────────────────────────────────────────────────────────────────────────
// LECTURA
// ─────────────────────────────────────────────────────────────────────────────

function manejarLectura(string $accion): never {
    match ($accion) {
        'listar'     => listarProductos(),
        'detalle'    => obtenerProducto(Sanitizar::entero($_GET['id'] ?? 0)),
        'categorias' => listarCategorias(),
        default      => listarProductos(),
    };
}

function listarProductos(): never {
    try {
        $pdo    = Conexion::obtener();
        $pagina = max(1, Sanitizar::entero($_GET['pagina'] ?? 1));
        $limite = min(50, max(5, Sanitizar::entero($_GET['limite'] ?? 20)));
        $offset = ($pagina - 1) * $limite;
        $buscar = Sanitizar::texto($_GET['buscar'] ?? '');
        $catId  = Sanitizar::entero($_GET['categoria'] ?? 0);

        $condiciones = ['p.activo = 1'];
        $params      = [];

        if ($buscar) {
            $condiciones[] = '(p.nombre LIKE :buscar OR p.descripcion LIKE :buscar)';
            $params[':buscar'] = "%$buscar%";
        }
        if ($catId > 0) {
            $condiciones[] = 'p.id_categoria = :cat';
            $params[':cat'] = $catId;
        }

        // Solo gestores/admins ven productos inactivos
        if (Sesion::nivelRol() < 3) {
            $condiciones[] = 'p.activo = 1';
        } else {
            // Quitar la condición por defecto
            $condiciones = array_filter($condiciones, fn($c) => $c !== 'p.activo = 1');
            if (isset($_GET['activo'])) {
                $condiciones[] = 'p.activo = :activo';
                $params[':activo'] = Sanitizar::entero($_GET['activo']);
            }
        }

        $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

        $total = $pdo->prepare(
            "SELECT COUNT(*) FROM `productos` p $where"
        );
        $total->execute($params);
        $totalReg = (int)$total->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT p.*, c.nombre AS categoria_nombre,
                    CONCAT(u.nombre, ' ', u.apellido) AS creador_nombre
             FROM `productos` p
             LEFT JOIN `categorias` c ON c.id_categoria = p.id_categoria
             LEFT JOIN `usuarios`   u ON u.id_usuario   = p.id_creador
             $where
             ORDER BY p.creado_en DESC
             LIMIT :limite OFFSET :offset"
        );
        $params[':limite']  = $limite;
        $params[':offset']  = $offset;
        $stmt->execute($params);
        $productos = $stmt->fetchAll();

        responderJSON(true, 'OK', [
            'productos'    => $productos,
            'total'        => $totalReg,
            'pagina'       => $pagina,
            'limite'       => $limite,
            'total_paginas' => (int)ceil($totalReg / $limite),
        ]);

    } catch (PDOException $e) {
        error_log('[Productos Listar] ' . $e->getMessage());
        responderJSON(false, 'Error al obtener productos.', [], 500);
    }
}

function obtenerProducto(int $id): never {
    if ($id <= 0) responderJSON(false, 'ID inválido.', [], 400);
    try {
        $pdo  = Conexion::obtener();
        $stmt = $pdo->prepare(
            'SELECT p.*, c.nombre AS categoria_nombre
             FROM `productos` p
             LEFT JOIN `categorias` c ON c.id_categoria = p.id_categoria
             WHERE p.id_producto = :id'
        );
        $stmt->execute([':id' => $id]);
        $producto = $stmt->fetch();
        if (!$producto) responderJSON(false, 'Producto no encontrado.', [], 404);
        responderJSON(true, 'OK', ['producto' => $producto]);
    } catch (PDOException $e) {
        error_log('[Productos Detalle] ' . $e->getMessage());
        responderJSON(false, 'Error al obtener producto.', [], 500);
    }
}

function listarCategorias(): never {
    try {
        $pdo   = Conexion::obtener();
        $filas = $pdo->query('SELECT * FROM `categorias` WHERE activa = 1 ORDER BY nombre')->fetchAll();
        responderJSON(true, 'OK', ['categorias' => $filas]);
    } catch (PDOException $e) {
        error_log('[Categorías] ' . $e->getMessage());
        responderJSON(false, 'Error al obtener categorías.', [], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CREAR
// ─────────────────────────────────────────────────────────────────────────────

function manejarCrear(): never {
    Sesion::requerirNivel(3); // gestor+

    $datos = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $csrf = $datos['csrf_token'] ?? '';
    if (!CSRF::verificar($csrf, 'productos')) {
        responderJSON(false, 'Token de seguridad inválido.', [], 403);
    }

    [$errores, $campos] = validarCamposProducto($datos);
    if (!empty($errores)) {
        responderJSON(false, 'Corrija los errores indicados.', ['errores' => $errores], 422);
    }

    try {
        $pdo  = Conexion::obtener();
        $stmt = $pdo->prepare(
            'INSERT INTO `productos` (`nombre`, `descripcion`, `precio`, `stock`, `id_categoria`, `id_creador`)
             VALUES (:nombre, :desc, :precio, :stock, :cat, :creador)'
        );
        $stmt->execute([
            ':nombre'  => $campos['nombre'],
            ':desc'    => $campos['descripcion'],
            ':precio'  => $campos['precio'],
            ':stock'   => $campos['stock'],
            ':cat'     => $campos['id_categoria'] ?: null,
            ':creador' => Sesion::idUsuario(),
        ]);
        $idNuevo = (int)$pdo->lastInsertId();
        Bitacora::registrar('crear_producto', "Producto #$idNuevo creado: {$campos['nombre']}");
        responderJSON(true, 'Producto creado exitosamente.', ['id_producto' => $idNuevo], 201);
    } catch (PDOException $e) {
        error_log('[Crear Producto] ' . $e->getMessage());
        responderJSON(false, 'Error al crear el producto.', [], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTUALIZAR
// ─────────────────────────────────────────────────────────────────────────────

function manejarActualizar(): never {
    Sesion::requerirNivel(3);

    $datos = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = Sanitizar::entero($datos['id_producto'] ?? 0);
    if ($id <= 0) responderJSON(false, 'ID inválido.', [], 400);

    $csrf = $datos['csrf_token'] ?? '';
    if (!CSRF::verificar($csrf, 'productos')) {
        responderJSON(false, 'Token de seguridad inválido.', [], 403);
    }

    [$errores, $campos] = validarCamposProducto($datos);
    if (!empty($errores)) {
        responderJSON(false, 'Corrija los errores indicados.', ['errores' => $errores], 422);
    }

    try {
        $pdo  = Conexion::obtener();
        $stmt = $pdo->prepare(
            'UPDATE `productos`
             SET `nombre` = :nombre, `descripcion` = :desc,
                 `precio` = :precio, `stock` = :stock,
                 `id_categoria` = :cat, `activo` = :activo
             WHERE id_producto = :id'
        );
        $stmt->execute([
            ':nombre' => $campos['nombre'],
            ':desc'   => $campos['descripcion'],
            ':precio' => $campos['precio'],
            ':stock'  => $campos['stock'],
            ':cat'    => $campos['id_categoria'] ?: null,
            ':activo' => (int)($datos['activo'] ?? 1),
            ':id'     => $id,
        ]);
        Bitacora::registrar('actualizar_producto', "Producto #$id actualizado.");
        responderJSON(true, 'Producto actualizado exitosamente.');
    } catch (PDOException $e) {
        error_log('[Actualizar Producto] ' . $e->getMessage());
        responderJSON(false, 'Error al actualizar el producto.', [], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ELIMINAR
// ─────────────────────────────────────────────────────────────────────────────

function manejarEliminar(): never {
    Sesion::requerirNivel(4); // propietario+

    $datos = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = Sanitizar::entero($datos['id_producto'] ?? 0);
    $csrf  = $datos['csrf_token'] ?? '';

    if ($id <= 0) responderJSON(false, 'ID inválido.', [], 400);
    if (!CSRF::verificar($csrf, 'eliminar_producto')) {
        responderJSON(false, 'Token de seguridad inválido.', [], 403);
    }

    try {
        $pdo  = Conexion::obtener();
        // Soft delete
        $stmt = $pdo->prepare('UPDATE `productos` SET `activo` = 0 WHERE id_producto = :id');
        $stmt->execute([':id' => $id]);
        Bitacora::registrar('eliminar_producto', "Producto #$id desactivado.");
        responderJSON(true, 'Producto eliminado correctamente.');
    } catch (PDOException $e) {
        error_log('[Eliminar Producto] ' . $e->getMessage());
        responderJSON(false, 'Error al eliminar el producto.', [], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// VALIDACIÓN
// ─────────────────────────────────────────────────────────────────────────────

function validarCamposProducto(array $d): array {
    $errores = [];
    $campos  = [];

    $nombre = Sanitizar::texto($d['nombre'] ?? '');
    if (strlen($nombre) < 2 || strlen($nombre) > 200) {
        $errores['nombre'] = 'El nombre debe tener entre 2 y 200 caracteres.';
    }
    $campos['nombre'] = $nombre;

    $campos['descripcion'] = Sanitizar::texto($d['descripcion'] ?? '');

    $precio = Sanitizar::decimal($d['precio'] ?? 0);
    if ($precio < 0) $errores['precio'] = 'El precio no puede ser negativo.';
    $campos['precio'] = $precio;

    $stock = Sanitizar::entero($d['stock'] ?? 0);
    if ($stock < 0) $errores['stock'] = 'El stock no puede ser negativo.';
    $campos['stock'] = $stock;

    $campos['id_categoria'] = Sanitizar::entero($d['id_categoria'] ?? 0);

    return [$errores, $campos];
}
