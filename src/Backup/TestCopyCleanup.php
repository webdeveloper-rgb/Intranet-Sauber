<?php
declare(strict_types=1);

require_once __DIR__ . '/IngestionService.php';

/** Solo evidencia propia del aviso; nunca el nombre del equipo ni su origen actual. */
function copias_prueba_plan(array $history, array $staging): array
{
    $keys = [];
    foreach (array_merge($history, $staging) as $row) {
        if (ingesta_es_prueba([
            'desc_error' => $row['desc_error'] ?? '',
            'clasificacion_evidencia' => $row['clasificacion_evidencia'] ?? '',
            'resultado_original' => $row['resultado_original'] ?? '',
        ])) {
            $key = $row['huella'] ?? $row['message_key'] ?? null;
            if (is_string($key) && $key !== '') {
                $keys[$key] = true;
            }
        }
    }
    $select = static function (array $rows) use ($keys): array {
        return array_values(array_filter($rows, static function (array $row) use ($keys): bool {
            $key = $row['huella'] ?? $row['message_key'] ?? null;
            return ($key !== null && isset($keys[$key])) || ingesta_es_prueba([
                'desc_error' => $row['desc_error'] ?? '',
                'clasificacion_evidencia' => $row['clasificacion_evidencia'] ?? '',
                'resultado_original' => $row['resultado_original'] ?? '',
            ]);
        }));
    };
    return ['copias' => $select($history), 'correo_copias' => $select($staging)];
}
