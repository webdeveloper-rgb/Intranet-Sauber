<?php
/** Plantilla local de configuracion; copiar a config.php y sustituir todos los valores de ejemplo. */
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
        'sync_max_caida_pct' => 30,
    ],

    // DNI o correo de las personas administradoras; usa los mismos datos autorizados en api_sync.
    'admins' => ['DNI_MAXIMILIANO'],

    // Zona horaria
    'timezone' => 'Europe/Madrid',

    // Nombre que aparece en el panel
    'titulo' => 'Panel de Control de Copias',

    // Mostrar errores técnicos en pantalla (false en producción)
    'debug' => false,

    // Horas sin recepción de n8n antes de alertar en el panel.
    'ingest_alert_hours' => 24,

    // Retención de logs y staging ya importado; nunca elimina copias finales ni staging pendiente.
    'maintenance_retention_days' => 365,

    // Actívalo solo si el proxy inverso está configurado y controlado por ti.
    'trust_proxy_https' => false,
];
