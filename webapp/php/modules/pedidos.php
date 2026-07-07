<?php
/**
 * MÓDULO CRUD - PEDIDOS
 * Archivo: /php/modules/pedidos.php
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
    'GET'  => manejarLectura($accion),
    'POST' => manejarCrear(),
    'PUT'  => manejarActualizar(),
    default => responderJSON(false, 'Método no permitido.', [], 405),
};

// ─────────────────────────────────────────────────────────────────────────────
function manejarLectura(string $accion): never {
    match ($accion) {
        'detalle' => obtenerPedido(Sanitizar::entero($_GET['id'] ?? 0)),
        default   => listarPedidos(),
    };
}

function listarPedidos(): never {
    $pdo    = Conexion::obtener();
    $pagina = max(1, Sanitizar::entero($_GET['pagina'] ?? 1));
    $limite = min(50, max(5, Sanitizar::entero($_GET['limite'] ?? 20)));
    $offset = ($pagina - 1) * $limite;
    $estado = Sanitizar::texto($_GET['estado'] ?? '');

    $cond   = [];
    $params = [];

    // Usuarios solo ven sus propios pedidos
    if (Sesion::nivelRol() < 3) {
        $cond[]           = 'p.id_usuario = :uid';
        $params[':uid']   = Sesion::idUsuario();
    }
    if ($estado) {
        $cond[]           = 'p.estado = :estado';
        $params[':estado'] = $estado;
    }

    $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';

    $total = $pdo->prepare("SELECT COUNT(*) FROM `pedidos` p $where");
    $total->execute($params);
    $totalReg = (int)$total->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT p.*, CONCAT(u.nombre,' ',u.apellido) AS cliente_nombre, u.correo AS cliente_correo
         FROM `pedidos` p
         JOIN `usuarios` u ON u.id_usuario = p.id_usuario
         $where
         ORDER BY p.creado_en DESC
         LIMIT :lim OFFSET :off"
    );
    $params[':lim'] = $limite;
    $params[':off'] = $offset;
    $stmt->execute($params);

    responderJSON(true, 'OK', [
        'pedidos'       => $stmt->fetchAll(),
        'total'         => $totalReg,
        'pagina'        => $pagina,
        'total_paginas' => (int)ceil($totalReg / $limite),
    ]);
}

function obtenerPedido(int $id): never {
    if ($id <= 0) responderJSON(false, 'ID inválido.', [], 400);

    $pdo  = Conexion::obtener();
    $cond = Sesion::nivelRol() < 3 ? 'AND p.id_usuario = ' . Sesion::idUsuario() : '';

    $stmt = $pdo->prepare(
        "SELECT p.*, CONCAT(u.nombre,' ',u.apellido) AS cliente_nombre
         FROM `pedidos` p JOIN `usuarios` u ON u.id_usuario = p.id_usuario
         WHERE p.id_pedido = :id $cond LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $pedido = $stmt->fetch();
    if (!$pedido) responderJSON(false, 'Pedido no encontrado.', [], 404);

    // Detalle de ítems
    $items = $pdo->prepare(
        'SELECT d.*, p.nombre AS producto_nombre
         FROM `detalle_pedido` d
         JOIN `productos` p ON p.id_producto = d.id_producto
         WHERE d.id_pedido = :id'
    );
    $items->execute([':id' => $id]);

    responderJSON(true, 'OK', ['pedido' => $pedido, 'items' => $items->fetchAll()]);
}

function manejarCrear(): never {
    $datos = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $csrf  = $datos['csrf_token'] ?? '';
    if (!CSRF::verificar($csrf, 'pedidos')) responderJSON(false, 'Token CSRF inválido.', [], 403);

    $notas = Sanitizar::texto($datos['notas'] ?? '');
    $items = $datos['items'] ?? [];

    if (empty($items)) responderJSON(false, 'El pedido debe tener al menos un producto.', [], 422);

    $pdo = Conexion::obtener();
    $pdo->beginTransaction();

    try {
        $total = 0;
        $lineas = [];
        foreach ($items as $item) {
            $idProd   = Sanitizar::entero($item['id_producto'] ?? 0);
            $cantidad = Sanitizar::entero($item['cantidad'] ?? 0);
            if ($idProd <= 0 || $cantidad <= 0) continue;

            $prod = $pdo->prepare('SELECT precio, stock FROM `productos` WHERE id_producto = :id AND activo = 1');
            $prod->execute([':id' => $idProd]);
            $producto = $prod->fetch();
            if (!$producto) continue;
            if ($producto['stock'] < $cantidad) {
                $pdo->rollBack();
                responderJSON(false, "Stock insuficiente para el producto #$idProd.", [], 422);
            }

            $subtotal = round((float)$producto['precio'] * $cantidad, 2);
            $total   += $subtotal;
            $lineas[] = ['id' => $idProd, 'cantidad' => $cantidad, 'precio' => $producto['precio'], 'subtotal' => $subtotal];
        }

        $ins = $pdo->prepare('INSERT INTO `pedidos` (`id_usuario`,`total`,`notas`) VALUES (:uid,:total,:notas)');
        $ins->execute([':uid' => Sesion::idUsuario(), ':total' => $total, ':notas' => $notas]);
        $idPedido = (int)$pdo->lastInsertId();

        $insItem = $pdo->prepare(
            'INSERT INTO `detalle_pedido` (`id_pedido`,`id_producto`,`cantidad`,`precio_unidad`,`subtotal`)
             VALUES (:ped,:prod,:cant,:precio,:sub)'
        );
        foreach ($lineas as $l) {
            $insItem->execute([':ped'=>$idPedido,':prod'=>$l['id'],':cant'=>$l['cantidad'],':precio'=>$l['precio'],':sub'=>$l['subtotal']]);
            $pdo->prepare('UPDATE `productos` SET `stock` = `stock` - :c WHERE id_producto = :id')
                ->execute([':c'=>$l['cantidad'],':id'=>$l['id']]);
        }

        $pdo->commit();
        Bitacora::registrar('crear_pedido', "Pedido #$idPedido creado. Total: $total");
        responderJSON(true, 'Pedido creado correctamente.', ['id_pedido' => $idPedido], 201);

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[Pedido Error] ' . $e->getMessage());
        responderJSON(false, 'Error al crear el pedido.', [], 500);
    }
}

function manejarActualizar(): never {
    Sesion::requerirNivel(3);

    $datos  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id     = Sanitizar::entero($datos['id_pedido'] ?? 0);
    $csrf   = $datos['csrf_token'] ?? '';
    $estado = Sanitizar::texto($datos['estado'] ?? '');

    if ($id <= 0) responderJSON(false, 'ID inválido.', [], 400);
    if (!CSRF::verificar($csrf, 'pedidos')) responderJSON(false, 'Token CSRF inválido.', [], 403);

    $estadosValidos = ['pendiente','confirmado','enviado','entregado','cancelado'];
    if (!in_array($estado, $estadosValidos, true)) {
        responderJSON(false, 'Estado inválido.', [], 422);
    }

    $pdo = Conexion::obtener();
    $pdo->prepare('UPDATE `pedidos` SET `estado` = :estado WHERE id_pedido = :id')
        ->execute([':estado' => $estado, ':id' => $id]);

    Bitacora::registrar('actualizar_pedido', "Pedido #$id → $estado");
    responderJSON(true, 'Estado del pedido actualizado.');
}
