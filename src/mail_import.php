<?php
declare(strict_types=1);

final class CorreoError extends RuntimeException {}

function correo_backup_cfg(): array
{
    $c = cfg('correo_backup');
    if (!is_array($c) || empty($c['host']) || empty($c['usuario']) || empty($c['password'])) {
        throw new CorreoError("Configura 'correo_backup' en config/config.php con los datos del buzón IMAP.");
    }
    return $c + ['puerto' => 993, 'carpeta' => 'INBOX', 'ssl' => true];
}

function correo_decodificar_parte(string $data, int $encoding): string
{
    if ($encoding === 3) $data = base64_decode($data, true) ?: '';
    elseif ($encoding === 4) $data = quoted_printable_decode($data);
    return $data;
}

function correo_parte($imap, int $num, $part, string $prefix = ''): array
{
    $tipo = (int) ($part->type ?? 0);
    $subtipo = strtoupper((string) ($part->subtype ?? ''));
    $seccion = $prefix === '' ? '1' : $prefix;
    if ($tipo === 0 && in_array($subtipo, ['PLAIN', 'HTML'], true)) {
        $data = correo_decodificar_parte((string) imap_fetchbody($imap, $num, $seccion), (int) ($part->encoding ?? 0));
        foreach (($part->parameters ?? []) as $p) {
            if (strcasecmp((string) $p->attribute, 'charset') === 0 && strcasecmp((string) $p->value, 'UTF-8') !== 0) {
                $data = mb_convert_encoding($data, 'UTF-8', (string) $p->value);
                break;
            }
        }
        return [$subtipo, $data];
    }
    foreach (($part->parts ?? []) as $i => $child) {
        $found = correo_parte($imap, $num, $child, $prefix === '' ? (string) ($i + 1) : $prefix . '.' . ($i + 1));
        if ($found[0] === 'PLAIN') return $found;
        if ($found[0] === 'HTML') $html = $found;
    }
    return $html ?? ['', ''];
}

