-- Equipos detectados en los avisos y copias importadas desde el buzón.

CREATE TABLE IF NOT EXISTS equipos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(200) NOT NULL,
  software VARCHAR(80) NOT NULL DEFAULT '',
  origen VARCHAR(200) NULL,
  sugerido VARCHAR(200) NULL,
  dias_copia CHAR(7) NOT NULL DEFAULT '1111111',
  cliente_id INT UNSIGNED NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_equipos_origen_nombre (software, nombre),
  KEY idx_equipos_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS correo_copias (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_key CHAR(40) NOT NULL,
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
  KEY idx_correo_equipo_importada (equipo_id, importada),
  KEY idx_correo_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS correo_sync_estado (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  revisado DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS correo_ignorados (
  message_key CHAR(40) NOT NULL PRIMARY KEY,
  fecha DATETIME NOT NULL,
  asunto VARCHAR(500) NOT NULL DEFAULT '',
  parser_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_correo_ignorados_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS correo_sync_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  inicio DATETIME NOT NULL,
  fin DATETIME NULL,
  ok TINYINT(1) NULL,
  nuevos INT NOT NULL DEFAULT 0,
  ignorados INT NOT NULL DEFAULT 0,
  mensaje VARCHAR(500) NULL,
  origen VARCHAR(10) NOT NULL DEFAULT '',
  KEY idx_correo_log_inicio (inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
