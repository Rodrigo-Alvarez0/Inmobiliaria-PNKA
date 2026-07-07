<?php
/**
 * CONFIGURACIÓN DE BASE DE DATOS
 * Archivo: /php/config/base_datos.php
 * Codificación: UTF-8
 */
declare(strict_types=1);

// ─── Configuración de conexión ───────────────────────────────────────────────
define('BD_HOST',     getenv('DB_HOST')     ?: 'localhost');
define('BD_PUERTO',   getenv('DB_PORT')     ?: '3306');
define('BD_NOMBRE',   getenv('DB_NAME')     ?: 'PENKAA_NOGUARDA');
define('BD_USUARIO',  getenv('DB_USER')     ?: 'pnk_admin');
define('BD_CONTRASENA', getenv('DB_PASS')   ?: 'PnK_Admin2026#');
define('BD_CHARSET',  'utf8mb4');

// ─── Configuración general ────────────────────────────────────────────────────
define('APP_NOMBRE',  'PNK_inmovilarias');
define('APP_URL',     'https://localhost');
define('APP_VERSION', '2.0.0');
define('APP_ZONA',    'America/Santiago');
define('APP_IDIOMA',  'es-CL');

// ─── Seguridad ────────────────────────────────────────────────────────────────
define('CSRF_DURACION',    3600);     // segundos
define('SESION_DURACION',  7200);    // segundos (2 horas)
define('HASH_COSTO',       12);      // bcrypt cost
define('TOKEN_RECUPERA_HORAS', 2);

// ─── Zona horaria ─────────────────────────────────────────────────────────────
date_default_timezone_set(APP_ZONA);

/**
 * Clase Conexion - Singleton PDO
 */
class Conexion {
    private static ?PDO $instancia = null;

    private function __construct() {}
    private function __clone() {}

    /**
     * Obtiene la instancia única de PDO
     */
    public static function obtener(): PDO {
        if (self::$instancia === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                BD_HOST, BD_PUERTO, BD_NOMBRE, BD_CHARSET
            );
            $opciones = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];
            try {
                self::$instancia = new PDO($dsn, BD_USUARIO, BD_CONTRASENA, $opciones);
            } catch (PDOException $e) {
                // No exponer detalles al usuario
                error_log('[BD Error] ' . $e->getMessage());
                http_response_code(503);
                die(json_encode([
                    'exito'   => false,
                    'mensaje' => 'No se pudo conectar a la base de datos. Intente más tarde.'
                ]));
            }
        }
        return self::$instancia;
    }
}
