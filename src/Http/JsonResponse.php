<?php
declare(strict_types=1);


/**
 * Emite respuestas JSON con codigo HTTP y cabeceras consistentes.
 */
final class JsonResponse
{
    public static function send($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
