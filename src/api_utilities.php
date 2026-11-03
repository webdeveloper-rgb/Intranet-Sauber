<?php
declare(strict_types=1);
// Sincronización de clientes y máquinas desde la API (DSPYME / Euromanager sobre NocoDB).
// Las credenciales viven solo en config/config.php y esta conexión se hace siempre desde el servidor.

final class ApiError extends RuntimeException
{
}

function api_sync_cfg(): array
{
    $c = cfg('api_sync');
    if (!is_array($c) || empty($c['base_url']) || empty($c['email']) || empty($c['password'])) {
        throw new ApiError("Falta configurar la sección 'api_sync' en config/config.php (base_url, email y password).");
    }
    return $c + [
        'org' => '1',       // segmento tras /db/data/ en las URLs de la API
        'proyecto' => 'demo',    // nombre del proyecto NocoDB
        'tabla_clientes' => 'Cliente',
        'tabla_maquinas' => 'Maquina',
        'page_size' => 500,       // la API admite hasta 1000 por página
        'timeout' => 30,
    ];
}

/** Petición HTTP. Devuelve [código HTTP, JSON decodificado o null]. */
function api_sync_http(string $method, string $url, array $headers, ?string $body, int $timeout): array
{
    if (!function_exists('curl_init')) {
        throw new ApiError('PHP no tiene la extensión cURL activada (actívala en Plesk → Ajustes de PHP).');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null)
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new ApiError('No se pudo conectar con la API: ' . $err);
    }
    $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = json_decode((string) $res, true);
    return [$st, is_array($j) ? $j : null];
}

function api_sync_token(array $c): string
{
    [$st, $j] = api_sync_http(
        'POST',
        rtrim($c['base_url'], '/') . '/auth/user/signin',
        ['Content-Type: application/json', 'Accept: application/json'],
        json_encode(['email' => $c['email'], 'password' => $c['password']]),
        (int) $c['timeout']
    );
    if (in_array($st, [400, 401, 403], true)) {
        throw new ApiError('La API rechazó el usuario o la contraseña (HTTP ' . $st . ').');
    }
    if ($st !== 200 || empty($j['token']) || !is_string($j['token'])) {
        throw new ApiError('Respuesta inesperada al iniciar sesión en la API (HTTP ' . $st . ').');
    }
    return $j['token'];
}

/** Descarga todas las filas de una tabla, página a página. */
function api_sync_todo(array $c, string $token, string $tabla): array
{
    $base = rtrim($c['base_url'], '/') . '/db/data/' . rawurlencode((string) $c['org']) . '/'
        . rawurlencode((string) $c['proyecto']) . '/' . rawurlencode($tabla);
    $lim = max(1, min(1000, (int) $c['page_size']));
    $filas = [];
    $offset = 0;
    for ($i = 0; $i < 2000; $i++) { // tope de seguridad
        [$st, $j] = api_sync_http(
            'GET',
            $base . '?limit=' . $lim . '&offset=' . $offset,
            ['xc-auth: ' . $token, 'Accept: application/json'],
            null,
            (int) $c['timeout']
        );
        if ($st === 401)
            throw new ApiError('La API rechazó el token (HTTP 401) al leer ' . $tabla . '.');
        if ($st === 404)
            throw new ApiError("No existe la tabla '$tabla' en el proyecto '{$c['proyecto']}' (HTTP 404). Revisa 'proyecto' y 'org' en config.php.");
        if ($st !== 200 || !isset($j['list']) || !is_array($j['list'])) {
            throw new ApiError('Error leyendo la tabla ' . $tabla . ' (HTTP ' . $st . ').');
        }
        foreach ($j['list'] as $r) {
            if (is_array($r))
                $filas[] = $r;
        }
        $n = count($j['list']);
        $ultima = $j['pageInfo']['isLastPage'] ?? ($n < $lim);
        if ($ultima || $n === 0)
            break;
        $offset += $n;
    }
    return $filas;
}

