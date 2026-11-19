# Descartar notificaciones de prueba

El nodo Code `Descartar mensajes de prueba` va inmediatamente después de `IMAP Email Trigger`, antes de los filtros y de la clasificación con IA. Usa el contenido de [filtrar-mensajes-prueba.js](filtrar-mensajes-prueba.js), en modo **Run Once for All Items**.

Descarta mensajes cuyo asunto o cuerpo identifica una notificación de prueba, incluido «Este es un mensaje de notificación de prueba», su variante con `�`, HTML y mensajes equivalentes en inglés. No filtra por nombres de equipos, clientes ni direcciones de correo. Tampoco descarta una copia por mencionar una prueba de restauración o un error de comprobación de conexión.

El workflow local `revisar_copias (panel).json` ya contiene el nodo conectado. Ese JSON sigue excluido de Git. Para activar el filtro, actualiza el workflow de n8n con el archivo local, o inserta el nodo Code con el contenido del script y vuelve a publicar/activar el flujo. Subir PHP a Plesk no modifica n8n.

El receptor `ingest.php` añade una segunda barrera si recibe esas expresiones en el asunto, cuerpo o evidencia, o un indicador explícito `es_prueba: true`, `is_test: true` o `resultado: "PRUEBA"`. Responde HTTP 200 para las pruebas ignoradas, informa `ignorados` y `motivo: "mensaje_de_prueba"` en el detalle y las contabiliza en el registro de recepción. No crea equipos ni resultados de copia para ellas.

Los datos guardados antes del filtro se conservan. Este cambio no modifica el esquema ni requiere ejecutar `php migrate.php`.

## Limpiar pruebas recibidas anteriormente

Tras subir los archivos, desde la raíz del proyecto en el servidor Plesk:

```sh
php bin/eliminar-copias-prueba.php
php bin/eliminar-copias-prueba.php --aplicar
```

El primer comando muestra una simulación. El segundo guarda primero una copia JSON privada en `config/copias-prueba-*.json` y elimina los registros identificados del historial y del staging en una transacción. Conserva sus claves para que los reintentos no los repongan. La copia incluye las notas que se eliminarán con esos registros y queda excluida de Git.

La detección usa solo la descripción, evidencia o resultado del propio aviso y su clave de mensaje; no elimina por nombre del equipo ni por la pertenencia al mismo equipo. Las fichas y los informes de monitorización se conservan. Si el cuerpo de prueba no quedó guardado en esos campos, el comando no puede identificarlo: no borra registros por suposición. No requiere migraciones.

Prueba del receptor:

```sh
php tests/ingesta_pruebas_test.php
```
