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

CREATE TABLE IF NOT EXISTS maquinas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(64) NOT NULL,
  codigo VARCHAR(40) NULL,
  nombre VARCHAR(200) NOT NULL DEFAULT '',
  cliente_codigo VARCHAR(40) NULL,
  marca VARCHAR(100) NULL,
  matricula VARCHAR(60) NULL,
  datos LONGTEXT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  visto DATETIME NULL,
  UNIQUE KEY uq_maquinas_clave (clave),
  KEY idx_maquinas_cliente (cliente_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  inicio DATETIME NOT NULL,
  fin DATETIME NULL,
  ok TINYINT(1) NULL,
  clientes INT NULL,
  maquinas INT NULL,
  mensaje VARCHAR(500) NULL,
  origen VARCHAR(10) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;