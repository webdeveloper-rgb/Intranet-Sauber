<?php
declare(strict_types=1);

require_once __DIR__ . '/mail_parser.php';
require_once __DIR__ . '/equipos.php';

final class CorreoError extends RuntimeException {}
const MAIL_PARSER_VERSION = 1;

function correo_backup_cfg(): array
{
    $config = cfg('correo_backup');
    if (!is_array($config)) $config = cfg('correo');
    if (!is_array($config) || empty($config['host']) || empty($config['usuario']) || empty($config['password'])) {
        throw new CorreoError("Configura 'correo_backup' en config/config.php con los datos del buzón IMAP.");
    }
    $security = strtolower((string) ($config['seguridad'] ?? ''));
    $config['puerto'] = (int) ($config['puerto'] ?? 993);
    $config['carpeta'] = (string) ($config['carpeta'] ?? 'INBOX');
    $config['seguridad'] = $security ?: (!empty($config['ssl']) ? 'ssl' : 'tls');
    if (!in_array($config['seguridad'], ['ssl','tls','starttls','none','notls'], true)) {
        throw new CorreoError("Seguridad IMAP no válida: usa 'ssl', 'tls' o 'none'.");
    }
    $config['dias'] = max(1, min(90, (int) ($config['dias'] ?? 14)));
    $config['max'] = max(1, min(1000, (int) ($config['max'] ?? 300)));
    return $config;
}

function correo_fingerprint(array $headers, $overview, int $uid, array $config): string
{
    $messageId = trim((string) ($headers['message-id'] ?? $overview->message_id ?? ''));
    $date = trim((string) ($headers['date'] ?? $overview->date ?? ''));
    $subject = trim((string) ($headers['subject'] ?? $overview->subject ?? ''));
    $identity = $messageId !== ''
        ? strtolower($messageId) . '|' . $date . '|' . $subject
        : strtolower((string) $config['host']) . '|' . $config['carpeta'] . '|' . $uid;
    return sha1($identity);
}

