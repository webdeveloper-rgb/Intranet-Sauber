<?php
declare(strict_types=1);

final class EquipmentController
{
    public static function handle(string $action, array $input, array $user): bool
    {
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

        if ($action === 'equipo_desasignar') {
            exigir_admin($user);
            try {
                $actualizadas = equipos_desasignar_cliente((int) ($input['id'] ?? 0));
                out(['ok' => true, 'copias_desasignadas' => $actualizadas]);
            } catch (ApiError $ex) {
                fail($ex->getMessage());
            }
        }

        return false;
    }
}
