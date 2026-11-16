# Arquitectura del panel

Este documento explica las responsabilidades del codigo, el recorrido de las peticiones y las reglas que conviene conservar al modificar el proyecto. La aplicacion se divide en una interfaz React compilada, un backend PHP y una base de datos MySQL/MariaDB.

## Mapa del proyecto

| Ruta | Responsabilidad |
|---|---|
| `index.php`, `login.php`, `logout.php`, `clientes.php` | Entrada HTML, autenticacion, cierre de sesion y compatibilidad con rutas antiguas. |
| `api.php` | Punto unico de entrada de las acciones JSON del panel. |
| `ingest.php` | Punto de entrada de avisos de n8n, protegido con token Bearer. |
| `monitor-ingest.php` | Receptor autenticado de informes de hardware y alertas de Monitor-Equipos. |
| `monitor-enroll.php` | Canjea códigos de alta de un solo uso por credenciales de agente individuales. |
| `monitor-token.php` | Intercambia la credencial individual de un agente por un token temporal de un solo uso. |
| `src/bootstrap.php` | Configuracion, PDO, sesion, CSRF, escape HTML y utilidades comunes. |
| `src/Http/ApiEndpoint.php` | Autorizacion, validacion de sesion/CSRF y despacho hacia controladores. |
| `src/Http/Controllers/` | Acciones de lectura, historial, clientes, equipos, tokens y revision de copias. |
| `src/Backup/` | Ingesta de avisos, gestion de equipos y lectura de copias. |
| `src/Monitoring/MonitorService.php` | Normalizacion de informes, asociacion de máquinas y alertas de salud. |
| `src/Security/ApiTokens.php` | Creacion, autenticacion y registro de uso de tokens para integraciones. |
| `src/Integration/ClientApi.php` | Cliente HTTP de la API externa de clientes y sincronizacion local. |
| `src/Console/` | Migraciones y tareas CLI de sincronizacion y retencion. |
| `database/` | Esquema inicial, tablas de equipos y tablas historicas de sincronizacion. |
| `frontend/src/` | Aplicacion React: rutas, estado, peticiones y componentes de interfaz. |
| `frontend/src/styles.css` | Estilos de la interfaz. |
| `build/` | JavaScript y CSS compilados para produccion. Se generan desde `frontend/`. |
| `revisar_copias (panel).json` | Workflow local de n8n: clasifica correo y llama a `ingest.php`. |

Los archivos PHP de la raiz son puntos de entrada de compatibilidad. La logica nueva debe ir en el modulo que corresponda, no crecer dentro de esos delegadores.

## Flujo de autenticacion del panel

1. `login.php` recibe credenciales, limita intentos persistentes y delega la validacion a `ClientApi.php`.
2. Al autenticar, el backend crea o recupera el usuario local permitido, inicia una sesion protegida y rota su identificador.
3. `index.php` exige sesion antes de servir React.
4. React llama a `api.php?action=sesion` para recuperar usuario y token CSRF.
5. Las acciones que cambian datos usan POST y envian CSRF en `X-CSRF-Token`. El servidor comprueba de nuevo sesion, estado del usuario y permisos.

## Flujo de copias desde n8n

1. El trigger IMAP recibe un mensaje. El workflow clasifica el aviso y conserva un `message_id` estable.
2. El nodo HTTP envia avisos a `ingest.php` con `Authorization: Bearer ...` y JSON.
3. `IngestEndpoint.php` valida metodo, tamano, JSON, limite de elementos y token.
4. `IngestionService.php` adapta el formato n8n, aplica reglas conocidas del asunto, normaliza campos y rechaza valores invalidos.
5. `ingesta_guardar()` usa una transaccion: crea o reutiliza el equipo, asigna cliente solo si la sugerencia coincide con un unico cliente activo e inserta el aviso en staging.
6. `message_key` es unico. Reintentar el mismo mensaje no duplica la copia.
7. Los avisos en staging se muestran en el panel. Al asignar el equipo, `EquipmentService.php` importa las copias pendientes al historial definitivo.

La ingesta y el panel usan credenciales diferentes: n8n utiliza un token revocable; las personas usan sesion web y proteccion CSRF.

## Informes de Monitor-Equipos

1. Durante la instalación, Monitor-Equipos canjea en `monitor-enroll.php` un código de alta de un solo uso por una credencial individual. El código caduca en 15 minutos, se consume atómicamente y un reintento idéntico es seguro si se pierde la respuesta.
2. El agente guarda su credencial individual en `%ProgramData%\MonitorAgent\config.json`, protegido para SYSTEM y Administradores, y solicita un token temporal a `monitor-token.php` con `X-Machine-Id`. El servidor devuelve un token ligado a esa máquina y válido durante cinco minutos.
3. El agente envía el informe JSON por `POST` a `monitor-ingest.php` con ese token temporal y `X-Machine-Id`. Cada informe de la cola de reintentos solicita un token nuevo.
4. El receptor consume el token de forma atómica, limita el cuerpo a 2 MB, comprueba que la cabecera y `machineId` coinciden y conserva solo los campos de hardware reconocidos. Los mensajes de eventos de Windows no se guardan.
5. La primera recepción se asocia a un equipo cuyo nombre coincide exactamente (sin distinguir mayúsculas) con el hostname, si solo hay una coincidencia. Si no existe una coincidencia única, se crea un equipo MonitorAgent sin cliente; se puede asignar desde **Clientes** como cualquier otro equipo.
6. Se conserva el último informe recibido. Las alertas activas se deduplican por equipo y condición, se resuelven al desaparecer la condición y pueden volver a avisar si reaparecen.
7. Se genera una alerta crítica si SMART predice fallo o un disco figura como `Unhealthy`; una advertencia si un disco figura como `Warning`, un volumen tiene menos del 15 % libre o la salud de una batería es inferior al 50 %. Los avisos aparecen en la vista de clientes/equipos y se envían por correo cuando se configuran destinatarios.

