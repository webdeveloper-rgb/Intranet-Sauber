# Panel de Control de Copias · Sauber Ofimática

Aplicación web en **PHP 8 + MySQL/MariaDB** con panel en **React + Vite**. La raíz de este repositorio es la web: lo que ves aquí es lo que hay en el servidor.

## Estructura

```
index.php, login.php, logout.php   Panel y acceso
api.php                            API interna (copias, revisión, notas, usuarios)
install.php                        Instalador (se usa UNA vez y se borra)
sync.php                           Sincroniza clientes (solo desde la tarea programada)
build/                             Panel compilado (app.js, app.css). SE GUARDA EN GIT
assets/                            logo.png y login.css
config/config.example.php          Plantilla de configuración (va en Git)
config/config.php                  TUS datos de BD. NO va en Git (.gitignore). Se crea a mano en el servidor
database/schema.sql                Tablas
src/bootstrap.php                  Sesión, BD, seguridad
src/albelink.php                   Conexión con la API y sincronización
database/sync.sql                  Tablas de clientes y registro de sincronización (se crean solas)
frontend/                          Código fuente de React (se despliega, pero .htaccess lo bloquea)
```

## Primera instalación

1. Crea una base de datos MySQL y su usuario en Plesk.
2. Copia `config/config.example.php` como `config/config.php` y pon los datos de la BD.
3. Abre `https://tu-dominio/install.php`, crea el administrador y **borra `install.php`**.

Requisitos: PHP 8.0+ con PDO MySQL, MySQL 5.7+ / MariaDB 10.3+.

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

Desde el panel, como administrador: **👥 Usuarios** (arriba a la derecha). Crear usuarios (Técnico o Administrador), desactivar/activar, restablecer contraseñas. Cada usuario cambia la suya en **🔑 Mi cuenta**. Un administrador no puede desactivarse ni quitarse el rol a sí mismo.

## Cambios en la base de datos

`database/schema.sql` solo lo usa el instalador. Si un día cambias las tablas, el servidor **no** las migra solo (salvo los usuarios): aplica el cambio en phpMyAdmin o añade una migración como la de `migrar_usuarios()` en `src/bootstrap.php`.

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
],
```
`config/config.example.php` trae la plantilla. El panel necesita la extensión **cURL** de PHP (suele venir activa; si no, en Plesk → Ajustes de PHP).

### 2. Es automático
No hay que hacer nada más: cuando alguien usa el panel, el servidor comprueba cuándo fue la última sincronización y, si han pasado **15 minutos**, vuelve a sincronizar por su cuenta. Lo hace **después** de responder al navegador, así que nadie espera. Se cambia con `'sync_minutos'` en `config.php` (`0` la desactiva).

Si falla (contraseña rechazada, API caída...), no se cae nada: el error queda registrado y el administrador lo ve en **🏢 Clientes**. El botón **🔄 Sincronizar ahora** (solo administradores) sirve para forzarla o para la primera prueba; al acabar muestra **"Campos que devuelve la API"**, útil si algún dato no aparece.

### 3. Refuerzo opcional: tarea programada en Plesk
La sincronización automática solo ocurre **mientras alguien tiene el panel abierto**. Si quieres que los datos estén al día aunque nadie entre (por ejemplo por la noche), añade además una tarea programada:
*Sitios web y dominios → tu dominio → **Tareas programadas** → Añadir tarea*:
- Tipo: **Ejecutar un script PHP** (o *Ejecutar un comando*).
- Ruta del script: `httpdocs/sync.php`. Con *Ejecutar un comando*: `/opt/plesk/php/8.3/bin/php /var/www/vhosts/TU_DOMINIO/httpdocs/sync.php` (ajusta versión de PHP y ruta).
- Frecuencia: la que prefieras, por ejemplo cada hora (`0 * * * *`).
- Notificación: solo si hay errores.

`sync.php` solo funciona por línea de comandos: desde el navegador devuelve 404 y el `.htaccess` además lo bloquea.

### Cómo se guardan los datos
- Cada registro se identifica por su `Clave` de la API: si ya existe se actualiza, si es nuevo se crea.
- Lo que desaparece de la API se **desactiva**, no se borra. Si la API devolviera 0 filas, no se desactiva nada (más probable un fallo que un catálogo vacío).
- Se guarda además el registro completo en bruto (columna `datos`), por si necesitas campos que hoy no se muestran.
- Es una copia de **solo lectura**: el panel nunca escribe en tu API.

## Copias recibidas por correo (IMAP)

Configura en `config/config.php` la sección `correo_backup` con el servidor, puerto, usuario y contraseña del buzón que ya recibe los avisos. La plantilla está en `config/config.example.php`. La conexión se realiza en el servidor y necesita la extensión IMAP de PHP.

El botón **Leer avisos** revisa los mensajes no leídos. Acronis e Iperius se reconocen por el asunto y el cuerpo del informe; los avisos que identifiquen VMware, vSphere o ESXi se procesan cuando el asunto o el informe indica equipo y resultado. Los correos de otros formatos quedan sin marcar como leídos para no perderlos.

Cada aviso reconocido crea o reutiliza un equipo por nombre y programa. En **Clientes**, el administrador asigna ese equipo a un cliente. Desde ese momento las copias pendientes del equipo aparecen en el panel con fecha, resultado y descripción del error; los avisos nuevos de un equipo ya asignado se incorporan automáticamente. La lectura automática se ejecuta cada cinco minutos cuando se usa el panel. La tarea programada `sync.php` también lee el buzón.

## Seguridad incluida

- Contraseñas con `password_hash`, sesión HttpOnly/SameSite, bloqueo tras 5 intentos fallidos.
- Consultas preparadas (PDO), salida escapada, token CSRF en acciones que modifican datos.
- Reglas de negocio validadas también en el servidor.
- Las credenciales de la API nunca salen del servidor; `sync.php` no es accesible desde la web.
- `.htaccess` bloquea `config/`, `src/`, `database/`, `frontend/` y ficheros ocultos o de código fuente.
- Los secretos (`config/config.php`) nunca van al repositorio.
