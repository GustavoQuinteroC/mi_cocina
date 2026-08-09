<?php

declare(strict_types=1);

/**
 * [Fase 2] Lista de compras a partir del plan semanal:
 * suma los ingredientes de las recetas planeadas y resta lo que ya hay
 * en la despensa. Las tablas ya existen en la migración; estas funciones
 * quedan listas para cuando se exponga la vista.
 */

/**
 * Calcula los faltantes de la semana que inicia en $lunes (Y-m-d).
 *
 * @return array<int,array{articulo:string,cantidad:float,unidad:string}>
 */
function calcularListaCompras(string $lunes): array
{
    global $database;
    $idFamilia = idFamiliaActual();
    $domingo = date('Y-m-d', strtotime($lunes . ' +6 days'));

    // Recetas de los platillos planeados esta semana (filtradas por familia).
    $filas = $database->select('plan_semanal', [
        '[>]recetas' => ['id_platillo' => 'id_platillo'],
    ], [
        'recetas.ingredientes_json(ingredientes)',
    ], [
        'plan_semanal.id_familia' => $idFamilia,
        'plan_semanal.fecha_plan[<>]' => [$lunes, $domingo],
    ]);

    // Agrega por (nombre normalizado, unidad).
    $necesarios = [];
    foreach ($filas as $fila) {
        $ingredientes = json_decode((string) ($fila['ingredientes'] ?? ''), true) ?: [];
        foreach ($ingredientes as $ingrediente) {
            if (!isset($ingrediente['nombre'], $ingrediente['cantidad'])) {
                continue;
            }
            $llave = mb_strtolower(trim($ingrediente['nombre'])) . '|' . mb_strtolower(trim($ingrediente['unidad'] ?? ''));
            if (!isset($necesarios[$llave])) {
                $necesarios[$llave] = [
                    'articulo' => trim($ingrediente['nombre']),
                    'cantidad' => 0.0,
                    'unidad' => trim($ingrediente['unidad'] ?? ''),
                ];
            }
            $necesarios[$llave]['cantidad'] += (float) $ingrediente['cantidad'];
        }
    }

    // Excluye lo que ya hay en casa (por nombre; la despensa no maneja cantidades).
    $enCasa = array_map(
        fn($n) => mb_strtolower(trim($n)),
        array_column($database->select('despensa', ['nombre_ingrediente'], ['id_familia' => $idFamilia, 'tengo' => 1]), 'nombre_ingrediente')
    );

    $faltantes = [];
    foreach ($necesarios as $articulo) {
        if (in_array(mb_strtolower($articulo['articulo']), $enCasa, true)) {
            continue; // ya lo tengo en casa
        }
        $articulo['cantidad'] = round($articulo['cantidad'], 2);
        $faltantes[] = $articulo;
    }
    return $faltantes;
}

/** Persiste los faltantes en lista_compras con origen = plan. */
function guardarListaCompras(array $articulos): int
{
    global $database;
    $idFamilia = idFamiliaActual();

    // Reemplaza lo generado por plan anterior; lo manual se conserva.
    $database->delete('lista_compras', ['id_familia' => $idFamilia, 'origen' => 'plan']);

    foreach ($articulos as $articulo) {
        $database->insert('lista_compras', [
            'id_familia' => $idFamilia,
            'articulo' => $articulo['articulo'],
            'cantidad' => $articulo['cantidad'],
            'unidad' => $articulo['unidad'] ?: '',
            'comprado' => 0,
            'origen' => 'plan',
        ]);
    }
    return count($articulos);
}

/** Genera la lista desde el plan de la semana que inicia en $lunes. */
function generarListaDesdePlan(string $lunes): int
{
    return guardarListaCompras(calcularListaCompras($lunes));
}

// ---------------------------------------------------------------------------
// Operaciones de la vista de lista de compras
// ---------------------------------------------------------------------------

/** Artículos de la familia (pendientes primero, más recientes arriba). */
function listarCompras(): array
{
    global $database;
    return $database->select(
        'lista_compras',
        ['id', 'articulo', 'cantidad', 'unidad', 'comprado', 'origen'],
        filtroFamilia(['ORDER' => ['comprado' => 'ASC', 'id' => 'DESC']])
    );
}

/** Agrega un artículo manual y devuelve la fila creada. */
function agregarItemCompra(string $articulo, float $cantidad, string $unidad): array
{
    global $database;
    $database->insert('lista_compras', [
        'id_familia' => idFamiliaActual(),
        'articulo' => $articulo,
        'cantidad' => $cantidad,
        'unidad' => $unidad,
        'comprado' => 0,
        'origen' => 'manual',
    ]);
    $id = (int) $database->id();
    return $database->get('lista_compras', ['id', 'articulo', 'cantidad', 'unidad', 'comprado', 'origen'], ['id' => $id]);
}

/** Palomea/despalomea un artículo. Devuelve el estado final (true = comprado). */
function alternarCompradoItem(int $id): bool
{
    global $database;
    $item = $database->get('lista_compras', ['id', 'comprado', 'id_familia'], ['id' => $id]);
    verificarFamiliaDeFila($item ?: null);

    $nuevo = ((int) $item['comprado'] === 1) ? 0 : 1;
    $database->update('lista_compras', ['comprado' => $nuevo], filtroFamilia(['id' => $id]));
    return $nuevo === 1;
}

/** Elimina un artículo de la lista de la familia. */
function eliminarItemCompra(int $id): void
{
    global $database;
    $item = $database->get('lista_compras', ['id', 'id_familia'], ['id' => $id]);
    verificarFamiliaDeFila($item ?: null);
    $database->delete('lista_compras', filtroFamilia(['id' => $id]));
}
