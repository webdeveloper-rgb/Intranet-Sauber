-- Tablas de la sincronización con la API (se crean solas si no existen)

CREATE TABLE IF NOT EXISTS clientes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(64) NOT NULL,
  codigo VARCHAR(40) NULL,
  nombre VARCHAR(200) NOT NULL DEFAULT '',
  cif VARCHAR(40) NULL,
  email VARCHAR(150) NULL,
  telefono VARCHAR(40) NULL,
  localidad VARCHAR(100) NULL,
  provincia VARCHAR(100) NULL,
  datos LONGTEXT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  visto DATETIME NULL,
  UNIQUE KEY uq_clientes_clave (clave),
  KEY idx_clientes_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  inicio DATETIME NOT NULL,
  fin DATETIME NULL,
  ok TINYINT(1) NULL,
  clientes INT NULL,
  mensaje VARCHAR(500) NULL,
  origen VARCHAR(10) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS equipos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(200) NOT NULL,
  software VARCHAR(40) NOT NULL DEFAULT '',
  cliente_id INT UNSIGNED NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_equipos_origen_nombre (software, nombre),
  KEY idx_equipos_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS correo_copias (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_key VARCHAR(190) NOT NULL,
  equipo_id INT UNSIGNED NOT NULL,
  fecha DATETIME NOT NULL,
  estado ENUM('OK','ERROR') NOT NULL,
  desc_error VARCHAR(500) NOT NULL DEFAULT '',
  codigo VARCHAR(40) NULL,
  software VARCHAR(80) NULL,
  destino VARCHAR(120) NULL,
  tamano VARCHAR(40) NULL,
  duracion VARCHAR(40) NULL,
  importada TINYINT(1) NOT NULL DEFAULT 0,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_correo_key (message_key),
  KEY idx_correo_equipo_importada (equipo_id, importada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS correo_sync_estado (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  revisado DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
