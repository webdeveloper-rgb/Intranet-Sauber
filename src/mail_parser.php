<?php
declare(strict_types=1);

/** Decode RFC 2047 subject text without changing ordinary UTF-8 subjects. */
function mail_header_text(string $value): string
{
    if (strpos($value, '=?') === false) return mb_scrub($value);
    $decoded = function_exists('iconv_mime_decode')
        ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')
        : false;
    return mb_scrub($decoded !== false ? $decoded : $value);
}

function mail_decode_body(string $body, string $transfer, string $charset): string
{
    $transfer = strtolower(trim($transfer));
    if ($transfer === 'base64') {
        $decoded = base64_decode($body, true);
        $body = $decoded === false ? '' : $decoded;
    } elseif ($transfer === 'quoted-printable') {
        $body = quoted_printable_decode($body);
    }
    $charset = trim($charset) ?: 'UTF-8';
    if (!in_array(strtolower($charset), ['utf-8', 'us-ascii'], true)) {
        try { $body = mb_convert_encoding($body, 'UTF-8', $charset); }
        catch (Throwable $e) { /* Retain the decoded bytes if the charset is unknown. */ }
    }
    return mb_scrub($body);
}

function mail_parse_headers(string $raw): array
{
    $raw = (string) preg_replace("/\r?\n[ \t]+/", ' ', $raw);
    $headers = [];
    foreach (preg_split("/\r?\n/", $raw) as $line) {
        if (strpos($line, ':') === false) continue;
        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        if (!array_key_exists($name, $headers)) $headers[$name] = trim($value);
    }
    return $headers;
}

function mail_parse_mime_part(array $headers, string $body, array &$result): void
{
    $contentType = $headers['content-type'] ?? 'text/plain';
    $type = strtolower(trim(explode(';', $contentType)[0]));
    if (str_starts_with($type, 'multipart/')) {
        if (!preg_match('/boundary="?([^";]+)"?/i', $contentType, $m)) return;
        $parts = explode('--' . $m[1], $body);
        array_shift($parts);
        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");
            if (str_starts_with($part, '--')) break;
            $split = preg_split("/\r?\n\r?\n/", $part, 2);
            mail_parse_mime_part(mail_parse_headers($split[0] ?? ''), $split[1] ?? '', $result);
        }
        return;
    }
    if (!in_array($type, ['text/plain', 'text/html'], true)
        || stripos($headers['content-disposition'] ?? '', 'attachment') !== false) return;

    preg_match('/charset="?([^";\s]+)"?/i', $contentType, $charset);
    $content = mail_decode_body($body, $headers['content-transfer-encoding'] ?? '', $charset[1] ?? 'UTF-8');
    $key = $type === 'text/html' ? 'html' : 'text';
    if ($result[$key] === '') $result[$key] = $content;
}

/** Parse RFC 5322 MIME input into headers, plain text and HTML body. */
function mail_parse_message(string $raw): array
{
    $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
    $headers = mail_parse_headers($parts[0] ?? '');
    $result = ['headers' => $headers, 'text' => '', 'html' => ''];
    mail_parse_mime_part($headers, $parts[1] ?? '', $result);
    return $result;
}

function mail_html_text(string $html): string
{
    $html = (string) preg_replace('~(?is)<(script|style|head).*?</\\1>~', '', $html);
    $html = (string) preg_replace('~(?i)<br\s*/?>|</p>|</div>|</tr>|</li>~', "\n", $html);
    $html = (string) preg_replace('~(?i)</t[dh]>~', "\t", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);
    return trim((string) preg_replace('/[ \t]+/', ' ', $text));
}

function mail_date_or_fallback(?string $date): string
{
    if ($date !== null && trim($date) !== '') {
        $timestamp = strtotime($date);
        if ($timestamp !== false) return date('Y-m-d H:i:s', $timestamp);
    }
    return date('Y-m-d H:i:s');
}

function mail_backup_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

