<?php
declare(strict_types=1);

function monitor_token_emitir(PDO $pdo, string $machineId): string
{
    $pdo->exec("DELETE FROM monitor_ingest_tokens
        WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
            OR consumed_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $token = 'mtr_' . bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO monitor_ingest_tokens (token_hash,machine_id,expires_at)
        VALUES (?,?,DATE_ADD(NOW(), INTERVAL 5 MINUTE))')
        ->execute([hash('sha256', $token), $machineId]);
    return $token;
}

function monitor_enrollment_code_valido(?string $code): bool
{
    return is_string($code) && preg_match('/\Amre_[0-9a-f]{48}\z/', $code) === 1;
}

function monitor_credencial_agente_valida(?string $token): bool
{
    return is_string($token) && preg_match('/\Amra_[0-9a-f]{64}\z/', $token) === 1;
}

function monitor_enrollment_crear(string $createdBy): array
{
    $pdo = db();
    $pdo->exec("DELETE FROM monitor_enrollments
        WHERE (consumed_at IS NOT NULL OR revoked_at IS NOT NULL OR expires_at < NOW())
            AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $code = 'mre_' . bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO monitor_enrollments (code_hash,code_prefix,created_by,expires_at)
        VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 15 MINUTE))')
        ->execute([hash('sha256', $code), substr($code, 0, 12), $createdBy]);
    return [
        'id' => (int) $pdo->lastInsertId(),
        'code' => $code,
        'prefix' => substr($code, 0, 12),
        'expires_in' => 900,
    ];
}

function monitor_enrollment_listar(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id,code_prefix,created_by,consumed_machine_id AS machine_id,
            DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at,
            DATE_FORMAT(expires_at,'%Y-%m-%d %H:%i') AS expires_at,
            DATE_FORMAT(consumed_at,'%Y-%m-%d %H:%i') AS consumed_at,
            DATE_FORMAT(revoked_at,'%Y-%m-%d %H:%i') AS revoked_at,
            (consumed_at IS NULL AND revoked_at IS NULL AND expires_at>NOW()) AS active
        FROM monitor_enrollments ORDER BY id DESC LIMIT 100")->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['active'] = (int) $row['active'];
    }
    unset($row);
    return $rows;
}

