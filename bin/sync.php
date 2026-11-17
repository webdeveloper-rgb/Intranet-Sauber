<?php
declare(strict_types=1);


/**
 * Entrada CLI compatible con Plesk; sincroniza el catalogo de clientes desde la API externa.
 */
// Synchronizes the local client catalog with the external API.
require dirname(__DIR__) . '/src/Console/SyncClients.php';
