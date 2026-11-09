<?php
declare(strict_types=1);
// Sincronización de clientes desde la API (DSPYME / Euromanager sobre NocoDB).
// Las credenciales viven solo en config/config.php y la conexión se hace siempre desde el servidor.

final class ApiError extends RuntimeException
{
}

/** Sección de configuración de la API. Acepta 'api_sync' o 'albelink' (nombre usado en el LEEME). */
function api_sync_conf(): ?array
{
    foreach (['api_sync', 'albelink'] as $k) {
        $c = cfg($k);
        if (is_array($c)) {
            return $c;
        }
    }
    return null;
}

function api_sync_configurado(): bool
{
    $c = api_sync_conf();
    return $c !== null && !empty($c['base_url']) && !empty($c['email']) && !empty($c['password']);
}

function api_sync_cfg(): array
{
    $c = api_sync_conf();
    if (!api_sync_configurado()) {
        throw new ApiError("Falta configurar la API en config/config.php (sección 'api_sync' o 'albelink' con base_url, email y password).");
    }
    return $c + [
        'org' => '1',            // segmento tras /db/data/ en las URLs de la API
        'proyecto' => 'demo',    // nombre del proyecto NocoDB
        'tabla_clientes' => 'Cliente',
        'tabla_cliservicios' => 'Cliservicios',
        'tabla_usuarios' => 'Usuario',
        'usuarios_permitidos' => ['Maximiliano', 'Miguel', 'Alberto'],
        'servicio_requerido' => 'VBA3KT7GN7',
        'page_size' => 500,      // la API admite hasta 1000 por página
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
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
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
        rtrim((string) $c['base_url'], '/') . '/auth/user/signin',
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
    $base = rtrim((string) $c['base_url'], '/') . '/db/data/' . rawurlencode((string) $c['org']) . '/'
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
        if ($st === 401) {
            throw new ApiError('La API rechazó el token (HTTP 401) al leer ' . $tabla . '.');
        }
        if ($st === 404) {
            throw new ApiError("No existe la tabla '$tabla' en el proyecto '{$c['proyecto']}' (HTTP 404). Revisa 'proyecto' y 'org' en config.php.");
        }
        // Formato habitual: {"list":[...],"pageInfo":{...}}. Se aceptan también {"data":[...]} y [...]
        $lista = null;
        if (is_array($j)) {
            if (isset($j['list']) && is_array($j['list'])) {
                $lista = $j['list'];
            } elseif (isset($j['data']) && is_array($j['data'])) {
                $lista = $j['data'];
            } elseif (isset($j[0]) && is_array($j[0])) {
                $lista = $j;
            }
        }
        if ($st !== 200 || $lista === null) {
            throw new ApiError('Error leyendo la tabla ' . $tabla . ' (HTTP ' . $st . ').');
        }
        foreach ($lista as $r) {
            if (is_array($r)) {
                $filas[] = $r;
            }
        }
        $n = count($lista);
        $ultima = $j['pageInfo']['isLastPage'] ?? ($n < $lim);
        if ($ultima || $n === 0) {
            break;
        }
        $offset += $n;
    }
    return $filas;
}

