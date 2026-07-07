-- =====================================================
-- ESQUEMA DE BASE DE DATOS - PNK Inmovilarias Completo
-- Codificación: UTF-8
-- =====================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE DATABASE IF NOT EXISTS `PENKAA_NOGUARDA`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `PENKAA_NOGUARDA`;

-- ========================
-- TABLA: roles
-- ========================
CREATE TABLE IF NOT EXISTS `roles` (
  `id_rol`       INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `nombre`       VARCHAR(50)      NOT NULL UNIQUE,
  `descripcion`  VARCHAR(255)     NOT NULL DEFAULT '',
  `nivel`        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=invitado,2=usuario,3=gestor,4=propietario,5=admin',
  `creado_en`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_rol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================
-- TABLA: usuarios
-- ========================
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id_usuario`       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `nombre`           VARCHAR(100)  NOT NULL,
  `apellido`         VARCHAR(100)  NOT NULL,
  `correo`           VARCHAR(255)  NOT NULL UNIQUE,
  `contrasena_hash`  VARCHAR(255)  NOT NULL,
  `id_rol`           INT UNSIGNED  NOT NULL DEFAULT 2,
  `activo`           TINYINT(1)    NOT NULL DEFAULT 1,
  `avatar`           VARCHAR(255)           DEFAULT NULL,
  `ultimo_acceso`    DATETIME               DEFAULT NULL,
  `token_recupera`   VARCHAR(255)           DEFAULT NULL,
  `token_expira`     DATETIME               DEFAULT NULL,
  `creado_en`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_usuario`),
  KEY `fk_usuario_rol` (`id_rol`),
  CONSTRAINT `fk_usuario_rol` FOREIGN KEY (`id_rol`) REFERENCES `roles` (`id_rol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================
-- TABLA: categorias
-- ========================
CREATE TABLE IF NOT EXISTS `categorias` (
  `id_categoria`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`        VARCHAR(100) NOT NULL UNIQUE,
  `descripcion`   TEXT                  DEFAULT NULL,
  `activa`        TINYINT(1)   NOT NULL DEFAULT 1,
  `creado_en`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================
-- TABLA: productos
-- ========================
CREATE TABLE IF NOT EXISTS `productos` (
  `id_producto`   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `nombre`        VARCHAR(200)      NOT NULL,
  `descripcion`   TEXT                       DEFAULT NULL,
  `precio`        DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
  `stock`         INT UNSIGNED      NOT NULL DEFAULT 0,
  `id_categoria`  INT UNSIGNED               DEFAULT NULL,
  `imagen`        VARCHAR(255)               DEFAULT NULL,
  `activo`        TINYINT(1)        NOT NULL DEFAULT 1,
  `id_creador`    INT UNSIGNED               DEFAULT NULL,
  `creado_en`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_producto`),
  KEY `fk_producto_categoria` (`id_categoria`),
  KEY `fk_producto_creador` (`id_creador`),
  CONSTRAINT `fk_producto_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id_categoria`) ON DELETE SET NULL,
  CONSTRAINT `fk_producto_creador`   FOREIGN KEY (`id_creador`)   REFERENCES `usuarios` (`id_usuario`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================
-- TABLA: pedidos
-- ========================
CREATE TABLE IF NOT EXISTS `pedidos` (
  `id_pedido`     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `id_usuario`    INT UNSIGNED  NOT NULL,
  `estado`        ENUM('pendiente','confirmado','enviado','entregado','cancelado') NOT NULL DEFAULT 'pendiente',
  `total`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notas`         TEXT                   DEFAULT NULL,
  `creado_en`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_pedido`),
  KEY `fk_pedido_usuario` (`id_usuario`),
  CONSTRAINT `fk_pedido_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================
-- TABLA: detalle_pedido
-- ========================
CREATE TABLE IF NOT EXISTS `detalle_pedido` (
  `id_detalle`    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `id_pedido`     INT UNSIGNED  NOT NULL,
  `id_producto`   INT UNSIGNED  NOT NULL,
  `cantidad`      INT UNSIGNED  NOT NULL DEFAULT 1,
  `precio_unidad` DECIMAL(10,2) NOT NULL,
  `subtotal`      DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`id_detalle`),
  KEY `fk_detalle_pedido`   (`id_pedido`),
  KEY `fk_detalle_producto` (`id_producto`),
  CONSTRAINT `fk_detalle_pedido`   FOREIGN KEY (`id_pedido`)   REFERENCES `pedidos`   (`id_pedido`)   ON DELETE CASCADE,
  CONSTRAINT `fk_detalle_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================
-- TABLA: sesiones_csrf (tokens)
-- ========================
CREATE TABLE IF NOT EXISTS `tokens_csrf` (
  `id_token`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario` INT UNSIGNED          DEFAULT NULL,
  `token`      VARCHAR(64)  NOT NULL UNIQUE,
  `ip`         VARCHAR(45)  NOT NULL,
  `expira_en`  DATETIME     NOT NULL,
  `usado`      TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_token`),
  KEY `fk_csrf_usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================
-- TABLA: bitácora de actividad
-- ========================
CREATE TABLE IF NOT EXISTS `bitacora` (
  `id_log`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario` INT UNSIGNED          DEFAULT NULL,
  `accion`     VARCHAR(100) NOT NULL,
  `descripcion` TEXT                 DEFAULT NULL,
  `ip`         VARCHAR(45)  NOT NULL,
  `creado_en`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_log`),
  KEY `fk_bitacora_usuario` (`id_usuario`),
  CONSTRAINT `fk_bitacora_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================
-- DATOS INICIALES: roles
-- ========================
INSERT INTO `roles` (`nombre`, `descripcion`, `nivel`) VALUES
  ('invitado',    'Acceso solo lectura a contenido público',       1),
  ('usuario',     'Usuario registrado con acceso básico',          2),
  ('gestor',      'Gestiona productos y pedidos',                  3),
  ('propietario', 'Propietario con acceso completo a su contenido',4),
  ('admin',       'Administrador total del sistema',               5)
ON DUPLICATE KEY UPDATE `descripcion` = VALUES(`descripcion`);

-- ========================
-- USUARIO ADMINISTRADOR INICIAL
-- Contraseña: Admin123!
-- ========================
INSERT INTO `usuarios` (`nombre`, `apellido`, `correo`, `contrasena_hash`, `id_rol`, `activo`) VALUES
  ('PENKA', 'ADMIN', 'PENKA_ADMIN@pnk.com',
   '$2y$12$LQfmRMXkMi2bMirDRf0zQuEWNMNKEqoePsHQu4A1UVjOxU4IzaFH2', -- Admin123!
   5, 1)
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);
