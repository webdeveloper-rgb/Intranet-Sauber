<?php
declare(strict_types=1);


/**
 * Entrada CLI compatible con Plesk; sincroniza el catalogo de clientes desde la API externa.
 */
// Comando CLI conservado para las tareas programadas de Plesk.
require __DIR__ . '/src/Console/SyncClients.php';
