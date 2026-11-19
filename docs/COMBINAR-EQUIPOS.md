# Combinar equipos duplicados

Disponible para administradores en **Clientes → ficha del equipo → Configuración → Combinar equipos duplicados**.

1. Elige la ficha que se conservará como destino. Conserva su nombre, cliente, tipo y configuración de avisos por falta de copias.
2. Busca y marca entre uno y veinte equipos de origen.
3. Pulsa **Revisar combinación** para consultar los registros que se trasladarán.
4. Si hay agentes, elige en **Agente que se conserva** el instalado actualmente. Se muestran su ID, última conexión, hostname, sistema y modelo. Si hay varios, la elección es obligatoria.
5. Confirma que las fichas pertenecen al mismo equipo físico y pulsa **Confirmar y combinar**.

El destino recibe el historial y staging de copias, las notas y revisiones ligadas a cada copia, eventos, alertas y actividad de copias en curso. Se mantienen los identificadores de las copias para conservar notas y referencias. Las copias trasladadas muestran el nombre y cliente del destino. Los registros ya importados no se duplican. Los orígenes desaparecen del listado.

Los nombres y productos anteriores se guardan en `equipo_nombres`. La ingesta los consulta antes de crear equipos nuevos, de modo que las próximas copias con esos nombres quedan en la ficha de destino. Los nombres vinculados se pueden consultar en el mismo panel. Las búsquedas de actividad de copia por hostname también reconocen esos nombres.

Solo se permite combinar equipos del mismo cliente, o un origen sin asignar con un destino asignado. Para otros casos hay que corregir primero la asignación. Se pueden combinar fichas con agentes e IDs distintos: se conserva únicamente la identidad elegida, su informe, contraseña cifrada de RustDesk y configuración. Si hay un único agente, se selecciona automáticamente. Los informes posteriores no sustituyen el nombre elegido para un destino combinado.

Los agentes descartados quedan con `active=0` en `monitor_agents` y sus tokens temporales pendientes se eliminan. También se comprueba la desactivación al consumir tokens y al guardar informes o actividad, para bloquear envíos que estuvieran en curso. Sus eventos e historial se conservan en el destino. Esto revoca su acceso al servidor; no desinstala el programa de los equipos. Para autorizar de nuevo una identidad retirada sería necesario un alta explícita con un nuevo código.

La revisión general de errores del destino se reinicia, ya que no cubría los errores recién trasladados; las revisiones individuales de las copias se conservan. La operación se ejecuta en una transacción y deja una auditoría en `equipo_combinaciones` con el usuario, las fichas originales y los recuentos. No existe una opción de deshacer en la interfaz.

Si cambian los equipos o los recuentos después de la vista previa, el servidor solicita revisar de nuevo. Las acciones están protegidas por sesión, rol de administrador y CSRF.

## Despliegue

Esta función **sí requiere migración**. Publica el conjunto completo de archivos PHP y `build/` y ejecuta desde la raíz en Plesk:

```sh
php migrate.php
```

La migración 19 crea las tablas de nombres y auditoría sin combinar equipos automáticamente.

La ampliación para elegir entre varios agentes no añade migraciones: si ya se ejecutó la 19, basta con actualizar PHP y `build/`.

## Validación local

```sh
php tests/equipos_combinar_test.php
```

Requiere PDO SQLite y mbstring. Ejecuta los servicios reales sobre una base temporal y adapta únicamente la sintaxis específica de MySQL. Comprueba traslado de historial, notas, agente, eventos, staging, alias y recepción futura, además de conflictos, vista previa obsoleta y rollback. No sustituye una comprobación del despliegue en MySQL/MariaDB.
