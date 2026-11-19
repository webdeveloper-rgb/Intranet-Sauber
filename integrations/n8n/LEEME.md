# Descartar notificaciones de prueba

El nodo Code `Descartar mensajes de prueba` va inmediatamente después de `IMAP Email Trigger`, antes de los filtros y de la clasificación con IA. Usa el contenido de [filtrar-mensajes-prueba.js](filtrar-mensajes-prueba.js), en modo **Run Once for All Items**.

Descarta mensajes cuyo asunto o cuerpo identifica una notificación de prueba, incluido «Este es un mensaje de notificación de prueba», su variante con `�`, HTML y mensajes equivalentes en inglés. No filtra por nombres de equipos, clientes ni direcciones de correo. Tampoco descarta una copia por mencionar una prueba de restauración o un error de comprobación de conexión.

El workflow local `revisar_copias (panel).json` ya contiene el nodo conectado. Ese JSON sigue excluido de Git. Para activar el filtro, actualiza el workflow de n8n con el archivo local, o inserta el nodo Code con el contenido del script y vuelve a publicar/activar el flujo. Subir PHP a Plesk no modifica n8n.

El receptor `ingest.php` añade una segunda barrera si recibe esas expresiones en el asunto, cuerpo o evidencia, o un indicador explícito `es_prueba: true`, `is_test: true` o `resultado: "PRUEBA"`. Responde HTTP 200 para las pruebas ignoradas, informa `ignorados` y `motivo: "mensaje_de_prueba"` en el detalle y las contabiliza en el registro de recepción. No crea equipos ni resultados de copia para ellas.

Los datos guardados antes del filtro se conservan. Este cambio no modifica el esquema ni requiere ejecutar `php migrate.php`.

Prueba del receptor:

```sh
php tests/ingesta_pruebas_test.php
```
