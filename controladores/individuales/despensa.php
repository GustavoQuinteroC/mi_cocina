<?php

declare(strict_types=1);

/**
 * Despensa: por cada ingrediente del catálogo la familia marca dos cosas,
 * de forma independiente: "lo tengo" (tengo) y "hace falta" (hace_falta).
 * Solo se guarda una fila cuando alguna de las dos está activa; si ambas
 * quedan en 0, la fila se borra. Alimenta las recomendaciones de la IA
 * (lo que tengo), la exclusión de la lista de compras y el volcado de
 * faltantes a la lista de compras.
 */

/** Estado de la familia por ingrediente: nombre(min) => [tengo, hace_falta]. */
function estadoDespensa(): array
{
    global $database;
    $filas = $database->select('despensa', ['nombre_ingrediente', 'tengo', 'hace_falta'], filtroFamilia());
    $mapa = [];
    foreach ($filas as $fila) {
        $mapa[mb_strtolower($fila['nombre_ingrediente'])] = [
            'tengo' => (int) $fila['tengo'],
            'hace_falta' => (int) $fila['hace_falta'],
        ];
    }
    return $mapa;
}

/**
 * Todo el catálogo de ingredientes con el estado de la familia mezclado.
 * Requiere catalogoIngredientes() (controlador de platillos).
 *
 * @return array<int,array{nombre:string,categoria:string,tengo:int,hace_falta:int}>
 */
function catalogoConEstadoDespensa(): array
{
    $estado = estadoDespensa();
    $lista = [];
    foreach (catalogoIngredientes() as $ingrediente) {
        $clave = mb_strtolower($ingrediente['nombre']);
        $lista[] = [
            'nombre' => $ingrediente['nombre'],
            'categoria' => $ingrediente['categoria'],
            'tengo' => $estado[$clave]['tengo'] ?? 0,
            'hace_falta' => $estado[$clave]['hace_falta'] ?? 0,
        ];
    }
    return $lista;
}

/**
 * Fija "tengo" o "hace_falta" de un ingrediente (upsert por nombre).
 * Si ambas quedan en 0, borra la fila. Devuelve el estado final.
 *
 * @param string $campo 'tengo' | 'hace_falta'
 * @return array{tengo:int,hace_falta:int}
 */
function fijarEstadoDespensa(string $nombre, string $campo, bool $valor): array
{
    global $database;
    $nombre = trim($nombre);
    $campo = $campo === 'hace_falta' ? 'hace_falta' : 'tengo';

    $fila = $database->get('despensa', ['id', 'tengo', 'hace_falta'], filtroFamilia(['nombre_ingrediente' => $nombre]));
    if ($fila) {
        $id = (int) $fila['id'];
        $tengo = (int) $fila['tengo'];
        $haceFalta = (int) $fila['hace_falta'];
    } else {
        $database->insert('despensa', [
            'id_familia' => idFamiliaActual(),
            'nombre_ingrediente' => $nombre,
            'tengo' => 0,
            'hace_falta' => 0,
        ]);
        $id = (int) $database->id();
        $tengo = 0;
        $haceFalta = 0;
    }

    if ($campo === 'tengo') {
        $tengo = $valor ? 1 : 0;
    } else {
        $haceFalta = $valor ? 1 : 0;
    }

    if ($tengo === 0 && $haceFalta === 0) {
        $database->delete('despensa', filtroFamilia(['id' => $id]));
        return ['tengo' => 0, 'hace_falta' => 0];
    }

    $database->update('despensa', ['tengo' => $tengo, 'hace_falta' => $haceFalta], filtroFamilia(['id' => $id]));
    return ['tengo' => $tengo, 'hace_falta' => $haceFalta];
}

/** Nombres de lo que la familia TIENE en casa (para la IA y la lista de compras). */
function nombresDespensa(): array
{
    global $database;
    return array_column(
        $database->select('despensa', ['nombre_ingrediente'], filtroFamilia(['tengo' => 1])),
        'nombre_ingrediente'
    );
}

/**
 * Pasa a la lista de compras (origen manual) lo marcado como "hace falta",
 * sin duplicar lo que ya esté en la lista. Devuelve cuántos se agregaron.
 */
function faltantesDespensaALista(): int
{
    global $database;
    $faltantes = $database->select('despensa', ['nombre_ingrediente'], filtroFamilia(['hace_falta' => 1]));
    $agregados = 0;
    foreach ($faltantes as $item) {
        $nombre = $item['nombre_ingrediente'];
        if (!$database->has('lista_compras', filtroFamilia(['articulo' => $nombre]))) {
            $database->insert('lista_compras', [
                'id_familia' => idFamiliaActual(),
                'articulo' => $nombre,
                'cantidad' => 1,
                'unidad' => '',
                'comprado' => 0,
                'origen' => 'manual',
            ]);
            $agregados++;
        }
    }
    return $agregados;
}
