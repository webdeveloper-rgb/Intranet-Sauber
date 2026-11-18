<?php
declare(strict_types=1);


/**
 * Comando CLI que aplica cambios de esquema versionados de forma repetible.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('SCHEMA_MIGRATING', true);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../Integration/ClientApi.php';
require __DIR__ . '/../Backup/EquipmentService.php';
require_once __DIR__ . '/../Monitoring/MonitorService.php';
require __DIR__ . '/../Security/ApiTokens.php';

$activeMigration = 0;

function migracion_ejecutar_sql(PDO $pdo, string $sql, string $origen): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        throw new RuntimeException('Error en ' . $origen . ' [' . $sql . ']: ' . $e->getMessage(), 0, $e);
    }
}

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
        2 => static function (PDO $pdo): void {
            // Reconciles installations that reached version 1 before newer columns were added.
            asegurar_tablas_sync($pdo);
            asegurar_tablas_equipos($pdo);
            tokens_asegurar_tabla($pdo);
        },
        3 => static function (PDO $pdo): void {
            monitor_migrar_esquema($pdo);
        },
        4 => static function (PDO $pdo): void {
            monitor_migrar_esquema($pdo);
        },
        5 => static function (PDO $pdo): void {
            monitor_migrar_esquema($pdo);
        },
        6 => static function (PDO $pdo): void {
            monitor_migrar_esquema($pdo);
        },
        7 => static function (PDO $pdo): void {
            $columns = $pdo->query("SELECT column_name FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='clientes'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('tiene_satbk', $columns, true)) {
                $pdo->exec('ALTER TABLE clientes ADD COLUMN tiene_satbk TINYINT(1) NOT NULL DEFAULT 0 AFTER activo');
                $pdo->exec('UPDATE clientes SET tiene_satbk=activo');
            }
            $pdo->exec("UPDATE equipos SET vigilar=0
                WHERE monitor_machine_id IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM correo_copias cc WHERE cc.equipo_id=equipos.id)
                    AND NOT EXISTS (SELECT 1 FROM copias cp WHERE cp.equipo_id=equipos.id)");
        },
        8 => static function (PDO $pdo): void {
            monitor_migrar_esquema($pdo);
        },
        9 => static function (PDO $pdo): void {
            monitor_migrar_esquema($pdo);
        },
        10 => static function (PDO $pdo): void {
            asegurar_tablas_equipos($pdo);
        },
        11 => static function (PDO $pdo): void {
            monitor_migrar_esquema($pdo);
        },
        12 => static function (PDO $pdo): void {
            foreach (['copias', 'correo_copias'] as $table) {
                $column = $pdo->prepare("SELECT column_type FROM information_schema.columns
                    WHERE table_schema=DATABASE() AND table_name=? AND column_name='estado'");
                $column->execute([$table]);
                $type = (string) $column->fetchColumn();
                if ($type !== '' && !str_contains($type, "'REVISION'")) {
                    $pdo->exec("ALTER TABLE `{$table}` MODIFY estado ENUM('OK','ERROR','REVISION') NOT NULL");
                }
            }
        },
        13 => static function (PDO $pdo): void {
            $columns = $pdo->query("SELECT column_name FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='equipos'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('error_reviewed_at', $columns, true)) {
                $pdo->exec('ALTER TABLE equipos ADD COLUMN error_reviewed_at DATETIME NULL');
            }
            if (!in_array('error_reviewed_by', $columns, true)) {
                $pdo->exec('ALTER TABLE equipos ADD COLUMN error_reviewed_by VARCHAR(50) NULL');
            }
            if (!in_array('error_review_desc', $columns, true)) {
                $pdo->exec('ALTER TABLE equipos ADD COLUMN error_review_desc TEXT NULL');
            }
        },
        14 => static function (PDO $pdo): void {
            $pdo->exec("CREATE TABLE IF NOT EXISTS correo_copias_eliminadas (
                message_key CHAR(40) NOT NULL PRIMARY KEY,
                equipo_id INT UNSIGNED NOT NULL,
                deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_correo_eliminadas_equipo (equipo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        },
        15 => static function (PDO $pdo): void {
            foreach (['copias', 'correo_copias'] as $table) {
                $columns = $pdo->query("SELECT column_name FROM information_schema.columns
                    WHERE table_schema=DATABASE() AND table_name='{$table}'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ([
                    'resultado_original' => "VARCHAR(24) NOT NULL DEFAULT ''",
                    'clasificacion_fuente' => "VARCHAR(40) NOT NULL DEFAULT ''",
                    'clasificacion_evidencia' => "VARCHAR(600) NOT NULL DEFAULT ''",
                ] as $column => $definition) {
                    if (!in_array($column, $columns, true)) {
                        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
                    }
                }
            }
            $pdo->exec("UPDATE copias SET resultado_original=estado,clasificacion_fuente='legacy'
                WHERE resultado_original='' AND clasificacion_fuente=''");
            $pdo->exec("UPDATE correo_copias SET resultado_original=estado,clasificacion_fuente='legacy'
                WHERE resultado_original='' AND clasificacion_fuente=''");
        },
        16 => static function (PDO $pdo): void {
            foreach (['copias', 'correo_copias'] as $table) {
                $column = $pdo->prepare("SELECT column_type FROM information_schema.columns
                    WHERE table_schema=DATABASE() AND table_name=? AND column_name='estado'");
                $column->execute([$table]);
                $type = (string) $column->fetchColumn();
                if ($type !== '' && (!str_contains($type, "'WARNING'") || !str_contains($type, "'RUNNING'"))) {
                    $pdo->exec("ALTER TABLE `{$table}` MODIFY estado ENUM('OK','ERROR','WARNING','RUNNING','REVISION') NOT NULL");
                }
            }
        },
        17 => static function (PDO $pdo): void {
            $indexes = $pdo->query("SELECT index_name FROM information_schema.statistics
                WHERE table_schema=DATABASE() AND index_name IN ('idx_copias_equipo_estado','idx_correo_equipo_estado_importada')")
                ->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('idx_copias_equipo_estado', $indexes, true)) {
                $pdo->exec('ALTER TABLE copias ADD KEY idx_copias_equipo_estado (equipo_id,estado)');
            }
            if (!in_array('idx_correo_equipo_estado_importada', $indexes, true)) {
                $pdo->exec('ALTER TABLE correo_copias ADD KEY idx_correo_equipo_estado_importada (equipo_id,estado,importada)');
            }
        },
    ];

    foreach ($migrations as $version => $migration) {
        if ($version <= $current) {
            continue;
        }
        $activeMigration = $version;
        $migration($pdo);
        $stmt = $pdo->prepare("INSERT INTO schema_version (id,version,updated_at) VALUES (1,?,NOW())
            ON DUPLICATE KEY UPDATE version=?,updated_at=NOW()");
        $stmt->execute([$version, $version]);
        echo "Migración {$version} aplicada.\n";
    }

    echo "Esquema actualizado.\n";
} catch (Throwable $e) {
    $fase = $activeMigration > 0 ? ' (migración ' . $activeMigration . ')' : '';
    fwrite(STDERR, 'Error de migración' . $fase . ': ' . $e->getMessage() . "\n");
    exit(1);
}
