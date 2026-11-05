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

    // API de clientes y filtro por servicio contratado
    'api_sync' => [
        'base_url' => 'https://api.albelink.es/api/v1',
        'email' => 'USUARIO_API',
        'password' => 'CONTRASEÑA_API',
        'org' => '1',
        'proyecto' => 'demo',
        'tabla_clientes' => 'Cliente',
        'tabla_cliservicios' => 'Cliservicios',
        'tabla_usuarios' => 'Usuario',
        'usuarios_permitidos' => [
            'dni' => ['DNI_MAXIMILIANO', 'DNI_MIGUEL'],
            'correo_nombre' => ['Alberto'],
        ],
        'servicio_requerido' => 'VBA3KT7GN7',
        'sync_minutos' => 15,
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
        'dias' => 0,        // 0 revisa todo el buzón; un número limita los días históricos
        'max' => 1000,      // máximo de avisos nuevos por lectura
        'minutos' => 1,     // intervalo de lectura automática; 0 la desactiva
    ],

    // Mostrar errores técnicos en pantalla (false en producción)
    'debug' => false,
];
