# Panel de Control de Copias · Sauber Ofimática

Aplicación web en **PHP 8 + MySQL/MariaDB** con panel en **React + Vite**. La raíz de este repositorio es la web: lo que ves aquí es lo que hay en el servidor.

## Estructura

Los PHP de la raíz son puntos de entrada conservados para no cambiar las URL del panel, n8n ni las tareas de Plesk. Su implementación está agrupada por responsabilidad dentro de `src/`:

```
api.php, ingest.php                 Entradas HTTP del panel y recepción de n8n
sync.php, migrate.php, purge.php   Entradas CLI compatibles con las tareas de Plesk
index.php, login.php, clientes.php Páginas del panel
src/Http/                          Implementaciones de endpoints y respuesta JSON común
src/Http/Controllers/              Acciones de lectura, historial, clientes, usuarios, equipos y revisión
src/Console/                       Comandos de sincronización, migración y limpieza
src/Backup/                        Ingesta de avisos, equipos y copias
src/Integration/ClientApi.php      Sincronización e inicio de sesión con la API de clientes
src/Security/ApiTokens.php          Creación y validación de tokens de n8n
src/bootstrap.php                  Configuración, sesión, PDO y seguridad común
database/                           Esquema y SQL de las tablas
frontend/                           Código fuente React
build/                              Panel compilado que se despliega
```

Las rutas activas del panel y n8n, y los comandos de Plesk, se conservan. Para añadir lógica nueva, coloca el código en el módulo de su área y deja las entradas de la raíz como delegadores pequeños.

## Primera instalación

1. Crea una base de datos MySQL y su usuario en Plesk.
2. Copia `config/config.example.php` como `config/config.php` y pon los datos de la BD/API.
3. Importa `database/schema.sql` en la base de datos desde phpMyAdmin.
4. Ejecuta `php migrate.php` desde la raíz del proyecto y con el mismo PHP/configuración que usa la web.
5. Comprueba que puedes iniciar sesión con un usuario permitido de la API.

Requisitos: PHP 8.0+ con PDO MySQL y mbstring, MySQL 5.7+ / MariaDB 10.3+.

## Actualizar sin copiar ficheros: Git + Plesk

Flujo: **editas → `git push` → Plesk se actualiza solo.**

### 1. Subir el proyecto a un repositorio (una vez, en tu ordenador)
Crea un repositorio **privado** vacío en GitHub (o GitLab/Bitbucket) y, dentro de esta carpeta:
```
git init -b main
git add .
git commit -m "Primera versión"
git remote add origin https://github.com/TU_USUARIO/panel-copias.git
git push -u origin main
```
Comprueba con `git status` que **`config/config.php` no aparece**: es el fichero con tus contraseñas.

### 2. Conectar Plesk (una vez)
1. *Sitios web y dominios → tu dominio → **Git** → Añadir repositorio*.
2. Elige **Repositorio remoto** y pega la URL.
3. Si es privado, Plesk muestra una **clave SSH pública**: cópiala en GitHub → repositorio → *Settings → Deploy keys → Add* (solo lectura). Usa entonces la URL SSH (`git@github.com:...`).
4. Rama: `main`. **Ruta del servidor** (deployment path): la carpeta de la web, por ejemplo `httpdocs`.
5. Modo de despliegue: **Automático**. Plesk te da una **URL de webhook**.
6. En GitHub → *Settings → Webhooks → Add webhook*: pega esa URL, tipo `application/json`, evento *push*.

**Antes del primer despliegue**, si `httpdocs` ya contiene la web actual: haz copia de `config/config.php`, vacía la carpeta, despliega y vuelve a poner `config/config.php`. Después de eso, los despliegues solo actualizan los ficheros del repositorio y **no tocan `config/config.php`** (no está en Git). Compruébalo tras el primer `push`.

### 3. Día a día
```
git add .
git commit -m "Descripción del cambio"
git push
```
En unos segundos la web está actualizada. Si no se actualiza, en Plesk → Git → *Registro* verás el motivo; también puedes pulsar *Extraer cambios* para forzarlo.

## Cambiar el diseño

El código está en `frontend/src/`. Necesitas **Node.js 18+** en tu ordenador:
```
cd frontend
npm install
npm run build      # regenera ../build/app.js y app.css
```
Luego `git add .`, `commit` y `push` (incluye la carpeta `build/`: el servidor no compila nada).

Para ver cambios en vivo: `php -S localhost:8000` en la raíz del repo, entra en `http://localhost:8000/login.php` y en otra terminal, dentro de `frontend/`, `npm run dev`.

```
frontend/src/
  App.jsx               Une las piezas
  styles.css            Colores y aspecto
  logic.js              Reglas ERROR / EN REVISIÓN / OK, filtros
  api.js                Único fichero que habla con el servidor
  hooks/useCopias.js    Carga y acciones
  components/           Header, Kpis, Filtros, Tabla, Detalle, Revision, Seguimiento, Notas, Historial, Usuarios
```

## Usuarios
El acceso se valida con las credenciales personales en la API y los usuarios configurados en `api_sync.usuarios_permitidos`. La primera vez que alguien inicia sesión se crea un único perfil local ligado al ID de usuario de la API; iniciar con DNI o correo no crea cuentas separadas. Configura en `admins` el DNI o correo exacto de cada administrador. Los intentos fallidos se limitan persistentemente por IP y usuario. Las contraseñas se gestionan en la API.

## Cambios en la base de datos

Las peticiones web no alteran el esquema. Cada cambio de base de datos debe añadirse como una migración versionada en `migrate.php` y ejecutarse después del despliegue con `php migrate.php`. El comando es seguro al repetirse; la web muestra un error explícito si falta aplicarlo.
Las pruebas puras de clasificación y asunto se ejecutan con `php tests/ingesta_test.php`.

