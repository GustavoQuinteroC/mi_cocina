<?php

declare(strict_types=1);

/**
 * Planificador semanal: un platillo por (fecha, tipo de comida) y familia.
 */

/**
 * Plan de una semana como matriz [fecha][tipo_comida] => platillo.
 * $lunes en formato Y-m-d.
 */
function planDeSemana(string $lunes): array
{
    global $database;
    $domingo = date('Y-m-d', strtotime($lunes . ' +6 days'));

    $filas = $database->select('plan_semanal', [
        '[>]platillos' => ['id_platillo' => 'id'],
    ], [
        'plan_semanal.fecha_plan',
        'plan_semanal.tipo_comida',
        'plan_semanal.id_platillo',
        'platillos.nombre(nombre_platillo)',
        'platillos.tono(tono_platillo)',
        'platillos.tiempo_preparacion(tiempo_platillo)',
    ], [
        'plan_semanal.id_familia' => idFamiliaActual(),
        'plan_semanal.fecha_plan[<>]' => [$lunes, $domingo],
    ]);

    $plan = [];
    foreach ($filas as $fila) {
        $fecha = substr((string) $fila['fecha_plan'], 0, 10);
        $plan[$fecha][$fila['tipo_comida']] = $fila;
    }
    return $plan;
}

/** Platillos planeados para hoy (inicio). */
function planDeHoy(): array
{
    global $database;
    $filas = $database->select('plan_semanal', [
        '[>]platillos' => ['id_platillo' => 'id'],
    ], [
        'plan_semanal.tipo_comida',
        'plan_semanal.id_platillo',
        'platillos.nombre(nombre_platillo)',
        'platillos.tono(tono_platillo)',
    ], [
        'plan_semanal.id_familia' => idFamiliaActual(),
        'plan_semanal.fecha_plan' => date('Y-m-d'),
    ]);

    // Orden natural del día
    $orden = array_flip(TIPOS_COMIDA);
    usort($filas, fn($a, $b) => $orden[$a['tipo_comida']] <=> $orden[$b['tipo_comida']]);
    return $filas;
}

/** Asigna (o quita, con id null) el platillo de una celda del plan. */
function asignarCeldaPlan(string $fecha, string $tipoComida, ?int $idPlatillo): void
{
    global $database;
    $where = filtroFamilia(['fecha_plan' => $fecha, 'tipo_comida' => $tipoComida]);

    if ($idPlatillo === null) {
        $database->delete('plan_semanal', $where);
        return;
    }

    // El platillo debe ser de la familia en sesión.
    $platillo = $database->get('platillos', ['id', 'id_familia'], ['id' => $idPlatillo]);
    verificarFamiliaDeFila($platillo ?: null);

    if ($database->has('plan_semanal', $where)) {
        $database->update('plan_semanal', ['id_platillo' => $idPlatillo, 'id_creado_por' => idUsuarioActual()], $where);
    } else {
        $database->insert('plan_semanal', [
            'id_familia' => idFamiliaActual(),
            'fecha_plan' => $fecha,
            'tipo_comida' => $tipoComida,
            'id_platillo' => $idPlatillo,
            'id_creado_por' => idUsuarioActual(),
        ]);
    }
}
