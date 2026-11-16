-- Alertas vigentes e historial de notificacion de Monitor-Equipos.
CREATE TABLE IF NOT EXISTS monitor_alertas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  equipo_id INT UNSIGNED NOT NULL,
  alert_key VARCHAR(64) NOT NULL,
  severidad ENUM('critical','warning') NOT NULL,
  titulo VARCHAR(120) NOT NULL,
  detalle VARCHAR(500) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  primera_vez DATETIME NOT NULL,
  ultima_vez DATETIME NOT NULL,
  resuelto_en DATETIME NULL,
  email_enviado DATETIME NULL,
  email_ultimo_intento DATETIME NULL,
  UNIQUE KEY uq_monitor_alerta_equipo_clave (equipo_id, alert_key),
  KEY idx_monitor_alertas_activo (activo, severidad),
  CONSTRAINT fk_monitor_alerta_equipo FOREIGN KEY (equipo_id) REFERENCES equipos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tokens temporales, de un solo uso y ligados a la identidad de cada agente.
CREATE TABLE IF NOT EXISTS monitor_ingest_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  machine_id VARCHAR(128) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  UNIQUE KEY uq_monitor_ingest_token_hash (token_hash),
  KEY idx_monitor_ingest_token_expiry (expires_at),
  KEY idx_monitor_ingest_token_machine (machine_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Credenciales individuales de agentes y códigos de alta de un solo uso.
CREATE TABLE IF NOT EXISTS monitor_agents (
  machine_id VARCHAR(128) NOT NULL PRIMARY KEY,
  credential_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_monitor_agent_credential_hash (credential_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS monitor_enrollments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code_hash CHAR(64) NOT NULL,
  code_prefix CHAR(12) NOT NULL,
  created_by VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  consumed_machine_id VARCHAR(128) NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY uq_monitor_enrollment_code_hash (code_hash),
  KEY idx_monitor_enrollment_expiry (expires_at),
  KEY idx_monitor_enrollment_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Errores críticos y de evento recibidos en la ventana móvil de 30 días.
CREATE TABLE IF NOT EXISTS monitor_eventos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key CHAR(64) NOT NULL,
  machine_id VARCHAR(128) NOT NULL,
  equipo_id INT UNSIGNED NOT NULL,
  record_id BIGINT UNSIGNED NULL,
  event_time DATETIME NOT NULL,
  log_name VARCHAR(128) NOT NULL,
  source VARCHAR(255) NULL,
  event_id INT UNSIGNED NOT NULL,
  level ENUM('Critical','Error') NOT NULL,
  message TEXT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_monitor_event_key (event_key),
  KEY idx_monitor_event_time (event_time, id),
  KEY idx_monitor_event_equipo_time (equipo_id, event_time, id),
  CONSTRAINT fk_monitor_evento_equipo FOREIGN KEY (equipo_id) REFERENCES equipos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