## Clientes desde la API

El panel copia los **clientes** (tabla `Cliente`) de tu API a su propia base de datos. La conexión la hace siempre el servidor: el email y la contraseña de la API solo existen en `config/config.php` y nunca llegan al navegador. La antigua sincronización de máquinas ya no se utiliza.

### 1. Configurar (una vez, en el servidor)
Edita `config/config.php` (no está en Git, así que se cambia a mano en Plesk) y añade, junto a `db`:
```php
'albelink' => [
    'base_url' => 'https://api.albelink.es/api/v1',
    'email'    => 'tu_usuario_de_la_api',
    'password' => 'tu_contraseña_de_la_api',
    'org'      => '1',        // según la documentación: /db/data/{org}/{proyecto}/{Tabla}
    'proyecto' => 'demo',     // nombre del proyecto NocoDB (cámbialo por el tuyo)
    'tabla_clientes' => 'Cliente',
    'tabla_cliservicios' => 'Cliservicios',
    'tabla_usuarios' => 'Usuarios',
    'usuarios_permitidos' => [
        'dni' => ['DNI_MAXIMILIANO', 'DNI_MIGUEL'],
        'correo_nombre' => ['Alberto'],
    ],
    'servicio_requerido' => 'VBA3KT7GN7',
    'sync_max_caida_pct' => 30,
],
```
La sincronización lee `Cliente` y `Cliservicios`, y solo muestra clientes cuyas filas de `Cliservicios` incluyen el código `VBA3KT7GN7` en el campo de artículo/servicio. No consulta la tabla `Articulo`. Los clientes que dejan de cumplir el filtro se desactivan (no se borran) y se conservan las asignaciones de equipos. Una respuesta vacía, sin clientes elegibles o que implique una caída superior al 30 % aborta la sincronización. Si la API no permite reconocer las relaciones, registra un error y no cambia los datos locales.
`config/config.example.php` trae la plantilla. El panel necesita la extensión **cURL** de PHP (suele venir activa; si no, en Plesk → Ajustes de PHP).

### 2. Es automático
La sincronización se ejecuta mediante la tarea CLI `sync.php`; no bloquea peticiones web ni se dispara al abrir el panel. Programa la tarea en Plesk según la frecuencia deseada.

Si falla (contraseña rechazada, API caída...), no se cae nada: el error queda registrado y el administrador lo ve en **🏢 Clientes**. El botón **🔄 Sincronizar ahora** (solo administradores) sirve para forzarla o para la primera prueba; al acabar muestra **"Campos que devuelve la API"**, útil si algún dato no aparece.

### 3. Refuerzo opcional: tarea programada en Plesk
Añade una tarea programada para sincronizar clientes y limpiar logs:
*Sitios web y dominios → tu dominio → **Tareas programadas** → Añadir tarea*:
- Tipo: **Ejecutar un script PHP** (o *Ejecutar un comando*).
- Ruta del script: `httpdocs/sync.php`. Con *Ejecutar un comando*: `/opt/plesk/php/8.3/bin/php /var/www/vhosts/TU_DOMINIO/httpdocs/sync.php` (ajusta versión de PHP y ruta).
- Frecuencia: la que prefieras, por ejemplo cada hora (`0 * * * *`).
- Notificación: solo si hay errores.

`sync.php`, `migrate.php` y `purge.php` solo funcionan por línea de comandos. Ejecuta `php migrate.php` después de cada despliegue que incluya cambios de esquema. Programa también `purge.php` (por ejemplo, una vez al día) para eliminar logs y staging importado de más de `'maintenance_retention_days'` días; conserva las copias finales y los avisos pendientes de asignar.

### Cómo se guardan los datos
- Cada registro se identifica por su `Clave` de la API: si ya existe se actualiza, si es nuevo se crea.
- Lo que desaparece de la API se **desactiva**, no se borra. Si la API devolviera 0 filas, no se desactiva nada (más probable un fallo que un catálogo vacío).
- Se guarda además el registro completo en bruto (columna `datos`), por si necesitas campos que hoy no se muestran.
- Es una copia de **solo lectura**: el panel nunca escribe en tu API.

## Recepción de copias desde n8n

El workflow de n8n lee el buzón IMAP, clasifica cada aviso y envía el resultado a ingest.php con un token Bearer. El panel no se conecta al buzón ni procesa correos por su cuenta.

Cada aviso debe incluir un identificador estable (message_id) para que la ingesta pueda reconocer reintentos y evitar duplicados. Los avisos aceptados crean o reutilizan un equipo; los que aún no tienen cliente quedan en staging hasta que se asignen desde **Clientes**.

## Seguridad incluida

- Contraseñas verificadas contra la API, sesión HttpOnly/SameSite y límites persistentes de intentos por IP/usuario, no por cookie.
- Consultas preparadas (PDO), salida escapada, token CSRF en acciones que modifican datos.
- Reglas de negocio validadas también en el servidor.
- Las credenciales de la API nunca salen del servidor; `sync.php` no es accesible desde la web.
- Cabeceras CSP, HSTS cuando se detecta HTTPS y cookie segura; si Plesk termina HTTPS en un proxy confiable, configura `'trust_proxy_https' => true`.
- Los secretos (`config/config.php`) nunca van al repositorio.

El panel alerta si n8n no registra recepciones dentro de `'ingest_alert_hours'` (24 horas por defecto). Los intentos fallidos de autenticación de ingesta se limitan por IP y los errores quedan en el log PHP del servidor.
