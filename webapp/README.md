# PNK_inmovilarias — Guía de instalación y uso

## Descripción

Aplicación web completa con autenticación, CRUD de entidades, roles y permisos diferenciados. Desarrollada con HTML5, CSS3, JavaScript vanilla y PHP 8.1+, conectada a MySQL 8.0+.

---

## Requisitos del servidor

| Tecnología | Versión mínima |
|---|---|
| PHP | 8.1 |
| MySQL / MariaDB | 8.0 / 10.6 |
| Apache | 2.4 (con mod_rewrite) |
| HTTPS | Certificado SSL requerido |

---

## Instalación paso a paso

### 1. Clonar o descomprimir el proyecto

```bash
# En la raíz de su servidor web (ej: /var/www/html/)
unzip PENKAA_NOGUARDA_v2.zip -d PENKAA_NOGUARDA
```

### 2. Crear la base de datos

```bash
mysql -u root -p < PENKAA_NOGUARDA/sql/esquema.sql
```

O desde phpMyAdmin:
1. Crear base de datos `PENKAA_NOGUARDA` con cotejamiento `utf8mb4_unicode_ci`
2. Importar el archivo `sql/esquema.sql`

### 3. Configurar la conexión

Edite el archivo `php/config/base_datos.php` o defina variables de entorno:

```php
// Opción A: editar directamente
define('BD_HOST',       'localhost');
define('BD_NOMBRE',     'PENKAA_NOGUARDA');
define('BD_USUARIO',    'su_usuario');
define('BD_CONTRASENA', 'su_contrasena');

// Opción B: variables de entorno (.env o servidor)
// DB_HOST=localhost
// DB_NAME=PENKAA_NOGUARDA
// DB_USER=su_usuario
// DB_PASS=su_contrasena
```

### 4. Permisos de archivos

```bash
chmod -R 755 PENKAA_NOGUARDA/
chmod -R 644 PENKAA_NOGUARDA/php/
chown -R www-data:www-data PENKAA_NOGUARDA/
```

### 5. Configurar Apache (VirtualHost)

```apache
<VirtualHost *:443>
    ServerName midominio.com
    DocumentRoot /var/www/html/PENKAA_NOGUARDA

    SSLEngine on
    SSLCertificateFile    /ruta/al/certificado.crt
    SSLCertificateKeyFile /ruta/a/la/clave.key

    <Directory /var/www/html/PENKAA_NOGUARDA>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

# Redirigir HTTP → HTTPS
<VirtualHost *:80>
    ServerName midominio.com
    Redirect permanent / https://midominio.com/
</VirtualHost>
```

### 6. Activar módulo rewrite

```bash
a2enmod rewrite headers
systemctl restart apache2
```

---

## Credenciales iniciales

| Campo | Valor |
|---|---|
| Correo | `PENKA_ADMIN@pnk.com` |
| Contraseña | `Admin123!` |
| Rol | Administrador |

> ⚠️ **Cambie la contraseña del administrador inmediatamente** tras el primer inicio de sesión.

---

## Estructura del proyecto

