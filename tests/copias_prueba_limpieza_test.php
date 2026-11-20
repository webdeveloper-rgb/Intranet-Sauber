<?php
declare(strict_types=1);

require __DIR__ . '/../src/Backup/TestCopyCleanup.php';

$history = [
    ['id' => 1, 'huella' => 'prueba', 'equipo_id' => 7, 'clasificacion_evidencia' => 'Este es un mensaje de notificación de prueba'],
    ['id' => 2, 'huella' => 'real', 'equipo_id' => 7, 'equipo' => 'PC-TEST', 'desc_error' => 'Fallo de copia real'],
    ['id' => 3, 'huella' => null, 'equipo_id' => 8, 'desc_error' => "Mensaje de notificaci\u{FFFD}n de prueba"],
    ['id' => 4, 'huella' => null, 'equipo_id' => 7, 'clasificacion_evidencia' => 'Copia correcta'],
    ['id' => 5, 'huella' => 'solo-staging', 'equipo_id' => 9, 'desc_error' => ''],
    ['id' => 6, 'huella' => 'restauracion', 'equipo_id' => 7, 'clasificacion_evidencia' => 'Prueba de restauración completada'],
];
$staging = [
    ['id' => 21, 'message_key' => 'prueba', 'equipo_id' => 7, 'importada' => 1],
    ['id' => 22, 'message_key' => 'real', 'equipo_id' => 7, 'importada' => 1],
    ['id' => 23, 'message_key' => 'solo-staging', 'equipo_id' => 9, 'clasificacion_evidencia' => 'This is a test notification.'],
];
$plan = copias_prueba_plan($history, $staging);
if (array_column($plan['copias'], 'id') !== [1, 3, 5]
    || array_column($plan['correo_copias'], 'id') !== [21, 23]) {
    throw new RuntimeException('Seleccion de pruebas incorrecta');
}
if (copias_prueba_plan([], []) !== ['copias' => [], 'correo_copias' => []]) {
    throw new RuntimeException('Plan vacio incorrecto');
}
echo "Seleccion verificada: pruebas, parejas importadas y copias reales del mismo equipo.\n";
