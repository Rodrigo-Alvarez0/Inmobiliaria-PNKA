<?php
/**
 * MÓDULO DE AUTENTICACIÓN
 * Archivo: /php/modules/autenticacion.php
 * Codificación: UTF-8
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/sesion.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJSON(false, 'Método no permitido.', [], 405);
}

$entrada = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$accion  = Sanitizar::texto($entrada['accion'] ?? '');

match ($accion) {
    'login'           => manejarLogin($entrada),
    'registro'        => manejarRegistro($entrada),
    'cerrar_sesion'   => manejarCerrarSesion(),
    'sesion_actual'   => responderJSON(true, 'OK', Sesion::datosPublicos()),
    'recuperar'       => manejarRecuperar($entrada),
    'cambiar_contrasena' => manejarCambiarContrasena($entrada),
    default           => responderJSON(false, 'Acción no reconocida.', [], 400),
};

// ─────────────────────────────────────────────────────────────────────────────
// HANDLERS
// ─────────────────────────────────────────────────────────────────────────────

function manejarLogin(array $datos): never {
    // Verificar CSRF
    $csrf = $datos['csrf_token'] ?? '';
    if (!CSRF::verificar($csrf, 'login')) {
        responderJSON(false, 'Token de seguridad inválido. Recargue la página.', [], 403);
    }

    $correo     = Sanitizar::correo($datos['correo'] ?? '');
    $contrasena = $datos['contrasena'] ?? '';

    if (!$correo) responderJSON(false, 'Correo electrónico inválido.', [], 422);
    if (strlen($contrasena) < 8) responderJSON(false, 'Contraseña muy corta.', [], 422);

    try {
        $pdo  = Conexion::obtener();
        $stmt = $pdo->prepare(
            'SELECT u.*, r.nombre AS nombre_rol, r.nivel
             FROM `usuarios` u
             JOIN `roles` r ON r.id_rol = u.id_rol
             WHERE u.correo = :correo AND u.activo = 1
             LIMIT 1'
        );
        $stmt->execute([':correo' => $correo]);
        $usuario = $stmt->fetch();

        if (!$usuario || !Verificador_Contra($contrasena, $usuario['contrasena_hash'])) {
            Bitacora::registrar('login_fallido', "Intento fallido: $correo");
            responderJSON(false, 'Correo o contraseña incorrectos.', [], 401);
        }

        // Actualizar último acceso
        $pdo->prepare('UPDATE `usuarios` SET `ultimo_acceso` = NOW() WHERE `id_usuario` = :id')
            ->execute([':id' => $usuario['id_usuario']]);

        Sesion::iniciar($usuario);
        Bitacora::registrar('login_exitoso', "Usuario #{$usuario['id_usuario']} ingresó.");

        responderJSON(true, 'Bienvenido, ' . $usuario['nombre'] . '.', [
            'redirigir' => obtenerRedireccion((int)$usuario['nivel']),
        ]);

    } catch (PDOException $e) {
        error_log('[Login Error] ' . $e->getMessage());
        responderJSON(false, 'Error interno. Intente más tarde.', [], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────

function manejarRegistro(array $datos): never {
    $csrf = $datos['csrf_token'] ?? '';
    if (!CSRF::verificar($csrf, 'registro')) {
        responderJSON(false, 'Token de seguridad inválido.', [], 403);
    }

    $nombre     = Sanitizar::texto($datos['nombre']     ?? '');
    $apellido   = Sanitizar::texto($datos['apellido']   ?? '');
    $correo     = Sanitizar::correo($datos['correo']    ?? '');
    $contrasena = $datos['contrasena']  ?? '';
    $confirmar  = $datos['confirmar']   ?? '';

    // Validaciones
    $errores = [];
    if (strlen($nombre)   < 2)  $errores['nombre']     = 'El nombre debe tener al menos 2 caracteres.';
    if (strlen($apellido) < 2)  $errores['apellido']   = 'El apellido debe tener al menos 2 caracteres.';
    if (!$correo)               $errores['correo']     = 'Correo electrónico inválido.';
    if (!validarContrasena($contrasena)) {
        $errores['contrasena'] = 'La contraseña debe tener al menos 8 caracteres, una mayúscula, una minúscula, un número y un símbolo.';
    }
    if ($contrasena !== $confirmar) $errores['confirmar'] = 'Las contraseñas no coinciden.';

    if (!empty($errores)) {
        responderJSON(false, 'Por favor corrija los errores del formulario.', ['errores' => $errores], 422);
    }

    try {
        $pdo = Conexion::obtener();

        // Verificar correo único
        $existe = $pdo->prepare('SELECT id_usuario FROM `usuarios` WHERE correo = :correo LIMIT 1');
        $existe->execute([':correo' => $correo]);
        if ($existe->fetch()) {
            responderJSON(false, 'Este correo ya está registrado.', [], 409);
        }

        $hash = Contra_hash($contrasena);
        $ins  = $pdo->prepare(
            'INSERT INTO `usuarios` (`nombre`, `apellido`, `correo`, `contrasena_hash`, `id_rol`)
             VALUES (:nombre, :apellido, :correo, :hash, 2)'
        );
        $ins->execute([
            ':nombre'   => $nombre,
            ':apellido' => $apellido,
            ':correo'   => $correo,
            ':hash'     => $hash,
        ]);

        $idNuevo = (int)$pdo->lastInsertId();
        Bitacora::registrar('registro_usuario', "Nuevo usuario #$idNuevo: $correo");

        responderJSON(true, 'Cuenta creada exitosamente. Ya puede iniciar sesión.', [
            'redirigir' => '/html/login.html',
        ]);

    } catch (PDOException $e) {
        error_log('[Registro Error] ' . $e->getMessage());
        responderJSON(false, 'Error al crear la cuenta. Intente más tarde.', [], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────

function manejarCerrarSesion(): never {
    $idUsuario = Sesion::idUsuario();
    Bitacora::registrar('cerrar_sesion', "Usuario #$idUsuario cerró sesión.");
    Sesion::destruir();
    responderJSON(true, 'Sesión cerrada correctamente.', ['redirigir' => '/html/login.html']);
}

// ─────────────────────────────────────────────────────────────────────────────

function manejarRecuperar(array $datos): never {
    $csrf   = $datos['csrf_token'] ?? '';
    if (!CSRF::verificar($csrf, 'recuperar')) {
        responderJSON(false, 'Token de seguridad inválido.', [], 403);
    }

    $correo = Sanitizar::correo($datos['correo'] ?? '');
    if (!$correo) responderJSON(false, 'Correo inválido.', [], 422);

    try {
        $pdo  = Conexion::obtener();
        $stmt = $pdo->prepare('SELECT id_usuario FROM `usuarios` WHERE correo = :correo AND activo = 1 LIMIT 1');
        $stmt->execute([':correo' => $correo]);
        $usuario = $stmt->fetch();

        // No revelar si el correo existe (seguridad)
        if ($usuario) {
            $token  = bin2hex(random_bytes(32));
            $expira = date('Y-m-d H:i:s', strtotime('+' . TOKEN_RECUPERA_HORAS . ' hours'));
            $pdo->prepare(
                'UPDATE `usuarios` SET `token_recupera` = :token, `token_expira` = :expira
                 WHERE id_usuario = :id'
            )->execute([':token' => $token, ':expira' => $expira, ':id' => $usuario['id_usuario']]);

            // En producción: enviar por correo. Aquí simulamos.
            Bitacora::registrar('recuperar_solicitud', "Solicitud para: $correo");
            error_log("[Recuperar] Token para $correo: $token"); // solo dev
        }

        responderJSON(true, 'Si su correo está registrado, recibirá instrucciones en breve.');

    } catch (PDOException $e) {
        error_log('[Recuperar Error] ' . $e->getMessage());
        responderJSON(false, 'Error interno.', [], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────

function manejarCambiarContrasena(array $datos): never {
    $token      = Sanitizar::texto($datos['token']       ?? '');
    $contrasena = $datos['contrasena'] ?? '';
    $confirmar  = $datos['confirmar']  ?? '';

    if (!$token) responderJSON(false, 'Token inválido.', [], 400);
    if (!validarContrasena($contrasena)) {
        responderJSON(false, 'La contraseña no cumple los requisitos de seguridad.', [], 422);
    }
    if ($contrasena !== $confirmar) {
        responderJSON(false, 'Las contraseñas no coinciden.', [], 422);
    }

    try {
        $pdo  = Conexion::obtener();
        $stmt = $pdo->prepare(
            'SELECT id_usuario FROM `usuarios`
             WHERE token_recupera = :token AND token_expira > NOW() AND activo = 1 LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $usuario = $stmt->fetch();

        if (!$usuario) responderJSON(false, 'El enlace de recuperación es inválido o ha expirado.', [], 400);

        $hash = Contra_hash($contrasena);
        $pdo->prepare(
            'UPDATE `usuarios` SET `contrasena_hash` = :hash, `token_recupera` = NULL, `token_expira` = NULL
             WHERE id_usuario = :id'
        )->execute([':hash' => $hash, ':id' => $usuario['id_usuario']]);

        Bitacora::registrar('cambiar_contrasena', "Contraseña cambiada para usuario #{$usuario['id_usuario']}");
        responderJSON(true, 'Contraseña actualizada. Ya puede iniciar sesión.', [
            'redirigir' => '/html/login.html',
        ]);

    } catch (PDOException $e) {
        error_log('[CambiarContra Error] ' . $e->getMessage());
        responderJSON(false, 'Error interno.', [], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// UTILIDADES
// ─────────────────────────────────────────────────────────────────────────────

function validarContrasena(string $c): bool {
    return strlen($c) >= 8
        && preg_match('/[A-Z]/', $c)
        && preg_match('/[a-z]/', $c)
        && preg_match('/[0-9]/', $c)
        && preg_match('/[\W_]/', $c);
}

function obtenerRedireccion(int $nivel): string {
    return match (true) {
        $nivel >= 5 => '/html/admin/dashboard.html',
        $nivel >= 4 => '/html/propietario/dashboard.html',
        $nivel >= 3 => '/html/gestor/dashboard.html',
        default     => '/html/usuario/dashboard.html',
    };
}
