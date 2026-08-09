<?php

declare(strict_types=1);

/**
 * Historial de cocina ("lo hice hoy"): alimenta las recomendaciones
 * y el cierre de ciclo del inicio.
 */

/** Registra que el platillo se cocinó ahora. */
function marcarCocinado(int $idPlatillo): void
{
    global $database;
    $platillo = $database->get('platillos', ['id', 'id_familia'], ['id' => $idPlatillo]);
    verificarFamiliaDeFila($platillo ?: null);

    $database->insert('historial_cocina', [
        'id_familia' => idFamiliaActual(),
        'id_platillo' => $idPlatillo,
        'id_cocinado_por' => idUsuarioActual(),
        'fecha_cocinado' => date('Y-m-d H:i:s'),
    ]);
}

/** Historial reciente de la familia con nombre de platillo y cocinero. */
function historialReciente(int $limite = 20): array
{
    global $database;
    return $database->select('historial_cocina', [
        '[>]platillos' => ['id_platillo' => 'id'],
        '[>]usuarios' => ['id_cocinado_por' => 'id'],
    ], [
        'historial_cocina.id',
        'historial_cocina.id_platillo',
        'historial_cocina.fecha_cocinado',
        'platillos.nombre(nombre_platillo)',
        'platillos.tono(tono_platillo)',
        'usuarios.nombre(nombre_cocinero)',
    ], [
        'historial_cocina.id_familia' => idFamiliaActual(),
        'ORDER' => ['historial_cocina.fecha_cocinado' => 'DESC'],
        'LIMIT' => $limite,
    ]);
}

/** Último registro de cocina de la familia (o null). */
function ultimaEntradaHistorial(): ?array
{
    $filas = historialReciente(1);
    return $filas[0] ?? null;
}

/** Ids de platillos cocinados ayer (para no repetirlos en recomendaciones). */
function idsCocinadosAyer(): array
{
    global $database;
    $ayer = date('Y-m-d', strtotime('-1 day'));
    return array_map('intval', $database->select('historial_cocina', 'id_platillo', filtroFamilia([
        'fecha_cocinado[<>]' => [$ayer . ' 00:00:00', $ayer . ' 23:59:59'],
    ])));
}
