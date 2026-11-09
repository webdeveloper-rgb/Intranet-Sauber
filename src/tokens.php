<?php
declare(strict_types=1);

/**
 * Tokens de API para sistemas externos (n8n).
 * - El token en claro se muestra UNA sola vez al crearlo.
 * - En la base de datos solo se guarda su hash SHA-256 (si se filtra la BD, los tokens no sirven).
 * - Se pueden revocar y caducar de forma individual.
 */

function tokens_asegurar_tabla(PDO $pdo): void
{
    static $hecho = false;
    if ($hecho) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        prefijo CHAR(12) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        creado_por VARCHAR(50) NOT NULL,
        creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ultimo_uso DATETIME NULL,
        ultima_ip VARCHAR(45) NULL,
        caduca DATETIME NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_api_tokens_hash (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $hecho = true;
}

function token_crear(string $nombre, string $creadoPor, ?int $caducaDias = null): array
{
    $pdo = db();
    tokens_asegurar_tabla($pdo);
    $token = 'sbr_' . bin2hex(random_bytes(32)); // 256 bits de entropía
    $pdo->prepare('INSERT INTO api_tokens (nombre, prefijo, token_hash, creado_por, caduca)
        VALUES (?, ?, ?, ?, ' . ($caducaDias ? 'DATE_ADD(NOW(), INTERVAL ' . (int) $caducaDias . ' DAY)' : 'NULL') . ')')
        ->execute([$nombre, substr($token, 0, 12), hash('sha256', $token), $creadoPor]);
    return ['id' => (int) $pdo->lastInsertId(), 'token' => $token, 'prefijo' => substr($token, 0, 12)];
}

/** Token de la petición: Authorization: Bearer ... (o X-Api-Key). Nunca por URL: acabaría en los logs. */
function token_de_peticion(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
    $k = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    return $k !== '' ? $k : null;
}

function token_validar(?string $token): ?array
{
    if ($token === null || !preg_match('/^sbr_[0-9a-f]{64}$/', $token)) return null;
    $pdo = db();
    tokens_asegurar_tabla($pdo);
    $st = $pdo->prepare('SELECT id, nombre FROM api_tokens
        WHERE token_hash = ? AND activo = 1 AND (caduca IS NULL OR caduca > NOW())');
    $st->execute([hash('sha256', $token)]);
    $t = $st->fetch();
    if (!$t) return null;
    $pdo->prepare('UPDATE api_tokens SET ultimo_uso = NOW(), ultima_ip = ? WHERE id = ?')
        ->execute([substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $t['id']]);
    return $t;
}