function mail_backup_result(string $subject, string $text, string $html, ?string $headerDate = null): ?array
{
    $subject = trim((string) preg_replace('/\s+/u', ' ', mail_header_text($subject)));
    $date = mail_date_or_fallback($headerDate);

    if (preg_match('/Notificaci[oó]n de Acronis True Image de\s+(.+)$/iu', $subject, $match)) {
        $origin = trim($match[1]);
        $bodyText = $text !== '' ? $text : mail_html_text($html);
        $bodyText = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $bodyText);
        $haystack = $subject . "\n" . $bodyText;
        $hasError = preg_match('/\berror\b|\bfall(?:o|os|a|as|ido|ida)\b|\badvertencia\b/iu', $subject)
            || preg_match('/se ha realizado con errores|se ha efectuado con errores|Error\s+0x[0-9a-f]+/iu', $bodyText);
        $hasSuccess = preg_match('/completad[oa] (?:satisfactoriamente|correctamente)/iu', $subject)
            || preg_match('/se ha efectuado correctamente|se ha completado correctamente/iu', $bodyText);
        if (!$hasError && !$hasSuccess) return null;
        $status = $hasError ? 'ERROR' : 'OK';

        $times = [];
        if (preg_match_all('/(20\d{2}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $bodyText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) $times[] = $m[1] . ' ' . $m[2];
        }
        $duration = null;
        if ($times) {
            $date = mail_date_or_fallback($times[0]);
            $start = strtotime($times[0]);
            $finish = strtotime($times[count($times) - 1]);
            if ($start !== false && $finish !== false) $duration = mail_backup_duration($finish - $start);
        }

        $name = $origin;
        $suggested = null;
        if (preg_match('/^(.*?)[_\s-]*((?:DESKTOP|LAPTOP|PC|WIN)-[A-Z0-9]+)$/iu', $origin, $device)) {
            $prefix = trim(str_replace('_', ' ', $device[1]));
            $suggested = $prefix !== '' ? $prefix : null;
        }
        $code = $error = $destination = null;
        if ($status === 'ERROR') {
            if (preg_match('/E[0-9A-F]{8}:\s*Error\s+(0x[0-9a-f]+):\s*([^\r\n]+)/mi', $bodyText, $e)) {
                $code = $e[1];
                $error = trim($e[2]);
            } elseif (preg_match('/Error\s+(0x[0-9a-f]+):\s*([^\r\n]+)/iu', $bodyText, $e)) {
                $code = $e[1];
                $error = trim($e[2]);
            }
            if (preg_match_all('/^\|\s*error 0x[0-9a-f]+:\s*([^\r\n]+)/mi', $bodyText, $causes)) {
                $causes = array_slice(array_values(array_unique(array_map('trim', $causes[1]))), 0, 3);
                $error = trim(($error ? $error . ' Causa: ' : '') . implode(' → ', $causes));
            }
            if (!$error) $error = trim($subject);
            if (preg_match('/Al archivo:\s*"([^"]+)"/iu', $bodyText, $target)) $destination = trim($target[1]);
        }

        return ['software' => 'Acronis', 'origen' => $origin, 'nombre' => $name, 'sugerido' => $suggested,
            'estado' => $status, 'fecha' => $date, 'desc_error' => $error, 'codigo' => $code,
            'destino' => $destination, 'tamano' => null, 'duracion' => $duration];
    }

    if (preg_match('/^(Copia de seguridad .+?) - (.+) - Iperius Backup$/iu', $subject, $match)) {
        $summary = $match[1];
        $statusError = preg_match('/con errores|error|fall/i', $summary);
        $statusSuccess = preg_match('/completada correctamente|realizada correctamente|finalizada correctamente|completada satisfactoriamente/i', $summary);
        if (!$statusError && !$statusSuccess) return null;
        $job = trim($match[2]);
        $bodyText = mail_html_text($html !== '' ? $html : $text);

        if (preg_match('/Inicio de copia de seguridad:\s*(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2}):(\d{2})/iu', $bodyText, $started)) {
            $date = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $started[3], $started[2], $started[1], $started[4], $started[5], $started[6]);
        }
        $duration = null;
        if (preg_match('/\((\d+) Horas?,\s*(\d+) Minutos?,\s*(\d+) Segundos?\)/iu', $bodyText, $elapsed)) {
            $duration = sprintf('%02d:%02d:%02d', $elapsed[1], $elapsed[2], $elapsed[3]);
        }
        $size = preg_match('/Tama[ñn]o de los datos copiados:\s*([^\t\r\n]+)/iu', $bodyText, $s) ? trim($s[1]) : null;
        $error = $code = null;
        if ($statusError) {
            $errors = [];
            if (preg_match_all('/^Error:\s*(.+)$/mi', $bodyText, $matches)) {
                foreach ($matches[1] as $line) {
                    $errors[] = trim((string) preg_replace('/\s*\(\d{1,2}\/\d{1,2}\/\d{4}[^)]*\)\s*$/u', '', $line));
                }
            }
            if ($errors && preg_match('/error\s+(-?\d+)/iu', $errors[0], $m)) $code = $m[1];
            $error = $errors ? implode(' · ', array_slice($errors, 0, 4)) : $summary;
        }
        $destination = preg_match('/Cargar archivo zip:\s*(.+?)\s*\(\d{1,2}\/\d{1,2}\/\d{4}/iu', $bodyText, $target) ? trim($target[1]) : null;
        return ['software' => 'Iperius', 'origen' => $job, 'nombre' => $job, 'sugerido' => null,
            'estado' => $statusError ? 'ERROR' : 'OK', 'fecha' => $date, 'desc_error' => $error, 'codigo' => $code,
            'destino' => $destination, 'tamano' => $size, 'duracion' => $duration];
    }

    if (preg_match('/VMware|vSphere|ESXi/i', $subject . "\n" . $text)) {
        $haystack = $subject . "\n" . $text;
        $error = preg_match('/fail|error|fallid/i', $haystack);
        $success = preg_match('/success|correct|complete|éxito|exito|satisfact/i', $haystack);
        if (!$error && !$success) return null;
        $name = '';
        if (preg_match('/(?:virtual machine|VM|equipo|host)\s*[:=]\s*[\'"]?([^\'"\r\n,;]+)/iu', $subject, $m)) $name = trim($m[1]);
        if ($name === '') {
            $name = preg_replace('/\b(?:VMware|vSphere|ESXi|backup|copia de seguridad|job|trabajo|report|informe|completed|completado|successfully|correctamente|failed|failure|error|fallido|fallida)\b/iu', ' ', $subject);
            $name = trim((string) preg_replace('/[\s:|–—-]+/u', ' ', $name));
        }
        if ($name === '') return null;
        $errorText = null;
        if ($error && preg_match('/[^\r\n]*(?:Error|failed|fall[oó])[^\r\n]*/iu', mail_html_text($html) . "\n" . $text, $m)) $errorText = trim($m[0]);
        return ['software' => 'VMware', 'origen' => $subject, 'nombre' => mb_substr($name, 0, 200), 'sugerido' => null,
            'estado' => $error ? 'ERROR' : 'OK', 'fecha' => $date, 'desc_error' => $errorText,
            'codigo' => null, 'destino' => null, 'tamano' => null, 'duracion' => null];
    }

    return null;
}
