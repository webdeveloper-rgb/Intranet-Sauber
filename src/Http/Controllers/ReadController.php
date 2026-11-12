<?php
declare(strict_types=1);


/**
 * Acciones de lectura del panel: sesion, novedades, copias recientes, equipos, catalogo y tokens.
 */
final class ReadController
{
    public static function handle(string $action, array $user): bool
    {
        if ($action === 'sesion') {
            out([
                'usuario' => $user['usuario'],
                'nombre' => $user['nombre'] ?: $user['usuario'],
                'rol' => $user['rol'],
                'csrf' => csrf_token(),
            ]);
        }

        if ($action === 'novedades') {
            $r = db()->query('SELECT COALESCE(MAX(id), 0) AS m FROM copias')->fetch();
            $staging = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM correo_copias')->fetchColumn();
            $ingesta = (int) db()->query("SELECT COALESCE(MAX(id), 0) FROM correo_sync_log WHERE origen='n8n'")->fetchColumn();
            if (empty($_SESSION['mig_tipos']) || empty($_SESSION['mig_dias_copia'])) {
                asegurar_tablas_equipos(db());
                $_SESSION['mig_tipos'] = 1;
                $_SESSION['mig_dias_copia'] = 1;
            }
            $sinCopia = copias_sin_recibir_cache(db());
            $ausentes = array_map(
                static fn($c) => [$c['id'], $c['cliente'], $c['equipo'], $c['fecha_esperada'], $c['fecha'], $c['ultima_copia']],
                $sinCopia
            );
            out([
                'm' => (int) $r['m'],
                'staging' => $staging,
                'ingesta' => $ingesta,
                'ausentes' => hash('sha256', json_encode($ausentes, JSON_UNESCAPED_UNICODE)),
            ]);
        }

        if ($action === 'list') {
            if (empty($_SESSION['mig_tipos']) || empty($_SESSION['mig_dias_copia'])) {
                asegurar_tablas_equipos(db());
                $_SESSION['mig_tipos'] = 1;
                $_SESSION['mig_dias_copia'] = 1;
            }
            $rows = db()->query(SQL_COPIA . " WHERE fecha >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                ORDER BY cursor_fecha DESC, id DESC LIMIT 3001")->fetchAll();
            if (count($rows) > 3000) {
                array_pop($rows);
            }
            $rows = array_map('copia_fila', $rows);
            $rows = array_merge(copias_sin_recibir_cache(db()), $rows);
            $notas = copia_notas_de(array_column(
                array_filter($rows, static fn($row) => ($row['codigo'] ?? null) !== 'SIN_COPIA'),
                'id'
            ));
            foreach ($rows as &$r) {
                $r['notas'] = $notas[$r['id']] ?? [];
            }
            unset($r);

            $body = json_encode([
                'copias' => $rows,
                'usuario' => $user['usuario'],
                'hay_mas_historial' => false,
            ], JSON_UNESCAPED_UNICODE);
            http_response_code(200);
            header('Content-Length: ' . strlen($body));
            header('Connection: close');
            ignore_user_abort(true);
            echo $body;
            session_write_close();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                flush();
            }
            exit;
        }

        if ($action === 'usuarios') {
            fail('La gestión de accesos se realiza en la API de usuarios autorizados.', 403);
        }

        if ($action === 'tokens') {
            exigir_admin($user);
            tokens_asegurar_tabla(db());
            $rows = db()->query("SELECT id, nombre, prefijo, creado_por, activo,
                    DATE_FORMAT(creado,'%Y-%m-%d %H:%i') AS creado,
                    DATE_FORMAT(ultimo_uso,'%Y-%m-%d %H:%i') AS ultimo_uso, ultima_ip,
                    DATE_FORMAT(caduca,'%Y-%m-%d') AS caduca
                    FROM api_tokens ORDER BY id DESC")->fetchAll();
            foreach ($rows as &$r) {
                $r['activo'] = (int) $r['activo'];
            }
            unset($r);
            out(['tokens' => $rows]);
        }

        if ($action === 'catalogo') {
            out(api_sync_catalogo());
        }

        if ($action === 'equipos') {
            $listado = equipos_listado();
            $_SESSION['mig_tipos'] = 1;
            $_SESSION['mig_dias_copia'] = 1;
            out($listado);
        }

        return false;
    }
}
