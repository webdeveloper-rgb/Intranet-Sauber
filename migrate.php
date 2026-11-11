<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('SCHEMA_MIGRATING', true);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/api_utilities.php';
require __DIR__ . '/src/equipos.php';
require __DIR__ . '/src/tokens.php';

try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_version (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        version INT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $current = (int) ($pdo->query('SELECT version FROM schema_version WHERE id=1')->fetchColumn() ?: 0);

    $migrations = [
        1 => static function (PDO $pdo): void {
            migrar_usuarios($pdo);
            asegurar_tablas_sync($pdo);
            asegurar_tablas_equipos($pdo);
            tokens_asegurar_tabla($pdo);

            $userColumns = $pdo->query("SELECT column_name FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='usuarios'")->fetchAll(PDO::FETCH_COLUMN);
            if ($userColumns && !in_array('api_user_id', $userColumns, true)) {
                $pdo->exec('ALTER TABLE usuarios ADD COLUMN api_user_id VARCHAR(100) NULL AFTER usuario');
            }
            $index = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema=DATABASE() AND table_name='usuarios' AND index_name='uq_usuarios_api_user_id'")->fetchColumn();
            if ($userColumns && !(int) $index) {
                $pdo->exec('ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_api_user_id (api_user_id)');
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                bucket CHAR(64) NOT NULL PRIMARY KEY,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                window_started DATETIME NOT NULL,
                blocked_until DATETIME NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_rate_limits_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS panel_cache (
                cache_key CHAR(64) NOT NULL PRIMARY KEY,
                payload MEDIUMTEXT NOT NULL,
                expires_at DATETIME NOT NULL,
                KEY idx_panel_cache_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        },
    ];

    foreach ($migrations as $version => $migration) {
        if ($version <= $current) {
            continue;
        }
        $migration($pdo);
        $stmt = $pdo->prepare("INSERT INTO schema_version (id,version,updated_at) VALUES (1,?,NOW())
            ON DUPLICATE KEY UPDATE version=?,updated_at=NOW()");
        $stmt->execute([$version, $version]);
        echo "Migración {$version} aplicada.\n";
    }

    echo "Esquema actualizado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error de migración: ' . $e->getMessage() . "\n");
    exit(1);
}