function correo_importar(string $origen = 'web', ?int $cadaMinutos = null): ?array
{
    if (!function_exists('imap_open')) throw new CorreoError('PHP no tiene activa la extensión IMAP. Actívala en la configuración de PHP del servidor.');
    @set_time_limit(300);
    $pdo = db();
    asegurar_tablas_equipos($pdo);
    if (!(int) $pdo->query("SELECT GET_LOCK('correo_backup_import', 0)")->fetchColumn()) {
        throw new CorreoError('Ya hay una lectura del buzón en curso. Espera unos segundos.');
    }

    $logId = 0;
    $imap = null;
    try {
        if ($cadaMinutos !== null) {
            $last = $pdo->query('SELECT MAX(inicio) FROM correo_sync_log')->fetchColumn();
            if ($last && strtotime((string) $last) > time() - $cadaMinutos * 60) return null;
        }
        $pdo->prepare('INSERT INTO correo_sync_log (inicio,origen) VALUES (NOW(),?)')->execute([$origen]);
        $logId = (int) $pdo->lastInsertId();

        $config = correo_backup_cfg();
        $security = $config['seguridad'] === 'ssl' ? '/ssl' : (in_array($config['seguridad'], ['tls','starttls'], true) ? '/tls' : '/notls');
        $mailbox = sprintf('{%s:%d/imap%s}%s', $config['host'], $config['puerto'], $security, $config['carpeta']);
        $imap = @imap_open($mailbox, (string) $config['usuario'], (string) $config['password'], OP_READONLY, 1);
        if (!$imap) throw new CorreoError('No se pudo conectar con el buzón IMAP: ' . (imap_last_error() ?: 'comprueba el servidor y el certificado.'));

        $since = date('d-M-Y', strtotime('-' . $config['dias'] . ' days'));
        $messages = imap_search($imap, 'SINCE "' . $since . '"') ?: [];
        sort($messages, SORT_NUMERIC);
        $new = $ignored = $failed = 0;
        $lastError = null;
        foreach (array_chunk($messages, 200) as $batch) {
            $overviews = imap_fetch_overview($imap, implode(',', $batch), 0) ?: [];
            foreach ($overviews as $overview) {
                $subject = trim(mail_header_text((string) ($overview->subject ?? '')));
                if (!preg_match('/Acronis True Image|Iperius Backup|VMware|vSphere|ESXi/i', $subject)) continue;
                $msgNo = (int) ($overview->msgno ?? 0);
                if ($msgNo < 1) continue;
                $uid = (int) imap_uid($imap, $msgNo);
                $legacyUid = (string) $uid;
                $raw = (string) imap_fetchheader($imap, $msgNo) . (string) imap_body($imap, $msgNo, FT_PEEK);
                $message = mail_parse_message($raw);
                $fingerprint = correo_fingerprint($message['headers'], $overview, $uid, $config);
                // Recognize UID keys written by the previous importer before switching to stable fingerprints.
                $exists = $pdo->prepare('SELECT 1 FROM correo_copias WHERE message_key IN (?,?) LIMIT 1');
                $exists->execute([$fingerprint, $legacyUid]);
                if ($exists->fetchColumn()) continue;
                $ignoredBefore = $pdo->prepare('SELECT parser_version FROM correo_ignorados WHERE message_key=?');
                $ignoredBefore->execute([$fingerprint]);
                $ignoredVersion = $ignoredBefore->fetchColumn();
                if ($ignoredVersion !== false && (int)$ignoredVersion >= MAIL_PARSER_VERSION) continue;
                if ($new >= $config['max']) break 2;

                try {
                    $parsed = mail_backup_result($subject, $message['text'], $message['html'], $message['headers']['date'] ?? ($overview->date ?? null));
                    if ($parsed === null) {
                        $date = mail_date_or_fallback($message['headers']['date'] ?? ($overview->date ?? null));
                        $pdo->prepare('INSERT INTO correo_ignorados (message_key,fecha,asunto,parser_version) VALUES (?,?,?,?)
                            ON DUPLICATE KEY UPDATE fecha=VALUES(fecha),asunto=VALUES(asunto),parser_version=VALUES(parser_version)')
                            ->execute([$fingerprint,$date,mb_substr($subject,0,500),MAIL_PARSER_VERSION]);
                        $ignored++;
                        continue;
                    }
                    $pdo->beginTransaction();
                    $pdo->prepare('INSERT INTO equipos (nombre,software,origen,sugerido)
                        VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE origen=VALUES(origen), sugerido=COALESCE(VALUES(sugerido),sugerido)')
                        ->execute([$parsed['nombre'],$parsed['software'],$parsed['origen'],$parsed['sugerido']]);
                    $st = $pdo->prepare('SELECT id,cliente_id FROM equipos WHERE software=? AND nombre=?');
                    $st->execute([$parsed['software'],$parsed['nombre']]);
                    $equipment = $st->fetch();
                    $insert = $pdo->prepare('INSERT IGNORE INTO correo_copias
                        (message_key,equipo_id,fecha,estado,desc_error,codigo,software,destino,tamano,duracion)
                        VALUES (?,?,?,?,?,?,?,?,?,?)');
                    $insert->execute([$fingerprint,$equipment['id'],$parsed['fecha'],$parsed['estado'],$parsed['desc_error'],$parsed['codigo'],
                        $parsed['software'],$parsed['destino'],$parsed['tamano'],$parsed['duracion']]);
                    if ($insert->rowCount()) {
                        $pdo->prepare('DELETE FROM correo_ignorados WHERE message_key=?')->execute([$fingerprint]);
                        correo_importar_equipo((int)$equipment['id'],$equipment['cliente_id'] !== null ? (int)$equipment['cliente_id'] : null,$pdo);
                        $new++;
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $failed++;
                    $lastError = $e->getMessage();
                    error_log('[copias] error leyendo correo "' . $subject . '": ' . $e->getMessage());
                }
            }
        }

        $summary = $failed ? "$failed correos con error: " . mb_substr((string)$lastError, 0, 350) : null;
        $pdo->prepare('UPDATE correo_sync_log SET fin=NOW(),ok=?,nuevos=?,ignorados=?,mensaje=? WHERE id=?')
            ->execute([$failed ? 0 : 1,$new,$ignored,$summary,$logId]);
        return ['leidas'=>$new,'ignoradas'=>$ignored,'omitidas'=>$ignored,'errores'=>$failed,'ultimo_error'=>$lastError];
    } catch (Throwable $e) {
        if ($logId) $pdo->prepare('UPDATE correo_sync_log SET fin=NOW(),ok=0,mensaje=? WHERE id=?')->execute([mb_substr($e->getMessage(),0,500),$logId]);
        throw $e instanceof CorreoError ? $e : new CorreoError('Error al importar correos: ' . (cfg('debug') ? $e->getMessage() : 'consulta el registro del servidor.'), 0, $e);
    } finally {
        if ($imap) @imap_close($imap);
        $pdo->query("SELECT RELEASE_LOCK('correo_backup_import')");
    }
}

function correo_backup_auto(): void
{
    $config = cfg('correo_backup') ?? cfg('correo');
    if (!is_array($config) || empty($config['host']) || !function_exists('imap_open')) return;
    $minutes = max(0, (int) ($config['minutos'] ?? 5));
    if ($minutes === 0) return;
    try { correo_importar('auto', $minutes); }
    catch (Throwable $e) { error_log('[copias] importación automática de correo: ' . $e->getMessage()); }
}
