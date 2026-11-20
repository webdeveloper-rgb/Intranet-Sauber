CREATE TABLE IF NOT EXISTS equipo_nombres (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  software VARCHAR(80) NOT NULL,
  nombre VARCHAR(200) NOT NULL,
  equipo_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_equipo_nombre (software,nombre),
  KEY idx_equipo_nombres_destino (equipo_id),
  CONSTRAINT fk_equipo_nombre FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipo_combinaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  destino_id INT UNSIGNED NOT NULL,
  usuario VARCHAR(50) NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  detalle LONGTEXT NOT NULL,
  KEY idx_combinacion_destino (destino_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
