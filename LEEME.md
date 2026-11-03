# Panel de Control de Copias · Sauber Ofimática

Aplicación web en **PHP 8 + MySQL/MariaDB** con panel en **React + Vite**. La raíz de este repositorio es la web: lo que ves aquí es lo que hay en el servidor.

## Estructura

```
index.php, login.php, logout.php   Panel y acceso
api.php                            API interna (copias, revisión, notas, usuarios)
install.php                        Instalador (se usa UNA vez y se borra)
build/                             Panel compilado (app.js, app.css). SE GUARDA EN GIT
assets/                            logo.png y login.css
config/config.example.php          Plantilla de configuración (va en Git)
config/config.php                  TUS datos de BD. NO va en Git (.gitignore). Se crea a mano en el servidor
database/schema.sql                Tablas
src/bootstrap.php                  Sesión, BD, seguridad
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

## API de entrada de copias (pendiente)

No hay API para recibir copias de los equipos; queda en blanco a propósito. Cuando la tengas (Node JS u otra), solo debe insertar filas en la tabla `copias` (campos en `database/schema.sql`).

## Seguridad incluida

- Contraseñas con `password_hash`, sesión HttpOnly/SameSite, bloqueo tras 5 intentos fallidos.
- Consultas preparadas (PDO), salida escapada, token CSRF en acciones que modifican datos.
- Reglas de negocio validadas también en el servidor.
- `.htaccess` bloquea `config/`, `src/`, `database/`, `frontend/` y ficheros ocultos o de código fuente.
- Los secretos (`config/config.php`) nunca van al repositorio.
