# Organización del proyecto

El repositorio contiene el panel de monitoreo de copias y equipos de los clientes de Sauber Ofimática. La raíz sigue siendo el directorio público que despliega Plesk.

## Interfaz

```text
frontend/
  dev/preview/server.js       Servidor de demo y datos ficticios
  src/
    main.jsx                  Montaje de React y entrada de estilos
    app/                      Composición, rutas y navegación principal
      layout/                 Cabecera y estructura común
    features/
      account/components/     Usuarios y tokens
      backups/                Copias, historial, revisión e informes
        components/
        hooks/
        lib/
        pages/
      clients/                Clientes y selector de cliente
      dashboard/pages/        Resumen e inicio
      equipment/              Listado y ficha de equipos
        components/           Monitoreo, revisión y controles de configuración
        pages/
      events/pages/           Consulta de eventos
      notifications/components/
    shared/
      api/client.js           Peticiones y sesión/CSRF
      hooks/                  Hooks reutilizables
      lib/                    Reglas y utilidades usadas por varias áreas
      ui/                     Controles visuales comunes
    styles/
      index.css               Entrada y orden de la cascada
      base.css                Estilos compartidos existentes
      dashboard.css           Estilos del dashboard
```

Cada área contiene sus pantallas en `pages`, sus piezas en `components` y, cuando corresponda, sus hooks y utilidades. Los componentes compartidos van en `shared`; la composición global permanece en `app`. Los estilos conservan el orden anterior para mantener la presentación.

## Servidor e integraciones

```text
src/
  bootstrap.php               Configuración, sesión, conexión y seguridad común
  Http/
    Endpoints/                Entradas HTTP y despacho de la API
    Controllers/              Acciones del panel
    Pages/                    HTML de entrada, login y redirecciones
    JsonResponse.php          Respuestas JSON
  Backup/                     Ingesta y repositorio de copias
  Equipment/                  Gestión y asignación de equipos
  Monitoring/                 Informes y alertas de los agentes
  Integration/                Conexión con la API de clientes
  Security/                   Tokens de integración
  Console/                    Migración, sincronización y limpieza
integrations/n8n/              Workflow exportado de recepción de copias
database/                      Esquemas SQL
tests/                         Pruebas de ingesta
docs/                          Documentación técnica
config/                        Ejemplo y configuración privada local
assets/                        Logo y estilos del login
build/                         Recursos compilados para producción
```

Los PHP de la raíz son delegadores pequeños. Sus nombres y URLs se conservan, así como los comandos `php migrate.php`, `php sync.php` y `php purge.php`. Añade la lógica en `src/`, sin hacer crecer los delegadores. Apache bloquea el acceso directo a `src/`.

El workflow local de n8n sigue excluido de Git por `.gitignore`; su traslado no lo publica. `config/config.php` también sigue excluido.

## Compilar y probar en local

Desde `frontend/`:

```sh
npm install
npm run build
npm run preview:demo
```

La demo usa datos ficticios y bloquea cambios. El desarrollo con datos reales continúa usando `npm run dev` y un servidor PHP configurado. `build/` se versiona porque Plesk sirve los archivos ya compilados.

Para desplegar esta reorganización, publica el conjunto de cambios completo: los delegadores PHP necesitan sus nuevas implementaciones y el frontend compilado. La reorganización no requiere migraciones de base de datos.
