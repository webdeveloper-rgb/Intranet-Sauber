<?php
declare(strict_types=1);


/**
 * Acciones administrativas de asignacion, vigilancia y calendario esperado de copias por equipo.
 */
final class EquipmentController
{
    public static function handle(string $action, array $input, array $user): bool
    {
        if (in_array($action, ['equipo_combinar_prever', 'equipo_combinar'], true)) {
            exigir_admin($user);
            $destinoId = filter_var($input['destino_id'] ?? null, FILTER_VALIDATE_INT);
            $origenIds = $input['origen_ids'] ?? null;
            if (!$destinoId || !is_array($origenIds)) fail('Selección de equipos no válida.');
            try {
                if ($action === 'equipo_combinar_prever') {
                    $plan = equipos_combinar_plan(db(), $destinoId, $origenIds);
                    unset($plan['_rows'], $plan['_counts'], $plan['_monitor']);
                    out($plan);
                }
                if (!is_string($input['token'] ?? null)) fail('Primero revisa la combinación.');
                $agenteId = isset($input['agente_equipo_id']) ? filter_var($input['agente_equipo_id'], FILTER_VALIDATE_INT) : null;
                if ($agenteId !== null && (!$agenteId || $agenteId < 1)) fail('Agente no válido.');
                out(equipos_combinar(db(), $destinoId, $origenIds, $input['token'], (string) $user['usuario'], $agenteId));
            } catch (ApiError $ex) {
                fail($ex->getMessage(), 409);
            }
        }

        if ($action === 'equipo_revisar_errores') {
            $equipoId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if ($equipoId === false || $equipoId < 1) {
                fail('Equipo no válido.');
            }
            $description = txt($input['desc'] ?? '', 2000);
            if (palabras($description) < MIN_PALABRAS) {
                fail('La descripción debe tener al menos ' . MIN_PALABRAS . ' palabras.');
            }
            try {
                $errors = equipos_revisar_errores($equipoId, (string) $user['usuario'], $description);
                out([
                    'ok' => true,
                    'errores_pendientes' => $errors,
                    'error_reviewed_at' => date('Y-m-d H:i:s'),
                    'error_reviewed_by' => $user['usuario'],
                    'error_review_desc' => $description,
                ]);
            } catch (ApiError $ex) {
                fail($ex->getMessage());
            }
        }

        if ($action === 'equipo_asignar') {
            exigir_admin($user);
            try {
                $importadas = equipos_asignar_cliente((int) ($input['id'] ?? 0), (int) ($input['cliente_id'] ?? 0));
                out(['ok' => true, 'copias_importadas' => $importadas]);
            } catch (ApiError $ex) {
                fail($ex->getMessage());
            }
        }

        if ($action === 'equipo_vigilar') {
            exigir_admin($user);
            try {
                equipos_vigilar((int) ($input['id'] ?? 0), !empty($input['vigilar']));
                out(['ok' => true]);
            } catch (ApiError $ex) {
                fail($ex->getMessage());
            }
        }

        if ($action === 'equipo_horario') {
            exigir_admin($user);
            try {
                equipos_guardar_dias_copia((int) ($input['id'] ?? 0), $input['dias_copia'] ?? null);
                out(['ok' => true]);
            } catch (ApiError $ex) {
                fail($ex->getMessage());
            }
        }

        if ($action === 'monitor_event_mode') {
            exigir_admin($user);
            try {
                monitor_event_mode_guardar(
                    db(),
                    (int) ($input['id'] ?? 0),
                    is_string($input['event_extraction_mode'] ?? null) ? $input['event_extraction_mode'] : ''
                );
                out(['ok' => true]);
            } catch (ApiError $ex) {
                fail($ex->getMessage());
            }
        }

        if ($action === 'equipo_alias') {
            exigir_admin($user);
            try {
                $alias = monitor_alias_guardar(
                    db(),
                    (int) ($input['id'] ?? 0),
                    $input['alias'] ?? null
                );
                out(['ok' => true, 'alias' => $alias]);
            } catch (ApiError $ex) {
                fail($ex->getMessage());
            }
        }

        if ($action === 'equipo_tipo') {
            exigir_admin($user);
            try {
                $tipo = equipos_guardar_tipo((int) ($input['id'] ?? 0), $input['tipo'] ?? null);
                out(['ok' => true, 'tipo' => $tipo ?: null, 'tipo_manual' => ($input['tipo'] ?? null) === 'auto' ? null : $tipo]);
            } catch (ApiError $ex) {
                fail($ex->getMessage());
            }
        }

        if ($action === 'equipo_rustdesk_password') {
            exigir_admin($user);
            try {
                $password = monitor_password_equipo(db(), (int) ($input['id'] ?? 0));
                out(['ok' => true, 'password' => $password]);
            } catch (ApiError $ex) {
                fail($ex->getMessage());
            }
        }

        if ($action === 'equipo_desasignar') {
            exigir_admin($user);
            try {
                $actualizadas = equipos_desasignar_cliente((int) ($input['id'] ?? 0));
                out(['ok' => true, 'copias_desasignadas' => $actualizadas]);
            } catch (ApiError $ex) {
                fail($ex->getMessage());
            }
        }

        if ($action === 'equipos_desasignar_sin_satbk') {
            exigir_admin($user);
            try {
                out(['ok' => true] + equipos_desasignar_clientes_sin_satbk());
            } catch (ApiError $ex) {
                fail($ex->getMessage());
            }
        }

        return false;
    }
}
