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
