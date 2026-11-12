<?php
declare(strict_types=1);


/**
 * Ciclo de revision de errores: revisar, reabrir, seguimiento y notas.
 */
final class CopyReviewController
{
    public static function handle(string $action, array $input, array $user): bool
    {
        if (!in_array($action, ['revisar', 'reabrir', 'seguimiento', 'nota'], true)) {
            return false;
        }

        $copy = copia_obtener((int) ($input['id'] ?? 0));
        if (!$copy) {
            fail('Copia no encontrada', 404);
        }
        $id = $copy['id'];

        switch ($action) {
            case 'revisar':
                if ($copy['estado'] !== 'ERROR') {
                    fail('Solo se pueden revisar copias con error');
                }
                if ($copy['revisado']) {
                    fail('Esta copia ya está revisada');
                }
                $description = txt($input['desc'] ?? '', 2000);
                if (palabras($description) < MIN_PALABRAS) {
                    fail('La descripción debe tener al menos ' . MIN_PALABRAS . ' palabras');
                }
                $update = db()->prepare("UPDATE copias SET revisado=1, revisado_por=?, revisado_fecha=NOW(), revision_desc=?, seg='vigilancia', recordar=NULL WHERE id=? AND estado='ERROR' AND revisado=0");
                $update->execute([$user['usuario'], $description, $id]);
                if ($update->rowCount() !== 1) {
                    fail('La copia ya fue revisada por otro usuario. Actualiza la lista.', 409);
                }
                break;

            case 'reabrir':
                if ($copy['estado'] !== 'ERROR') {
                    fail('Solo se pueden reabrir copias con error');
                }
                db()->prepare("UPDATE copias SET revisado=0, revisado_por=NULL, revisado_fecha=NULL, revision_desc=NULL, seg=NULL, recordar=NULL WHERE id=?")
                    ->execute([$id]);
                break;

            case 'seguimiento':
                if ($copy['estado'] !== 'ERROR' || !$copy['revisado']) {
                    fail('La copia debe estar revisada');
                }
                $followUp = $copy['seg'];
                $reminder = $copy['recordar'];
                if (array_key_exists('seg', $input)) {
                    if (!in_array($input['seg'], ['vigilancia', 'cerrado'], true)) {
                        fail('Seguimiento no válido');
                    }
                    $followUp = $input['seg'];
                }
                if (array_key_exists('recordar', $input)) {
                    $value = $input['recordar'];
                    if ($value === null || $value === '') {
                        $reminder = null;
                    } else {
                        $date = DateTime::createFromFormat('Y-m-d', (string) $value);
                        if (!$date || $date->format('Y-m-d') !== $value) {
                            fail('Fecha no válida');
                        }
                        $reminder = $value;
                    }
                }
                if ($followUp === 'cerrado') {
                    $reminder = null;
                }
                db()->prepare('UPDATE copias SET seg=?, recordar=? WHERE id=?')->execute([$followUp, $reminder, $id]);
                break;

            case 'nota':
                if ($copy['estado'] !== 'ERROR' || !$copy['revisado']) {
                    fail('La copia debe estar revisada');
                }
                $text = txt($input['texto'] ?? '', 2000);
                if ($text === '') {
                    fail('La nota está vacía');
                }
                db()->prepare('INSERT INTO notas (copia_id, usuario, texto) VALUES (?,?,?)')
                    ->execute([$id, $user['usuario'], $text]);
                break;
        }

        out(['copia' => copia_obtener($id)]);
        return true;
    }
}