El envío de correo usa `mail()` de PHP, por lo que también debe estar configurado el transporte de correo del servidor. Añade `monitor_alert_recipients` (lista de direcciones) y, opcionalmente, `monitor_alert_sender` a `config/config.php`; los valores vacíos de la plantilla no envían email. Los intentos fallidos quedan en el log PHP del servidor y se reintentan como máximo una vez por hora mientras la alerta siga activa.

## API del panel

`api.php` delega en `src/Http/ApiEndpoint.php`. `ReadController` atiende lecturas. Los controladores de historial, cliente, administracion, equipo y revision se ejecutan solo para POST despues de validar CSRF. Las acciones administrativas comprueban el rol `admin` en servidor.

| Accion | Uso |
|---|---|
| `sesion`, `novedades`, `list`, `history`, `equipo_historial` | Sesion, actualizacion incremental, copias recientes e historiales paginados. |
| `catalogo`, `equipos`, `sync` | Catalogo de clientes, equipos detectados y sincronizacion externa. |
| `monitor_equipo` | Informe de hardware y alertas del equipo seleccionado. |
| `equipo_asignar`, `equipo_desasignar`, `equipo_vigilar`, `equipo_horario` | Asignacion y seguimiento del equipo. |
| `revisar`, `reabrir`, `seguimiento`, `nota` | Ciclo de revision de una copia fallida. |
| `tokens`, `token_crear`, `token_revocar` | Gestion administrativa de credenciales de integraciones. |

## Datos principales

- `usuarios`: perfiles locales enlazados con usuarios permitidos por la API.
- `copias`: historial visible de copias, revision, seguimiento y metadatos.
- `notas`: comentarios asociados a una copia; se eliminan junto con ella.
- `equipos`: identidad del host, software, cliente, tipo y dias esperados de copia.
- `correo_copias`: avisos recibidos por n8n en staging, con clave unica para deduplicacion.
- `correo_sync_log`: estado de las recepciones e ingestas.
- `monitor_alertas`: alertas de salud activas y registro de notificacion por correo.
- `clientes`, `maquinas`, `sync_log`: tablas historicas de sincronizacion descritas por `database/sync.sql`; revisar su uso antes de retirarlas.
- `api_tokens`: tokens de integracion; la tabla se asegura desde el modulo de seguridad.

Los cambios de esquema deben hacerse mediante migracion versionada en `src/Console/Migrate.php` y ejecutarse con `php migrate.php`. No se deben hacer migraciones en cada peticion web.

## Interfaz y rutas

`frontend/src/App.jsx` compone vistas y estado global. `routes.js` interpreta `/copias`, `/copias/:id`, `/clientes/:cliente` y `/clientes/:cliente/equipos/:id`; `popstate` sincroniza atras/adelante del navegador. `api.js` es el cliente HTTP unico y mantiene CSRF en memoria. `useCopias.js` carga y modifica copias; `logic.js` concentra reglas visuales y calculos; `components/` contiene las piezas de interfaz.

La lista diaria consulta las ultimas 24 horas. Los historiales se cargan con cursor estable (fecha e ID) para paginar sin saltos. `App.jsx` comprueba novedades mientras la sesion esta activa.

## Reglas para cambios

- No pongas secretos en frontend, Git, logs ni el workflow exportado. `config/config.php` es local y no debe subirse al repositorio.
- Mantener validacion y permisos en backend aunque la interfaz oculte controles.
- Usar consultas PDO preparadas para datos variables y escapar HTML con `e()`.
- Mantener los limites de campos de `IngestionService.php` alineados con las columnas SQL.
- Si cambia una accion, sincronizar cliente frontend, controlador PHP y workflow n8n cuando aplique.
- No editar `build/` manualmente. Regenerarlo desde `frontend/` con `npm run build`.
- Las pruebas puras se ejecutan con `php tests/ingesta_test.php` y `php tests/monitor_ingesta_test.php`.

## Convenciones de comentarios

Los comentarios explican intencion, reglas de negocio, seguridad, efectos secundarios y decisiones no obvias. No repiten lo que ya expresa claramente una instruccion. Las funciones publicas deben documentar proposito, entradas relevantes, resultado y errores o efectos cuando no sean evidentes.