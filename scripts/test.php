<?php
declare(strict_types=1);

/** Ejecuta cada prueba aislada para evitar colisiones entre fixtures globales. */
$tests = glob(__DIR__ . '/../tests/*_test.php') ?: [];
sort($tests, SORT_STRING);
if (!$tests) {
    fwrite(STDERR, "No se encontraron pruebas en tests/.\n");
    exit(1);
}

$failed = [];
foreach ($tests as $test) {
    fwrite(STDOUT, "\n==> " . basename($test) . "\n");
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test), $status);
    if ($status !== 0) {
        $failed[] = basename($test);
    }
}

if ($failed) {
    fwrite(STDERR, "\nPruebas fallidas: " . implode(', ', $failed) . "\n");
    exit(1);
}

fwrite(STDOUT, "\nTodas las pruebas pasaron (" . count($tests) . ").\n");
