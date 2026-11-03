<?php
// ============================================================
//  CONFIGURACIÓN — edita solo este fichero
// ============================================================
return [
    // Datos de tu base de datos MySQL (los da tu hosting)
    'db' => [
        'host' => 'localhost',
        'name' => 'NOMBRE_BASE_DATOS',
        'user' => 'USUARIO_BD',
        'pass' => 'CONTRASEÑA_BD',
    ],

    // Zona horaria
    'timezone' => 'Europe/Madrid',

    // Nombre que aparece en el panel
    'titulo' => 'Panel de Control de Copias',

    // Mostrar errores técnicos en pantalla (false en producción)
    'debug' => false,
];
