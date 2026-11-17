<?php
declare(strict_types=1);


/**
 * Entrada CLI compatible con tareas existentes; ejecuta las migraciones versionadas.
 */
// Applies versioned database schema migrations.
require dirname(__DIR__) . '/src/Console/Migrate.php';
