# Arquitectura del panel

Este documento explica las responsabilidades del codigo, el recorrido de las peticiones y las reglas que conviene conservar al modificar el proyecto. La aplicacion se divide en una interfaz React compilada, un backend PHP y una base de datos MySQL/MariaDB.

## Mapa del proyecto

| Ruta | Responsabilidad |
|---|---|
| `index.php`, `login.php`, `logout.php`, `clientes.php` | Entrada HTML, autenticacion, cierre de sesion y compatibilidad con rutas antiguas. |
| `api.php` | Punto unico de entrada de las acciones JSON del panel. |
| `ingest.php` | Punto de entrada de avisos de n8n, protegido con token Bearer. |
| `src/bootstrap.php` | Configuracion, PDO, sesion, CSRF, escape HTML y utilidades comunes. |
| `src/Http/ApiEndpoint.php` | Autorizacion, validacion de sesion/CSRF y despacho hacia controladores. |
| `src/Http/Controllers/` | Acciones de lectura, historial, clientes, equipos, tokens y revision de copias. |
| `src/Backup/` | Ingesta de avisos, gestion de equipos y lectura de copias. |
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

## API del panel

`api.php` delega en `src/Http/ApiEndpoint.php`. `ReadController` atiende lecturas. Los controladores de historial, cliente, administracion, equipo y revision se ejecutan solo para POST despues de validar CSRF. Las acciones administrativas comprueban el rol `admin` en servidor.

| Accion | Uso |
|---|---|
| `sesion`, `novedades`, `list`, `history`, `equipo_historial` | Sesion, actualizacion incremental, copias recientes e historiales paginados. |
| `catalogo`, `equipos`, `sync` | Catalogo de clientes, equipos detectados y sincronizacion externa. |
| `equipo_asignar`, `equipo_desasignar`, `equipo_vigilar`, `equipo_horario` | Asignacion y seguimiento del equipo. |
| `revisar`, `reabrir`, `seguimiento`, `nota` | Ciclo de revision de una copia fallida. |
| `tokens`, `token_crear`, `token_revocar` | Gestion administrativa de credenciales n8n. |

## Datos principales

- `usuarios`: perfiles locales enlazados con usuarios permitidos por la API.
- `copias`: historial visible de copias, revision, seguimiento y metadatos.
- `notas`: comentarios asociados a una copia; se eliminan junto con ella.
- `equipos`: identidad del host, software, cliente, tipo y dias esperados de copia.
- `correo_copias`: avisos recibidos por n8n en staging, con clave unica para deduplicacion.
- `correo_sync_log`: estado de las recepciones e ingestas.
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
- Las pruebas actuales de clasificacion se ejecutan con `php tests/ingesta_test.php`.

## Convenciones de comentarios

Los comentarios explican intencion, reglas de negocio, seguridad, efectos secundarios y decisiones no obvias. No repiten lo que ya expresa claramente una instruccion. Las funciones publicas deben documentar proposito, entradas relevantes, resultado y errores o efectos cuando no sean evidentes.