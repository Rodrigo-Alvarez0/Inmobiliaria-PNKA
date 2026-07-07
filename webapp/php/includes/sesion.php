<?php
/**
 * GESTIÓN DE SESIONES Y SEGURIDAD
 * Archivo: /php/includes/sesion.php
 * Codificación: UTF-8
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/base_datos.php';

// ─── Configuración segura de sesión ──────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESION_DURACION,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    // Regenerar ID para prevenir fijación de sesión
    if (!isset($_SESSION['iniciada'])) {
        session_regenerate_id(true);
        $_SESSION['iniciada'] = true;
        $_SESSION['ip']       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $_SESSION['agente']   = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    // Verificar que la sesión pertenece al mismo cliente
    if (
        isset($_SESSION['ip']) &&
        $_SESSION['ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')
    ) {
        Sesion::destruir();
    }
}

// ─── Funciones de contraseña (como pedidas en el enunciado) ──────────────────
/**
 * Crea el hash seguro de una contraseña (wrapper de password_hash)
 */
function Contra_hash(string $contrasena): string {
    return password_hash($contrasena, PASSWORD_BCRYPT, ['cost' => HASH_COSTO]);
}

/**
 * Verifica una contraseña contra su hash (wrapper de password_verify)
 */
function Verificador_Contra(string $contrasena, string $hash): bool {
    return password_verify($contrasena, $hash);
}

// ─── Clase de gestión de sesión ───────────────────────────────────────────────
class Sesion {

    /**
     * Inicia sesión para un usuario autenticado
     */
    public static function iniciar(array $usuario): void {
        session_regenerate_id(true);
        $_SESSION['id_usuario'] = (int)$usuario['id_usuario'];
        $_SESSION['nombre']     = $usuario['nombre'];
        $_SESSION['apellido']   = $usuario['apellido'];
        $_SESSION['correo']     = $usuario['correo'];
        $_SESSION['id_rol']     = (int)$usuario['id_rol'];
        $_SESSION['nombre_rol'] = $usuario['nombre_rol'];
        $_SESSION['nivel_rol']  = (int)$usuario['nivel'];
        $_SESSION['ip']         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $_SESSION['agente']     = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['autenticado'] = true;
        $_SESSION['tiempo']     = time();
    }

    /**
     * Destruye la sesión actual
     */
    public static function destruir(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Verifica si hay un usuario autenticado
     */
    public static function estaAutenticado(): bool {
        if (!isset($_SESSION['autenticado'], $_SESSION['tiempo'])) return false;
        if (time() - $_SESSION['tiempo'] > SESION_DURACION) {
            self::destruir();
            return false;
        }
        $_SESSION['tiempo'] = time(); // refrescar
        return true;
    }

    /**
     * Retorna el ID del usuario en sesión (0 si no autenticado)
     */
    public static function idUsuario(): int {
        return (int)($_SESSION['id_usuario'] ?? 0);
    }

    /**
     * Retorna el nivel de rol del usuario (1=invitado … 5=admin)
     */
    public static function nivelRol(): int {
        return (int)($_SESSION['nivel_rol'] ?? 1);
    }

    /**
     * Retorna el nombre del rol
     */
    public static function nombreRol(): string {
        return $_SESSION['nombre_rol'] ?? 'invitado';
    }

    /**
     * Retorna el nombre completo del usuario
     */
    public static function nombreCompleto(): string {
        $n = $_SESSION['nombre']   ?? '';
        $a = $_SESSION['apellido'] ?? '';
        return trim("$n $a");
    }

    /**
     * Verifica si el usuario tiene al menos el nivel requerido
     */
    public static function tienePermiso(int $nivelMinimo): bool {
        return self::nivelRol() >= $nivelMinimo;
    }

    /**
     * Redirige a login si no está autenticado
     */
    public static function requerirAutenticacion(): void {
        if (!self::estaAutenticado()) {
            header('Location: /html/login.html');
            exit;
        }
    }

    /**
     * Redirige con error si no tiene el nivel requerido
     */
    public static function requerirNivel(int $nivel): void {
        self::requerirAutenticacion();
        if (!self::tienePermiso($nivel)) {
            http_response_code(403);
            header('Location: /html/sin_permisos.html');
            exit;
        }
    }

    /**
     * Datos de sesión para uso en JSON/JS
     */
    public static function datosPublicos(): array {
        if (!self::estaAutenticado()) return ['autenticado' => false];
        return [
            'autenticado'   => true,
            'id_usuario'    => self::idUsuario(),
            'nombre'        => $_SESSION['nombre']     ?? '',
            'apellido'      => $_SESSION['apellido']   ?? '',
            'nombre_rol'    => self::nombreRol(),
            'nivel_rol'     => self::nivelRol(),
        ];
    }
}

// ─── CSRF ─────────────────────────────────────────────────────────────────────
class CSRF {

    /**
     * Genera y almacena un token CSRF en sesión
     */
    public static function generar(string $formulario = 'general'): string {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf'][$formulario] = [
            'token'  => $token,
            'expira' => time() + CSRF_DURACION,
        ];
        return $token;
    }

    /**
     * Verifica el token CSRF recibido
     */
    public static function verificar(string $token, string $formulario = 'general'): bool {
        $datos = $_SESSION['csrf'][$formulario] ?? null;
        if (!$datos) return false;
        if (time() > $datos['expira']) {
            unset($_SESSION['csrf'][$formulario]);
            return false;
        }
        $valido = hash_equals($datos['token'], $token);
        if ($valido) unset($_SESSION['csrf'][$formulario]);
        return $valido;
    }

    /**
     * Campo oculto HTML con token CSRF
     */
    public static function campoOculto(string $formulario = 'general'): string {
        $token = self::generar($formulario);
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
}

// ─── Sanitización ─────────────────────────────────────────────────────────────
class Sanitizar {
    public static function texto(string $valor): string {
        return htmlspecialchars(strip_tags(trim($valor)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    public static function entero(mixed $valor): int {
        return (int) filter_var($valor, FILTER_VALIDATE_INT);
    }
    public static function decimal(mixed $valor): float {
        return (float) filter_var($valor, FILTER_VALIDATE_FLOAT, ['options' => ['decimal' => '.']]);
    }
    public static function correo(string $valor): string|false {
        return filter_var(trim($valor), FILTER_VALIDATE_EMAIL);
    }
    public static function url(string $valor): string|false {
        return filter_var(trim($valor), FILTER_VALIDATE_URL);
    }
}

// ─── Bitácora ─────────────────────────────────────────────────────────────────
class Bitacora {
    public static function registrar(string $accion, string $descripcion = ''): void {
        try {
            $pdo  = Conexion::obtener();
            $stmt = $pdo->prepare(
                'INSERT INTO `bitacora` (`id_usuario`, `accion`, `descripcion`, `ip`)
                 VALUES (:id, :accion, :desc, :ip)'
            );
            $stmt->execute([
                ':id'     => Sesion::idUsuario() ?: null,
                ':accion' => $accion,
                ':desc'   => $descripcion,
                ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);
        } catch (PDOException $e) {
            error_log('[Bitácora Error] ' . $e->getMessage());
        }
    }
}

// ─── Respuesta JSON ───────────────────────────────────────────────────────────
function responderJSON(bool $exito, string $mensaje, array $datos = [], int $codigo = 200): never {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'exito'   => $exito,
        'mensaje' => $mensaje,
        'datos'   => $datos,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