function monitor_enrollment_revocar(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('UPDATE monitor_enrollments SET revoked_at=NOW()
        WHERE id=? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>NOW()');
    $st->execute([$id]);
    return $st->rowCount() === 1;
}

function monitor_enrollment_redimir(PDO $pdo, ?string $code, string $machineId, ?string $deviceToken): bool
{
    if (
        !monitor_enrollment_code_valido($code)
        || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $machineId)
        || !monitor_credencial_agente_valida($deviceToken)
    ) {
        return false;
    }

    $codeHash = hash('sha256', $code);
    $tokenHash = hash('sha256', $deviceToken);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id,consumed_at,consumed_machine_id,revoked_at,expires_at
            FROM monitor_enrollments WHERE code_hash=? FOR UPDATE');
        $st->execute([$codeHash]);
        $enrollment = $st->fetch();
        if (!$enrollment) {
            $pdo->rollBack();
            return false;
        }

        if ($enrollment['consumed_at'] !== null) {
            $st = $pdo->prepare('SELECT credential_hash,active FROM monitor_agents WHERE machine_id=?');
            $st->execute([$machineId]);
            $agent = $st->fetch();
            $sameRequest = $enrollment['consumed_machine_id'] !== null
                && hash_equals((string) $enrollment['consumed_machine_id'], $machineId)
                && $agent
                && (int) $agent['active'] === 1
                && hash_equals((string) $agent['credential_hash'], $tokenHash);
            $pdo->commit();
            return (bool) $sameRequest;
        }

        if ($enrollment['revoked_at'] !== null || strtotime((string) $enrollment['expires_at']) <= time()) {
            $pdo->rollBack();
            return false;
        }

        $pdo->prepare('UPDATE monitor_enrollments SET consumed_at=NOW(),consumed_machine_id=? WHERE id=?')
            ->execute([$machineId, $enrollment['id']]);
        $pdo->prepare("INSERT INTO monitor_agents (machine_id,credential_hash,active)
            VALUES (?,?,1)
            ON DUPLICATE KEY UPDATE credential_hash=VALUES(credential_hash),active=1,created_at=NOW(),last_used_at=NULL")
            ->execute([$machineId, $tokenHash]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Resuelve hasta varias generaciones de una identidad mientras equipos antiguos terminan de actualizarse. */
function monitor_machine_id_canonico(PDO $pdo, string $machineId): string
{
    $query = $pdo->prepare('SELECT machine_id FROM monitor_machine_id_aliases WHERE previous_machine_id=?');
    $seen = [];
    for ($i = 0; $i < 8; $i++) {
        if (isset($seen[$machineId])) break;
        $seen[$machineId] = true;
        $query->execute([$machineId]);
        $next = $query->fetchColumn();
        if (!is_string($next) || $next === '' || isset($seen[$next])) break;
        $machineId = $next;
    }
    return $machineId;
}

function monitor_machine_id_alias_guardar(PDO $pdo, string $oldMachineId, string $newMachineId): void
{
    if ($oldMachineId === $newMachineId) return;
    $pdo->prepare('INSERT INTO monitor_machine_id_aliases (previous_machine_id,machine_id) VALUES (?,?)
        ON DUPLICATE KEY UPDATE machine_id=VALUES(machine_id)')->execute([$oldMachineId, $newMachineId]);
}

function monitor_agente_autenticar(PDO $pdo, ?string $token, string $machineId): bool
{
    if (!monitor_credencial_agente_valida($token)) {
        return false;
    }
    $canonicalId = monitor_machine_id_canonico($pdo, $machineId);
    $st = $pdo->prepare('SELECT credential_hash FROM monitor_agents WHERE machine_id=? AND active=1');
    $st->execute([$canonicalId]);
    $storedHash = $st->fetchColumn();
    if (!is_string($storedHash) || !hash_equals($storedHash, hash('sha256', $token))) {
        return false;
    }
    $pdo->prepare('UPDATE monitor_agents SET last_used_at=NOW() WHERE machine_id=?')->execute([$canonicalId]);
    return true;
}

/** La comprobacion bajo bloqueo protege tambien envios autenticados antes de combinar. */
function monitor_agente_comprobar_retirado(PDO $pdo, string $machineId): void
{
    $machineId = monitor_machine_id_canonico($pdo, $machineId);
    $q = $pdo->prepare('SELECT active FROM monitor_agents WHERE machine_id=? FOR UPDATE');
    $q->execute([$machineId]);
    $active = $q->fetchColumn();
    if ($active !== false && (int) $active === 0) {
        throw new InvalidArgumentException('Este agente fue desactivado al combinar equipos.');
    }
}

function monitor_token_consumir(PDO $pdo, ?string $token, string $machineId): bool
{
    if ($token === null || !preg_match('/\Amtr_[0-9a-f]{64}\z/', $token)) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id,machine_id FROM monitor_ingest_tokens
            WHERE token_hash=? AND consumed_at IS NULL AND expires_at>NOW() FOR UPDATE');
        $st->execute([hash('sha256', $token)]);
        $row = $st->fetch();
        if (!$row || !hash_equals((string) $row['machine_id'], $machineId)) {
            $pdo->rollBack();
            return false;
        }
        try {
            monitor_agente_comprobar_retirado($pdo, $machineId);
        } catch (InvalidArgumentException $e) {
            $pdo->rollBack();
            return false;
        }
        $st = $pdo->prepare('UPDATE monitor_ingest_tokens SET consumed_at=NOW()
            WHERE id=? AND consumed_at IS NULL');
        $st->execute([$row['id']]);
        if ($st->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
