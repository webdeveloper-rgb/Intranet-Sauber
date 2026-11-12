-- Panel de Control de Copias · Sauber Ofimática
-- Esquema MySQL 5.7+ / MariaDB 10.3+ (utf8mb4)

CREATE TABLE IF NOT EXISTS usuarios (
  -- Perfil local: la autenticacion real se verifica con la API de usuarios autorizados.
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario VARCHAR(50) NOT NULL,
  api_user_id VARCHAR(100) NULL,
  nombre VARCHAR(100) NOT NULL DEFAULT '',
  rol ENUM('admin','usuario') NOT NULL DEFAULT 'usuario',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  password_hash VARCHAR(255) NOT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuario (usuario),
  UNIQUE KEY uq_usuarios_api_user_id (api_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS copias (
  -- Historial definitivo mostrado por el panel; guarda tambien el estado de revision y seguimiento.
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha DATETIME NOT NULL,
  cliente VARCHAR(200) NOT NULL,
  equipo VARCHAR(200) NOT NULL,
  equipo_id INT UNSIGNED NULL,
  huella CHAR(40) NULL,
  estado ENUM('OK','ERROR') NOT NULL,
  desc_error VARCHAR(500) NOT NULL DEFAULT '',
  codigo VARCHAR(40) NULL,
  software VARCHAR(80) NULL,
  destino VARCHAR(120) NULL,
  tamano VARCHAR(40) NULL,
  duracion VARCHAR(40) NULL,
  intentos SMALLINT UNSIGNED NULL,
  revisado TINYINT(1) NOT NULL DEFAULT 0,
  revisado_por VARCHAR(50) NULL,
  revisado_fecha DATETIME NULL,
  revision_desc TEXT NULL,
  seg ENUM('vigilancia','cerrado') NULL,
  recordar DATE NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fecha (fecha),
  KEY idx_cliente_equipo (cliente, equipo),
  KEY idx_copias_equipo_id (equipo_id),
  UNIQUE KEY uq_copias_huella (huella),
  KEY idx_estado_rev (estado, revisado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notas (
  -- Notas de tecnicos ligadas a una copia; la FK las elimina junto con la copia.
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  copia_id INT UNSIGNED NOT NULL,
  usuario VARCHAR(50) NOT NULL,
  texto TEXT NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_copia (copia_id),
  CONSTRAINT fk_notas_copia FOREIGN KEY (copia_id) REFERENCES copias (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
