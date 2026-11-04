<?php
// ============================================================
//  CONFIGURACIÓN — edita solo este fichero
// ============================================================
return [
    // Datos de tu base de datos MySQL (los da tu hosting)
    'db' => [
        'host' => 'localhost',
        'name' => 'panel_intranet',
        'user' => 'sauberofimatica',
        'pass' => 'CONTRASEÑA_BD',
    ],

    // Zona horaria
    'timezone' => 'Europe/Madrid',

    // Nombre que aparece en el panel
    'titulo' => 'Panel de Control de Copias',

    // Buzón IMAP que recibe informes de copias (configurar en config.php)
    'correo_backup' => [
        'host' => 'mail.ejemplo.com',
        'puerto' => 993,
        'usuario' => 'copias@ejemplo.com',
        'password' => 'CONTRASEÑA_DEL_BUZON',
        'carpeta' => 'INBOX',
        'ssl' => true,
        'dias' => 14,       // histórico que se vuelve a revisar para recoger avisos atrasados
        'max' => 300,       // máximo de avisos nuevos por lectura
        'minutos' => 5,     // intervalo de lectura automática; 0 la desactiva
    ],

    // Mostrar errores técnicos en pantalla (false en producción)
    'debug' => false,
];