/** Authenticate by DNI/email against the bcrypt hash stored in the API Securepassword field. */
function api_autenticar_usuario(string $identificador, string $password): ?array
{
    $c = api_sync_cfg();
    $serviceToken = api_sync_token($c);
    $allowedUsers = api_sync_todo($c, $serviceToken, (string)$c['tabla_usuarios']);
    $normalizeDni = static fn($value) => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim((string)$value), 'UTF-8'));
    $normalize = static fn($value) => mb_strtolower(trim((string)$value), 'UTF-8');
    $esCorreo = strpos($identificador, '@') !== false;
    $identificadorBuscado = $esCorreo ? $normalize($identificador) : $normalizeDni($identificador);
    $permitidos = (array)$c['usuarios_permitidos'];
    $dnisPermitidos = array_map($normalizeDni, (array)($permitidos['dni'] ?? []));
    $nombresConCorreo = array_map($normalize, (array)($permitidos['correo_nombre'] ?? []));

    foreach ($allowedUsers as $row) {
        // En la respuesta real de Usuario, el DNI puede venir en Cif o User.
        $dniApi = api_sync_primero($row, ['DNI', 'NIF', 'Cif', 'Documento', 'NumeroDocumento', 'Identificacion', 'User'], 30);
        $nombre = api_sync_primero($row, ['NombreCompleto', 'Nombre Completo', 'Nombre', 'Nombres', 'DisplayName'], 100) ?? '';
        $primerNombre = $normalize((string)(preg_split('/\\s+/u', trim($nombre))[0] ?? ''));
        $emailApi = api_sync_primero($row, ['Email', 'Correo', 'Mail', 'EMail'], 150);

        if ($esCorreo) {
            $nombreCorreo = $emailApi !== null ? $normalize((string)(strstr($emailApi, '@', true) ?: '')) : '';
            if ($emailApi === null || $normalize($emailApi) !== $identificadorBuscado
                || (!in_array($primerNombre, $nombresConCorreo, true) && !in_array($nombreCorreo, $nombresConCorreo, true))) continue;
        } else {
            if ($dniApi === null || $normalizeDni($dniApi) !== $identificadorBuscado || !in_array($identificadorBuscado, $dnisPermitidos, true)) continue;
        }

        foreach ($row as $field => $value) {
            $field = mb_strtolower((string)$field, 'UTF-8');
            if (in_array($field, ['baja', 'deleted', 'eliminado'], true)) {
                if ($value === true || $value === 1 || (is_string($value) && in_array($normalize($value), ['1', 'true', 'si', 'sí'], true))) return null;
                continue;
            }
            if (!in_array($field, ['activo', 'active', 'enabled', 'habilitado', 'estado'], true)) continue;
            if ($value === false || $value === 0 || (is_string($value) && in_array($normalize($value), ['0', 'false', 'no', 'inactivo', 'desactivado', 'baja'], true))) return null;
        }

        $hash = api_sync_campo($row, 'Securepassword', 255);
        if ($hash === null || !preg_match('/^\\$2[aby]\\$\\d{2}\\$/', $hash)) {
            throw new ApiError('El campo Securepassword no contiene un hash bcrypt compatible.');
        }
        if (!password_verify($password, $hash)) return null;

        $usuarioLocal = $esCorreo ? ($emailApi ?? $identificador) : $dniApi;
        return ['usuario' => $usuarioLocal, 'nombre' => $nombre !== '' ? $nombre : $usuarioLocal];
    }
    return null;
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
    // Campos enlazados (objeto): se intenta usar su código o nombre
    if (is_array($v)) {
        $v = $v['Codigo'] ?? $v['codigo'] ?? $v['Clave'] ?? $v['Id'] ?? null;
    }
    if ($v === null || is_array($v) || is_object($v)) {
        return null;
    }
    $v = trim((string) $v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/** Devuelve el primer campo no vacío de una lista de nombres posibles. */
function api_sync_primero(array $r, array $claves, int $max = 200): ?string
{
    foreach ($claves as $k) {
        $v = api_sync_campo($r, $k, $max);
        if ($v !== null) {
            return $v;
        }
    }
    return null;
}

/** Values nested in linked fields returned by the API. */
function api_sync_valores_relacion($valor): array
{
    if (is_array($valor) || is_object($valor)) {
        $valores = [];
        foreach ((array)$valor as $v) $valores = array_merge($valores, api_sync_valores_relacion($v));
        return $valores;
    }
    if (is_string($valor) || is_int($valor) || is_float($valor)) {
        $texto = trim((string)$valor);
        return $texto === '' ? [] : [$texto];
    }
    return [];
}

function api_sync_valores_campos(array $fila, string $patron): array
{
    $valores = [];
    foreach ($fila as $campo => $valor) {
        if (preg_match($patron, (string)$campo)) {
            $valores = array_merge($valores, api_sync_valores_relacion($valor));
        }
    }
    return $valores;
}

/** Returns client keys with the required service directly listed in Cliservicios. */
function api_sync_clientes_con_servicio(array $clientes, array $cliservicios, string $servicioId): array
{
    $normalizar = static fn($v) => mb_strtoupper(trim((string)$v), 'UTF-8');
    $identidades = [];
    foreach ($clientes as $cliente) {
        $clave = api_sync_primero($cliente, ['Clave', 'Id', 'Codigo'], 64);
        if ($clave === null) continue;
        foreach ([$clave, api_sync_primero($cliente, ['Id'], 64), api_sync_primero($cliente, ['Codigo', 'Cod', 'CodCliente'], 64)] as $id) {
            if ($id !== null) $identidades[$normalizar($id)] = $clave;
        }
    }

    $permitidos = [];
    $tieneRelacionCliente = false;
    $tieneRelacionServicio = false;
    $servicioBuscado = $normalizar($servicioId);
    foreach ($cliservicios as $fila) {
        $idsCliente = api_sync_valores_campos($fila, '/cliente|customer/i');
        $idsServicio = api_sync_valores_campos($fila, '/articulo|servicio|service|item/i');
        if ($idsCliente) $tieneRelacionCliente = true;
        if ($idsServicio) $tieneRelacionServicio = true;
        $coincideServicio = in_array($servicioBuscado, array_map($normalizar, $idsServicio), true);
        if (!$idsCliente || !$coincideServicio) continue;
        foreach ($idsCliente as $id) {
            $id = $normalizar($id);
            if (isset($identidades[$id])) $permitidos[$identidades[$id]] = true;
        }
    }

    if ($cliservicios && (!$tieneRelacionCliente || !$tieneRelacionServicio)) {
        throw new ApiError('No se reconocieron los campos Cliente y Servicio/Articulo en Cliservicios. Campos recibidos: ' . implode(', ', array_keys($cliservicios[0])));
    }
    return $permitidos;
}

function asegurar_tablas_sync(PDO $pdo): void
{
    static $hecho = false;
    if ($hecho) {
        return;
    }
    $sql = (string) file_get_contents(__DIR__ . '/../database/sync.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $s) {
        $pdo->exec($s);
    }
    $cols = $pdo->query("SELECT column_name, character_maximum_length FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='copias'")->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($cols && !array_key_exists('equipo_id', $cols)) {
        $pdo->exec('ALTER TABLE copias ADD COLUMN equipo_id INT UNSIGNED NULL AFTER equipo, ADD KEY idx_copias_equipo_id (equipo_id)');
    }
    if (isset($cols['cliente']) && (int)$cols['cliente'] < 200) $pdo->exec('ALTER TABLE copias MODIFY cliente VARCHAR(200) NOT NULL');
    if (isset($cols['equipo']) && (int)$cols['equipo'] < 200) $pdo->exec('ALTER TABLE copias MODIFY equipo VARCHAR(200) NOT NULL');
    $hecho = true;
}

/**
 * Sincroniza clientes. Devuelve un resumen o lanza ApiError.
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
            if ($ult && strtotime((string) $ult) > time() - $cadaMinutos * 60) {
                return null;
            }
        }
        $pdo->prepare('INSERT INTO sync_log (inicio, origen) VALUES (NOW(), ?)')->execute([$origen]);
        $logId = (int) $pdo->lastInsertId();

        $c = api_sync_cfg();
        $token = api_sync_token($c);
        $clientes = api_sync_todo($c, $token, (string) $c['tabla_clientes']);
        $cliservicios = api_sync_todo($c, $token, (string) $c['tabla_cliservicios']);
        $clientesConServicio = api_sync_clientes_con_servicio($clientes, $cliservicios, (string)$c['servicio_requerido']);

        $ahora = date('Y-m-d H:i:s');
        $json = fn(array $r) => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $saltados = 0;

        if (function_exists('asegurar_tablas_equipos')) asegurar_tablas_equipos($pdo);

        $pdo->beginTransaction();

        $st = $pdo->prepare(
            'INSERT INTO clientes (clave, codigo, nombre, cif, email, telefono, localidad, provincia, datos, activo, visto)
             VALUES (?,?,?,?,?,?,?,?,?,1,?)
             ON DUPLICATE KEY UPDATE codigo=VALUES(codigo), nombre=VALUES(nombre), cif=VALUES(cif), email=VALUES(email),
                telefono=VALUES(telefono), localidad=VALUES(localidad), provincia=VALUES(provincia), datos=VALUES(datos),
                activo=1, visto=VALUES(visto)'
        );
        $pdo->exec('UPDATE clientes SET activo = 0');
        $nCli = 0;
        foreach ($clientes as $r) {
            $codigo = api_sync_primero($r, ['Codigo', 'Cod', 'CodCliente'], 40);
            $clave = api_sync_primero($r, ['Clave', 'Id', 'Codigo'], 64);
            if ($clave === null) {
                $saltados++;
                continue;
            }
            if (!isset($clientesConServicio[$clave])) continue;
            $st->execute([
                $clave,
                $codigo,
                api_sync_primero($r, ['Nombre', 'RazonSocial', 'Razon_Social']) ?? '',
                api_sync_primero($r, ['Cif', 'Nif', 'CifNif'], 40),
                api_sync_primero($r, ['Email', 'EMail', 'Correo'], 150),
                api_sync_primero($r, ['Tlfno1', 'Telefono', 'Tlfno'], 40),
                api_sync_primero($r, ['Localidad', 'Poblacion'], 100),
                api_sync_primero($r, ['Provincia'], 100),
                $json($r),
                $ahora,
            ]);
            $nCli++;
        }

        // El catálogo arranca limpio: equipos pierden la asignación a clientes excluidos,
        // pero el historial de copias/notas se conserva con su texto original.
        $pdo->exec('UPDATE equipos e INNER JOIN clientes c ON c.id=e.cliente_id SET e.cliente_id=NULL WHERE c.activo=0');
        $pdo->exec('DELETE FROM clientes WHERE activo=0');

        $pdo->commit();

        $msg = $saltados ? "$saltados registros sin identificador (Clave/Id/Codigo) omitidos" : null;
        $pdo->prepare('UPDATE sync_log SET fin = NOW(), ok = 1, clientes = ?, mensaje = ? WHERE id = ?')
            ->execute([$nCli, $msg, $logId]);

        return [
            'clientes' => $nCli,
            'omitidos' => $saltados,
            'servicio' => (string)$c['servicio_requerido'],
            'campos' => [
                'clientes' => $clientes ? array_keys($clientes[0]) : [],
                'cliservicios' => $cliservicios ? array_keys($cliservicios[0]) : [],
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
    if (!api_sync_configurado()) {
        return;
    }
    $min = (int) (api_sync_conf()['sync_minutos'] ?? 15);
    if ($min <= 0) {
        return;
    }
    try {
        api_sync('auto', $min);
    } catch (Throwable $e) {
        error_log('[copias] sincronización automática: ' . $e->getMessage());
    }
}

/** Catálogo guardado de clientes. */
function api_sync_catalogo(): array
{
    $pdo = db();
    asegurar_tablas_sync($pdo);
    $cl = $pdo->query('SELECT id, clave, codigo, nombre, cif, email, telefono, localidad, provincia FROM clientes WHERE activo = 1 ORDER BY nombre')->fetchAll();
    $out = [];
    foreach ($cl as $c) {
        $c['id'] = (int) $c['id'];
        unset($c['clave']);
        $out[] = $c;
    }
    $ult = $pdo->query("SELECT DATE_FORMAT(inicio,'%Y-%m-%d %H:%i') AS fecha, ok, mensaje, clientes, origen
                        FROM sync_log ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    if ($ult) {
        $ult['ok'] = $ult['ok'] === null ? null : (int) $ult['ok'];
    }
    return [
        'clientes' => $out,
        'ultima' => $ult,
        'configurado' => api_sync_configurado(),
        'auto_minutos' => api_sync_configurado() ? (int) (api_sync_conf()['sync_minutos'] ?? 15) : 0,
    ];
}

/** Como api_sync_catalogo(), pero si nunca se ha sincronizado lo hace ahora (primera vez). */
function api_sync_catalogo_inicial(): array
{
    $cat = api_sync_catalogo();
    if (!$cat['clientes'] && !$cat['ultima'] && $cat['configurado']) {
        try {
            api_sync('auto');
        } catch (Throwable $e) {
            error_log('[copias] primera sincronización: ' . $e->getMessage());
        }
        $cat = api_sync_catalogo();
    }
    return $cat;
}