function correo_parse_fecha(string $s, ?int $fallback): string
{
    if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})\s+(\d{1,2}):(\d{2}):(\d{2})/', $s, $m)) {
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4], $m[5], $m[6]);
    }
    if (preg_match('/(20\d{2}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $s, $m)) return $m[1] . ' ' . $m[2];
    return date('Y-m-d H:i:s', $fallback ?: time());
}

function correo_analizar(string $subject, string $body, ?int $fallback): ?array
{
    $texto = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?\s*>|<\/p>|<\/tr>/i', "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $full = $subject . "\n" . $texto;
    if (stripos($subject, 'Acronis True Image') !== false || stripos($texto, 'Acronis') !== false) {
        if (!preg_match('/Acronis\s+True\s+Image\s+de\s+(.+)$/iu', $subject, $m)) return null;
        $equipo = trim($m[1]);
        $estado = preg_match('/se ha realizado con errores|se ha efectuado con errores|Error\s+0x[0-9a-f]+/iu', $full) ? 'ERROR'
            : (preg_match('/se ha efectuado correctamente|se ha completado correctamente/iu', $full) ? 'OK' : 'ERROR');
        $err = '';
        if ($estado === 'ERROR' && preg_match('/(?:Error\s+0x[0-9a-f]+:\s*|Error:\s*)([^\r\n]+)/iu', $texto, $m)) $err = trim($m[0]);
        return ['equipo' => mb_substr($equipo, 0, 200), 'software' => 'Acronis', 'estado' => $estado,
            'fecha' => correo_parse_fecha($texto, $fallback), 'error' => mb_substr($err, 0, 500)];
    }
    if (stripos($subject, 'Iperius Backup') !== false || stripos($texto, 'Iperius Backup') !== false) {
        $equipo = '';
        if (preg_match('/font-size:\s*16pt[^>]*>(.*?)<\/span>/is', $body, $m)) $equipo = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($equipo === '' && preg_match('/-\s*(.+?)\s*-\s*Iperius Backup\s*$/iu', $subject, $m)) $equipo = trim($m[1]);
        if ($equipo === '') return null;
        if (!preg_match('/completada correctamente|realizada correctamente|finalizada correctamente|con errores|Error:/iu', $full)) return null;
        $estado = preg_match('/finalizada con errores|con errores|Error:/iu', $full) ? 'ERROR' : 'OK';
        $err = '';
        if ($estado === 'ERROR' && preg_match('/Error:\s*([^\r\n]+)/iu', $texto, $m)) $err = trim($m[0]);
        return ['equipo' => mb_substr($equipo, 0, 200), 'software' => 'Iperius', 'estado' => $estado,
            'fecha' => correo_parse_fecha($texto, $fallback), 'error' => mb_substr($err, 0, 500)];
    }
    if (preg_match('/VMware|vSphere|ESXi/i', $subject . ' ' . $texto)) {
        $equipo = '';
        if (preg_match('/(?:virtual machine|VM|equipo|host)\s*[:=]\s*[\'\"]?([^\'\"\r\n,;]+)/iu', $subject, $m)) $equipo = trim($m[1]);
        if ($equipo === '') {
            $equipo = preg_replace('/\b(?:VMware|vSphere|ESXi|backup|copia de seguridad|job|trabajo|report|informe|completed|completado|successfully|correctamente|failed|failure|error|fallido|fallida)\b/iu', ' ', $subject);
            $equipo = trim(preg_replace('/[\s:|–—-]+/u', ' ', $equipo));
        }
        if ($equipo === '' || (!preg_match('/success|correct|complete|éxito|exito|satisfact/i', $full) && !preg_match('/fail|error|fallid/i', $full))) return null;
        $estado = preg_match('/fail|error|fallid/i', $full) ? 'ERROR' : 'OK';
        $err = '';
        if ($estado === 'ERROR' && preg_match('/[^\r\n]*(?:Error|failed|fall[oó])[^\r\n]*/iu', $texto, $m)) $err = trim($m[0]);
        return ['equipo' => mb_substr(trim($equipo), 0, 200), 'software' => 'VMware', 'estado' => $estado,
            'fecha' => correo_parse_fecha($texto, $fallback), 'error' => mb_substr($err, 0, 500)];
    }
    return null;
}

function correo_importar(): array
{
    if (!function_exists('imap_open')) throw new CorreoError('PHP no tiene la extensión IMAP activada en el servidor.');
    $c = correo_backup_cfg();
    $flags = !empty($c['ssl']) ? '/imap/ssl' : '/imap/notls';
    $mailbox = sprintf('{%s:%d%s}%s', $c['host'], (int) $c['puerto'], $flags, $c['carpeta']);
    $pdo = db();
    if (!(int)$pdo->query("SELECT GET_LOCK('correo_backup_import', 0)")->fetchColumn()) throw new CorreoError('Ya hay una lectura del correo en curso.');
    $imap = @imap_open($mailbox, $c['usuario'], $c['password']);
    if (!$imap) { $pdo->query("SELECT RELEASE_LOCK('correo_backup_import')"); throw new CorreoError('No se pudo conectar con el buzón IMAP: ' . (imap_last_error() ?: 'comprueba la configuración.')); }
    $nuevas = 0; $omitidas = 0;
    try {
        $uids = imap_search($imap, 'UNSEEN', SE_UID) ?: [];
        foreach ($uids as $uid) {
            $num = imap_msgno($imap, (int) $uid);
            if (!$num) continue;
            $ov = imap_fetch_overview($imap, (string) $num, 0)[0] ?? null;
            if (!$ov) continue;
            $subj = '';
            foreach (imap_mime_header_decode((string) ($ov->subject ?? '')) as $part) $subj .= $part->text;
            $structure = imap_fetchstructure($imap, $num);
            [, $body] = correo_parte($imap, $num, $structure);
            if ($body === '' && empty($structure->parts)) $body = correo_decodificar_parte((string) imap_body($imap, $num), (int) ($structure->encoding ?? 0));
            $parsed = correo_analizar($subj, $body, isset($ov->udate) ? (int) $ov->udate : null);
            if (!$parsed) { $omitidas++; continue; }
            $key = (string) imap_uid($imap, $num);
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO equipos (nombre, software) VALUES (?,?) ON DUPLICATE KEY UPDATE software=VALUES(software)')->execute([$parsed['equipo'], $parsed['software']]);
            $st = $pdo->prepare('SELECT id, cliente_id FROM equipos WHERE nombre=? AND software=?');
            $st->execute([$parsed['equipo'], $parsed['software']]);
            $equipo = $st->fetch();
            $pdo->prepare('INSERT IGNORE INTO correo_copias (message_key,equipo_id,fecha,estado,desc_error,software) VALUES (?,?,?,?,?,?)')
                ->execute([$key, $equipo['id'], $parsed['fecha'], $parsed['estado'], $parsed['error'], $parsed['software']]);
            if ($equipo['cliente_id']) correo_importar_equipo((int) $equipo['id'], (int) $equipo['cliente_id'], $pdo);
            $pdo->commit();
            imap_setflag_full($imap, (string) $num, '\Seen');
            $nuevas++;
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    } finally { imap_close($imap); $pdo->query("SELECT RELEASE_LOCK('correo_backup_import')"); }
    return ['leidas' => $nuevas, 'omitidas' => $omitidas];
}

function correo_backup_auto(): void
{
    if (!is_array(cfg('correo_backup')) || empty(cfg('correo_backup')['host'])) return;
    try {
        $pdo = db();
        asegurar_tablas_sync($pdo);
        if (!(int)$pdo->query("SELECT GET_LOCK('correo_backup_auto', 0)")->fetchColumn()) return;
        try {
            $pdo->exec('INSERT IGNORE INTO correo_sync_estado (id) VALUES (1)');
            $st = $pdo->query('SELECT revisado FROM correo_sync_estado WHERE id=1');
            $last = $st->fetchColumn();
            if ($last && strtotime((string)$last) > time() - 300) return;
            $pdo->exec('UPDATE correo_sync_estado SET revisado=NOW() WHERE id=1');
            correo_importar();
        } finally { $pdo->query("SELECT RELEASE_LOCK('correo_backup_auto')"); }
    } catch (Throwable $e) { error_log('[copias] lectura IMAP automática: ' . $e->getMessage()); }
}

function correo_importar_equipo(int $equipoId, int $clienteId, ?PDO $pdo = null): int
{
    $pdo ??= db();
    $st = $pdo->prepare('SELECT nombre FROM equipos WHERE id=?'); $st->execute([$equipoId]); $nombreEquipo = $st->fetchColumn();
    $st = $pdo->prepare('SELECT nombre FROM clientes WHERE id=? AND activo=1'); $st->execute([$clienteId]); $nombreCliente = $st->fetchColumn();
    if (!$nombreEquipo || !$nombreCliente) return 0;
    $pend = $pdo->prepare('SELECT * FROM correo_copias WHERE equipo_id=? AND importada=0 ORDER BY fecha,id'); $pend->execute([$equipoId]);
    $insert = $pdo->prepare('INSERT INTO copias (fecha,cliente,equipo,equipo_id,estado,desc_error,software) VALUES (?,?,?,?,?,?,?)');
    $mark = $pdo->prepare('UPDATE correo_copias SET importada=1 WHERE id=?');
    $n = 0;
    $pdo->prepare('UPDATE copias SET cliente=? WHERE equipo_id=?')->execute([$nombreCliente,$equipoId]);
    foreach ($pend as $r) { $insert->execute([$r['fecha'],$nombreCliente,$nombreEquipo,$equipoId,$r['estado'],$r['desc_error'],$r['software']]); $mark->execute([$r['id']]); $n++; }
    return $n;
}
