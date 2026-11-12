<?php
declare(strict_types=1);


/**
 * Comando CLI que elimina logs y staging vencidos segun la retencion configurada.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';

try {
    $pdo = db();
    $days = max(30, min(3650, (int) cfg('maintenance_retention_days', 365)));
    $pdo->beginTransaction();
    $deleted = [];
    foreach ([
        'correo_sync_log' => ['inicio < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]],
        'correo_copias' => ['importada=1 AND fecha < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]],
        'sync_log' => ['inicio < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]],
        'rate_limits' => ['updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)', []],
        'panel_cache' => ['expires_at < NOW()', []],
    ] as $table => [$condition, $params]) {
        $st = $pdo->prepare("DELETE FROM `$table` WHERE $condition");
        $st->execute($params);
        $deleted[$table] = $st->rowCount();
    }
    $pdo->commit();

    foreach ($deleted as $table => $count) {
        echo "{$table}: {$count} registros eliminados\n";
    }
    echo "Retención aplicada: {$days} días. Las copias finales y el staging pendiente se conservaron.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error de purga: ' . $e->getMessage() . "\n");
    exit(1);
}
