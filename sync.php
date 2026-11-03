<?php
declare(strict_types=1);
// Sincroniza clientes y máquinas con la API. SOLO se ejecuta desde la línea de comandos
// (tarea programada de Plesk); desde el navegador responde 404.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/api_utilities.php';

try {
    $r = api_utility_sync('cli') ?? ['clientes' => 0, 'maquinas' => 0];
    echo date('c') . " OK clientes={$r['clientes']} maquinas={$r['maquinas']}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' ERROR ' . $e->getMessage() . "\n");
    exit(1);
}
