<?php
declare(strict_types=1);
// Sincroniza clientes con la API (los avisos de copia llegan por ingest.php desde n8n). SOLO se ejecuta desde la línea de comandos
// (tarea programada de Plesk); desde el navegador responde 404.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/api_utilities.php';

try {
    $r = api_sync('cli') ?? ['clientes' => 0];
    echo date('c') . " OK clientes={$r['clientes']}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' ERROR ' . $e->getMessage() . "\n");
    exit(1);
}