/** Lee un campo sin importar mayúsculas/minúsculas; devuelve texto recortado o null. */
function api_sync_campo(array $r, string $k, int $max = 200): ?string
{
    $v = $r[$k] ?? null;
    if ($v === null) {
        foreach ($r as $kk => $vv) {
            if (strcasecmp((string) $kk, $k) === 0) {
                $v = $vv;
                break;
            }
        }
    }
    if ($v === null || is_array($v) || is_object($v))
        return null;
    $v = trim((string) $v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

function asegurar_tablas_sync(PDO $pdo): void
{
    static $hecho = false;
    if ($hecho)
        return;
    $sql = (string) file_get_contents(__DIR__ . '/../database/sync.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $s) {
        $pdo->exec($s);
    }
    $hecho = true;
}

/**
 * Sincroniza clientes y máquinas. Devuelve un resumen o lanza ApiError.
 * $origen: 'cli' (tarea programada), 'web' (botón) o 'auto' (automática al usar el panel).
 * Con $cadaMinutos no sincroniza si ya hubo un intento hace menos de ese tiempo y devuelve null.
 */
function api_sync(string $origen, ?int $cadaMinutos = null): ?array
{
    @set_time_limit(300);
    $pdo = db();
    asegurar_tablas_sync($pdo);

    if (!(int) $pdo->query("SELECT GET_LOCK('api_sync', 0)")->fetchColumn()) {
        throw new ApiError('Ya hay una sincronización en curso. Espera unos segundos.');
    }
    $logId = 0;
    try {
        if ($cadaMinutos !== null) { // se comprueba ya con el candado puesto, para no repetir
            $ult = $pdo->query('SELECT MAX(inicio) FROM sync_log')->fetchColumn();
            if ($ult && strtotime((string) $ult) > time() - $cadaMinutos * 60)
                return null;
        }
        $pdo->prepare('INSERT INTO sync_log (inicio, origen) VALUES (NOW(), ?)')->execute([$origen]);
        $logId = (int) $pdo->lastInsertId();

        $c = api_sync_cfg();
        $token = api_sync_token($c);
        $clientes = api_sync_todo($c, $token, (string) $c['tabla_clientes']);
        $maquinas = api_sync_todo($c, $token, (string) $c['tabla_maquinas']);

        $ahora = date('Y-m-d H:i:s');
        $json = fn(array $r) => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $saltados = 0;

        $pdo->beginTransaction();

        $st = $pdo->prepare(
            'INSERT INTO clientes (clave, codigo, nombre, cif, email, telefono, localidad, provincia, datos, activo, visto)
             VALUES (?,?,?,?,?,?,?,?,?,1,?)
             ON DUPLICATE KEY UPDATE codigo=VALUES(codigo), nombre=VALUES(nombre), cif=VALUES(cif), email=VALUES(email),
                telefono=VALUES(telefono), localidad=VALUES(localidad), provincia=VALUES(provincia), datos=VALUES(datos),
                activo=1, visto=VALUES(visto)'
        );
        $nCli = 0;
        foreach ($clientes as $r) {
            $clave = api_sync_campo($r, 'Clave', 64);
            if ($clave === null) {
                $saltados++;
                continue;
            }
            $st->execute([
                $clave,
                api_sync_campo($r, 'Codigo', 40),
                api_sync_campo($r, 'Nombre') ?? '',
                api_sync_campo($r, 'Cif', 40),
                api_sync_campo($r, 'Email', 150),
                api_sync_campo($r, 'Tlfno1', 40),
                api_sync_campo($r, 'Localidad', 100),
                api_sync_campo($r, 'Provincia', 100),
                $json($r),
                $ahora,
            ]);
            $nCli++;
        }

        $st = $pdo->prepare(
            'INSERT INTO maquinas (clave, codigo, nombre, cliente_codigo, marca, matricula, datos, activo, visto)
             VALUES (?,?,?,?,?,?,?,1,?)
             ON DUPLICATE KEY UPDATE codigo=VALUES(codigo), nombre=VALUES(nombre), cliente_codigo=VALUES(cliente_codigo),
                marca=VALUES(marca), matricula=VALUES(matricula), datos=VALUES(datos), activo=1, visto=VALUES(visto)'
        );
        $nMaq = 0;
        foreach ($maquinas as $r) {
            $clave = api_sync_campo($r, 'Clave', 64);
            if ($clave === null) {
                $saltados++;
                continue;
            }
            $st->execute([
                $clave,
                api_sync_campo($r, 'Codigo', 40),
                api_sync_campo($r, 'Nombre') ?? '',
                api_sync_campo($r, 'Cliente', 40),
                api_sync_campo($r, 'Marca', 100),
                api_sync_campo($r, 'Matricula', 60),
                $json($r),
                $ahora,
            ]);
            $nMaq++;
        }

        if ($nCli > 0)
            $pdo->prepare('UPDATE clientes SET activo = 0 WHERE visto < ?')->execute([$ahora]);
        if ($nMaq > 0)
            $pdo->prepare('UPDATE maquinas SET activo = 0 WHERE visto < ?')->execute([$ahora]);

        $pdo->commit();

        $msg = $saltados ? "$saltados registros sin 'Clave' omitidos" : null;
        $pdo->prepare('UPDATE sync_log SET fin = NOW(), ok = 1, clientes = ?, maquinas = ?, mensaje = ? WHERE id = ?')
            ->execute([$nCli, $nMaq, $msg, $logId]);

        return [
            'clientes' => $nCli,
            'maquinas' => $nMaq,
            'omitidos' => $saltados,
            'campos' => [
                'clientes' => $clientes ? array_keys($clientes[0]) : [],
                'maquinas' => $maquinas ? array_keys($maquinas[0]) : [],
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        if ($logId) {
            $pdo->prepare('UPDATE sync_log SET fin = NOW(), ok = 0, mensaje = ? WHERE id = ?')
                ->execute([mb_substr($e->getMessage(), 0, 500), $logId]);
        }
        throw $e instanceof ApiError ? $e : new ApiError('Error al sincronizar: ' . (cfg('debug') ? $e->getMessage() : 'consulta el registro del servidor.'), 0, $e);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('api_sync')");
    }
}

function api_sync_auto(): void
{
    $c = cfg('api_sync');
    if (!is_array($c) || empty($c['email']) || empty($c['password']) || empty($c['base_url']))
        return;
    $min = (int) ($c['sync_minutos'] ?? 15);
    if ($min <= 0)
        return;
    try {
        api_sync('auto', $min);
    } catch (Throwable $e) {
        error_log('[copias] sincronización automática: ' . $e->getMessage());
    }
}

function api_sync_catalogo(): array
{
    $pdo = db();
    asegurar_tablas_sync($pdo);
    $cl = $pdo->query('SELECT id, codigo, nombre, cif, email, telefono, localidad, provincia FROM clientes WHERE activo = 1 ORDER BY nombre')->fetchAll();
    $mq = $pdo->query('SELECT id, codigo, nombre, cliente_codigo, marca, matricula FROM maquinas WHERE activo = 1 ORDER BY nombre')->fetchAll();

    $porCliente = [];
    foreach ($mq as $m) {
        $m['id'] = (int) $m['id'];
        $porCliente[(string) $m['cliente_codigo']][] = $m;
    }
    $out = [];
    foreach ($cl as $c) {
        $c['id'] = (int) $c['id'];
        $c['maquinas'] = $porCliente[(string) $c['codigo']] ?? [];
        unset($porCliente[(string) $c['codigo']]);
        $out[] = $c;
    }
    $huerfanas = [];
    foreach ($porCliente as $lista) {
        foreach ($lista as $m)
            $huerfanas[] = $m;
    }
    $ult = $pdo->query("SELECT DATE_FORMAT(inicio,'%Y-%m-%d %H:%i') AS fecha, ok, mensaje, clientes, maquinas, origen
                        FROM sync_log ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    if ($ult) {
        $ult['ok'] = $ult['ok'] === null ? null : (int) $ult['ok'];
    }
    return [
        'clientes' => $out,
        'sin_cliente' => $huerfanas,
        'ultima' => $ult,
        'configurado' => is_array(cfg('api')) && !empty(cfg('api_sync')['email']),
        'auto_minutos' => is_array(cfg('api_sync')) ? (int) (cfg('api')['sync_minutos'] ?? 15) : 0
    ];
}