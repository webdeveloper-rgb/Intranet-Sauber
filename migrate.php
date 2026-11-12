<?php
declare(strict_types=1);


/**
 * Entrada CLI compatible con tareas existentes; ejecuta las migraciones versionadas.
 */
// Comando de migración conservado en la raíz para mantener el despliegue actual.
require __DIR__ . '/src/Console/Migrate.php';