```
PENKAA_NOGUARDA/
├── index.php                    # Punto de entrada (redirige por rol)
├── .htaccess                    # Seguridad y HTTPS
├── css/
│   └── estilos.css              # Sistema de diseño completo
├── js/
│   └── utilidades.js            # API, validaciones, modales, toasts
├── html/
│   ├── login.html               # Login + Registro
│   ├── recuperar.html           # Recuperación de contraseña
│   ├── perfil.html              # Perfil (todos los roles)
│   ├── sin_permisos.html        # Error 403
│   ├── 404.html                 # Error 404
│   ├── admin/
│   │   ├── dashboard.html       # Panel administrador
│   │   ├── usuarios.html        # CRUD usuarios
│   │   ├── roles.html           # Matriz de permisos
│   │   ├── productos.html       # CRUD productos
│   │   ├── categorias.html      # CRUD categorías
│   │   └── pedidos.html         # Gestión pedidos
│   ├── gestor/
│   │   ├── dashboard.html       # Panel gestor
│   │   ├── productos.html
│   │   ├── categorias.html
│   │   └── pedidos.html
│   ├── propietario/
│   │   ├── dashboard.html       # Panel propietario
│   │   ├── usuarios.html
│   │   ├── productos.html
│   │   ├── categorias.html
│   │   └── pedidos.html
│   └── usuario/
│       ├── dashboard.html       # Panel usuario
│       └── mis-pedidos.html     # Pedidos propios con timeline
├── php/
│   ├── config/
│   │   └── base_datos.php       # PDO singleton + constantes
│   ├── includes/
│   │   └── sesion.php           # Sesión, CSRF, Sanitizar, Bitácora
│   └── modules/
│       ├── autenticacion.php    # Login, registro, recuperar, logout
│       ├── csrf_token.php       # Endpoint generador de tokens
│       ├── usuarios.php         # CRUD usuarios + estadísticas
│       ├── productos.php        # CRUD productos + filtros
│       ├── categorias.php       # CRUD categorías
│       ├── pedidos.php          # CRUD pedidos + detalle
│       ├── perfil.php           # Ver/editar perfil + cambiar contraseña
│       └── roles.php            # Consulta de roles
└── sql/
    └── esquema.sql              # Tablas, índices y datos iniciales
```

---

## Roles del sistema

| Nivel | Nombre | Acceso |
|---|---|---|
| 1 | Invitado | Solo lectura pública |
| 2 | Usuario | Sus propios pedidos y perfil |
| 3 | Gestor | Catálogo + todos los pedidos |
| 4 | Propietario | Todo excepto roles y bitácora |
| 5 | Admin | Acceso total |

---

## Seguridad implementada

- ✅ Contraseñas con `bcrypt` (cost 12) mediante `Contra_hash()` / `Verificador_Contra()`
- ✅ Tokens CSRF en todos los formularios de escritura
- ✅ Sesiones con regeneración de ID, caducidad y validación de IP
- ✅ Sanitización de todas las entradas con PDO + prepared statements
- ✅ Headers HTTP de seguridad (X-Frame-Options, CSP, HSTS, etc.)
- ✅ HTTPS obligatorio vía `.htaccess`
- ✅ Bloqueo de acceso directo a `/php/` desde el navegador
- ✅ Soft-delete (no se borran registros reales)
- ✅ Bitácora de actividad en base de datos

---

## Personalización rápida

### Cambiar nombre de la aplicación
Busque `PNK_inmovilarias` en `css/estilos.css`, `js/utilidades.js` y los archivos HTML.

### Cambiar colores principales
Edite las variables CSS en `css/estilos.css`:
```css
:root {
  --color-primario:      #1d4ed8;  /* Azul principal */
  --color-secundario:    #0f766e;  /* Verde secundario */
  --color-acento:        #f59e0b;  /* Amarillo acento */
}
```

### Agregar un nuevo módulo PHP
1. Crear `php/modules/mi_modulo.php`
2. Incluir `require_once __DIR__ . '/../includes/sesion.php';`
3. Usar `Sesion::requerirNivel(N)` para controlar acceso
4. Devolver respuestas con `responderJSON($exito, $mensaje, $datos)`

---

## Solución de problemas frecuentes

**Error de conexión a BD**
→ Verifique las credenciales en `php/config/base_datos.php` y que MySQL esté activo.

**Página en blanco / 500**
→ Revise `error_log` de PHP: `tail -f /var/log/apache2/error.log`

**CSRF inválido**
→ Asegúrese de que las cookies de sesión estén habilitadas y que HTTPS esté activo.

**Redirección infinita en login**
→ Confirme que el dominio use HTTPS; el `.htaccess` fuerza la redirección.

---

## Licencia

Proyecto educativo. Libre uso con fines académicos.
