<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../Backup/TestCopyCleanup.php';

$apply = in_array('--aplicar', $argv, true);
if (array_diff(array_slice($argv, 1), ['--aplicar'])) {
    fwrite(STDERR, "Uso: php bin/eliminar-copias-prueba.php [--aplicar]\n");
    exit(1);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    // Se bloquean las filas al aplicar para que no se importen mientras se eliminan.
    $lock = $apply ? ' FOR UPDATE' : '';
    $history = $pdo->query('SELECT * FROM copias ORDER BY id' . $lock)->fetchAll();
    $staging = $pdo->query('SELECT * FROM correo_copias ORDER BY id' . $lock)->fetchAll();
    $plan = copias_prueba_plan($history, $staging);
    foreach ($plan as $table => $rows) {
        echo $table . ': ' . count($rows) . " registros de prueba identificados\n";
        foreach ($rows as $row) {
            echo json_encode([
                'id' => $row['id'], 'fecha' => $row['fecha'],
                'equipo' => $row['equipo'] ?? $row['equipo_id'],
                'evidencia' => $row['clasificacion_evidencia'] ?? '',
                'descripcion' => $row['desc_error'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        }
    }
    if (!$apply || (!$plan['copias'] && !$plan['correo_copias'])) {
        $pdo->rollBack();
        echo $apply ? "No hay pruebas identificadas que borrar.\n" : "Simulacion: no se ha borrado nada. Usa --aplicar para eliminar lo identificado.\n";
        exit;
    }

    $plan['notas'] = [];
    $notes = $pdo->prepare('SELECT * FROM notas WHERE copia_id=?');
    foreach ($plan['copias'] as $copy) {
        $notes->execute([$copy['id']]);
        foreach ($notes->fetchAll() as $note) {
            $plan['notas'][] = $note;
        }
    }
    $backup = dirname(__DIR__, 2) . '/config/copias-prueba-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    $json = json_encode(['fecha' => date(DATE_ATOM), 'registros' => $plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $handle = fopen($backup, 'x');
    if ($handle === false) {
        throw new RuntimeException('No se pudo crear la copia de seguridad.');
    }
    try {
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = fwrite($handle, substr($json, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('No se pudo completar la copia de seguridad.');
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('No se pudo guardar la copia de seguridad.');
        }
    } finally {
        fclose($handle);
    }
    $remember = $pdo->prepare('INSERT IGNORE INTO correo_copias_eliminadas (message_key,equipo_id) VALUES (?,?)');
    foreach (['copias', 'correo_copias'] as $table) {
        $delete = $pdo->prepare("DELETE FROM `$table` WHERE id=?");
        foreach ($plan[$table] as $row) {
            $key = $row['huella'] ?? $row['message_key'] ?? null;
            if ($key && !empty($row['equipo_id'])) {
                $remember->execute([$key, $row['equipo_id']]);
            }
            $delete->execute([$row['id']]);
        }
    }
    $pdo->commit();
    echo "Pruebas eliminadas. Equipos y monitorizacion conservados.\nCopia de seguridad: {$backup}\n";
    echo "Si una prueba no conserva su texto en la evidencia o descripcion, no se identifica automaticamente.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Limpieza cancelada: ' . $e->getMessage() . "\n");
    exit(1);
}
