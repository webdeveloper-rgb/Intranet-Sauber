<?php
declare(strict_types=1);

/**
 * Lee el buzón IMAP del servidor (solo lectura, LEÍDOS O NO) y devuelve los correos de copias que el panel
 * todavía no conoce. n8n los recoge, los pasa por la IA y los envía a ingest.php.
 *
 * config.php → 'correo_backup' => ['host','puerto','usuario','password','carpeta','ssl',
 *     'asunto' => '/regex/i'   // opcional: qué asuntos se consideran de copias
 * ]
 */
require_once __DIR__ . '/MailParser.php';

final class BuzonError extends RuntimeException
{
}

function buzon_cfg(): array
{
    $c = cfg('correo_backup');
    if (!is_array($c) || empty($c['host']) || empty($c['usuario']) || empty($c['password']))
        throw new BuzonError("Falta la sección 'correo_backup' en config/config.php (host, usuario, password).");
    $sec = strtolower((string) ($c['seguridad'] ?? ''));
    $sec = $sec ?: (!empty($c['ssl']) ? 'ssl' : 'tls');
    $flag = $sec === 'ssl' ? '/ssl' : (in_array($sec, ['tls', 'starttls'], true) ? '/tls' : '/notls');
    $re = (string) ($c['asunto'] ?? '/Acronis|Iperius|VMware|vSphere|ESXi|Micro ?Focus|copia de seguridad|backup/i');
    if (@preg_match($re, '') === false)
        throw new BuzonError("La expresión 'asunto' de correo_backup no es válida.");
    return [
        'mailbox' => sprintf('{%s:%d/imap%s}%s', $c['host'], (int) ($c['puerto'] ?? 993), $flag, (string) ($c['carpeta'] ?? 'INBOX')),
        'usuario' => (string) $c['usuario'],
        'password' => (string) $c['password'],
        'asunto' => $re,
    ];
}

/** Clave con la que ingest.php identifica un correo (igual que ingesta_normalizar). */
function buzon_clave(string $messageId): string
{
    return sha1('n8n|' . strtolower($messageId));
}

function buzon_leer(PDO $pdo, int $dias, int $max, int $segundos = 40): array
{
    if (!function_exists('imap_open'))
        throw new BuzonError('PHP no tiene activa la extensión IMAP (Plesk → Ajustes de PHP).');
    $c = buzon_cfg();
    $imap = @imap_open($c['mailbox'], $c['usuario'], $c['password'], OP_READONLY, 1);
    if (!$imap)
        throw new BuzonError('No se pudo conectar con el buzón: ' . (imap_last_error() ?: 'comprueba servidor, puerto y certificado.'));
    try {
        $ids = imap_search($imap, 'SINCE "' . date('d-M-Y', strtotime("-$dias days")) . '"') ?: [];
        rsort($ids, SORT_NUMERIC); // lo más reciente primero
        $deadline = time() + $segundos;
        $enCopias = $pdo->prepare('SELECT 1 FROM correo_copias WHERE message_key=? LIMIT 1');
        $enIgnorados = $pdo->prepare('SELECT 1 FROM correo_ignorados WHERE message_key=? LIMIT 1');

        $out = [];
        $revisados = $coinciden = $conocidos = 0;
        $parcial = false;
        foreach (array_chunk($ids, 100) as $lote) {
            $ovs = imap_fetch_overview($imap, implode(',', $lote), 0) ?: [];
            usort($ovs, static fn($a, $b) => ((int) ($b->msgno ?? 0)) <=> ((int) ($a->msgno ?? 0)));
            foreach ($ovs as $ov) {
                if (time() > $deadline || count($out) >= $max) { $parcial = true; break 2; }
                $revisados++;
                $asunto = trim((string) preg_replace('/\s+/u', ' ', mail_header_text((string) ($ov->subject ?? ''))));
                if (!preg_match($c['asunto'], $asunto)) continue;
                $coinciden++;
                $msgNo = (int) ($ov->msgno ?? 0);
                if ($msgNo < 1) continue;

                $rawHeader = (string) imap_fetchheader($imap, $msgNo);
                $cab = mail_parse_headers($rawHeader);
                $ts = strtotime((string) ($cab['date'] ?? $ov->date ?? ''));
                $iso = date('c', $ts !== false ? $ts : time());
                $mid = trim((string) ($cab['message-id'] ?? $ov->message_id ?? ''));
                if ($mid === '') $mid = 'sin-id|' . $asunto . '|' . $iso;

                $k = buzon_clave($mid);
                $enCopias->execute([$k]);
                if ($enCopias->fetchColumn()) { $conocidos++; continue; }
                $enIgnorados->execute([$k]);
                if ($enIgnorados->fetchColumn()) { $conocidos++; continue; }

                $msg = mail_parse_message($rawHeader . (string) imap_body($imap, $msgNo, FT_PEEK));
                $texto = $msg['text'] !== '' ? $msg['text'] : mail_html_text($msg['html']);
                $texto = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto));
                $out[] = [
                    'message_id' => $mid,
                    'asunto' => $asunto,
                    'de' => mb_substr(mail_header_text((string) ($cab['from'] ?? '')), 0, 200),
                    'fecha' => $iso,
                    'texto' => mb_substr($texto, 0, 6000),
                ];
            }
        }
        return ['correos' => $out, 'buzon' => count($ids), 'revisados' => $revisados, 'coinciden' => $coinciden,
            'conocidos' => $conocidos, 'hay_mas' => $parcial];
    } finally {
        @imap_close($imap);
    }
}
