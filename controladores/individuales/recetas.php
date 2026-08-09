<?php

declare(strict_types=1);

/**
 * Recetas (1:1 con platillo) y su validación.
 * `recetas` no tiene id_familia propio: el aislamiento se garantiza
 * verificando SIEMPRE que el platillo padre sea de la familia en sesión.
 * TODA receta (manual o de IA) pasa por validarReceta() antes de guardarse.
 */

/** Corta con 404 si el platillo no pertenece a la familia en sesión. */
function exigirPlatilloDeFamilia(int $idPlatillo): void
{
    global $database;
    $platillo = $database->get('platillos', ['id', 'id_familia'], ['id' => $idPlatillo]);
    verificarFamiliaDeFila($platillo ?: null);
}

/** Receta de un platillo (con ingredientes/pasos ya decodificados) o null. */
function obtenerRecetaDePlatillo(int $idPlatillo): ?array
{
    global $database;
    exigirPlatilloDeFamilia($idPlatillo);

    $fila = $database->get('recetas', '*', ['id_platillo' => $idPlatillo]);
    if (!$fila) {
        return null;
    }
    $fila['ingredientes'] = json_decode((string) $fila['ingredientes_json'], true) ?: [];
    $fila['pasos'] = json_decode((string) $fila['pasos_json'], true) ?: [];
    return $fila;
}

/**
 * Crea o reemplaza la receta de un platillo.
 * $datos ya viene validado por validarReceta() (shape exacto).
 */
function guardarRecetaDePlatillo(int $idPlatillo, array $datos, bool $esIa): void
{
    global $database;
    exigirPlatilloDeFamilia($idPlatillo);

    $registro = [
        'ingredientes_json' => json_encode($datos['ingredientes'], JSON_UNESCAPED_UNICODE),
        'pasos_json' => json_encode($datos['pasos'], JSON_UNESCAPED_UNICODE),
        'notas' => $datos['notas'] ?? null,
        'url_fuente' => $datos['url_fuente'] ?? '',
        'generada_ia' => $esIa ? 1 : 0,
        'verificada' => $esIa ? 0 : 1, // lo capturado a mano se asume revisado; lo de IA requiere revisión
        'sistema_actualizacion' => date('Y-m-d H:i:s'),
    ];

    if ($database->has('recetas', ['id_platillo' => $idPlatillo])) {
        $database->update('recetas', $registro, ['id_platillo' => $idPlatillo]);
    } else {
        $registro['id_platillo'] = $idPlatillo;
        $database->insert('recetas', $registro);
    }

    // Si la receta trae porciones/tiempo, sincroniza el platillo.
    $actualizarPlatillo = [];
    if (!empty($datos['porciones'])) {
        $actualizarPlatillo['porciones'] = (int) $datos['porciones'];
    }
    if (!empty($datos['tiempo_preparacion'])) {
        $actualizarPlatillo['tiempo_preparacion'] = (int) $datos['tiempo_preparacion'];
    }
    if ($actualizarPlatillo !== []) {
        $database->update('platillos', $actualizarPlatillo, filtroFamilia(['id' => $idPlatillo]));
    }
}

/** Marca la receta como revisada por un humano. */
function marcarRecetaVerificada(int $idPlatillo): void
{
    global $database;
    exigirPlatilloDeFamilia($idPlatillo);
    $database->update('recetas', ['verificada' => 1], ['id_platillo' => $idPlatillo]);
}

function eliminarRecetaDePlatillo(int $idPlatillo): void
{
    global $database;
    exigirPlatilloDeFamilia($idPlatillo);
    $database->delete('recetas', ['id_platillo' => $idPlatillo]);
}

/**
 * Reconstruye una receta cruda a partir del formulario de platillo,
 * que envía arreglos: ingrediente_nombre[], ingrediente_cantidad[],
 * ingrediente_unidad[] y paso[]. Los renglones vacíos se ignoran.
 * El resultado se valida después con validarReceta().
 */
function recetaDesdeFormulario(): array
{
    $ingredientes = [];
    $nombres = $_POST['ingrediente_nombre'] ?? [];
    $cantidades = $_POST['ingrediente_cantidad'] ?? [];
    $unidades = $_POST['ingrediente_unidad'] ?? [];
    if (is_array($nombres)) {
        foreach ($nombres as $i => $nombre) {
            $nombre = trim((string) $nombre);
            if ($nombre === '') {
                continue;
            }
            $ingredientes[] = [
                'nombre' => $nombre,
                'cantidad' => $cantidades[$i] ?? null,
                'unidad' => trim((string) ($unidades[$i] ?? '')),
            ];
        }
    }

    $pasos = [];
    foreach (($_POST['paso'] ?? []) as $paso) {
        $paso = trim((string) $paso);
        if ($paso !== '') {
            $pasos[] = $paso;
        }
    }

    return [
        'ingredientes' => $ingredientes,
        'pasos' => $pasos,
        'notas' => post('notas') ?: null,
        'url_fuente' => post('url_fuente') ?: null,
    ];
}

// ---------------------------------------------------------------------------
// Validación (barrera anti-alucinación): si el JSON de la IA o de un
// formulario no cumple campos, tipos y cantidades coherentes, NO se guarda.
//
// Shape esperado (mismo de la tabla `recetas`):
//   ingredientes: [{nombre, cantidad, unidad}], pasos: [string, ...],
//   notas?, url_fuente?, porciones?, tiempo_preparacion?
// ---------------------------------------------------------------------------

/**
 * Valida el shape exacto de una receta ANTES de persistirla.
 * Devuelve la receta limpia, o null dejando el detalle en $errores.
 *
 * @param string[] $errores
 */
function validarReceta(array $datos, array &$errores): ?array
{
    $limpia = [
        'ingredientes' => validarIngredientesReceta($datos['ingredientes'] ?? null, $errores),
        'pasos' => validarPasosReceta($datos['pasos'] ?? null, $errores),
        'notas' => validarTextoOpcional($datos['notas'] ?? null, 2000, 'notas', $errores),
        'url_fuente' => validarUrlOpcional($datos['url_fuente'] ?? null, $errores),
        'porciones' => validarEnteroOpcional($datos['porciones'] ?? null, 1, 50, 'porciones', $errores),
        'tiempo_preparacion' => validarEnteroOpcional($datos['tiempo_preparacion'] ?? null, 1, 1440, 'tiempo_preparacion', $errores),
    ];

    return $errores === [] ? $limpia : null;
}

/** @param string[] $errores */
function validarIngredientesReceta(mixed $ingredientes, array &$errores): array
{
    if (!is_array($ingredientes) || $ingredientes === [] || array_is_list($ingredientes) === false) {
        $errores[] = 'ingredientes debe ser una lista no vacía.';
        return [];
    }
    if (count($ingredientes) > 60) {
        $errores[] = 'Demasiados ingredientes (máx. 60).';
        return [];
    }

    $limpios = [];
    foreach ($ingredientes as $i => $ingrediente) {
        if (!is_array($ingrediente)) {
            $errores[] = "Ingrediente #$i: formato inválido.";
            continue;
        }
        $nombre = is_string($ingrediente['nombre'] ?? null) ? trim($ingrediente['nombre']) : '';
        if ($nombre === '' || mb_strlen($nombre) > 160) {
            $errores[] = "Ingrediente #$i: nombre vacío o demasiado largo.";
            continue;
        }

        // Cantidad: numérica positiva y dentro de un rango sensato.
        $cantidad = $ingrediente['cantidad'] ?? null;
        if (!is_numeric($cantidad)) {
            $errores[] = "Ingrediente '$nombre': cantidad no numérica.";
            continue;
        }
        $cantidad = round((float) $cantidad, 3);
        if ($cantidad <= 0 || $cantidad > 10000) {
            $errores[] = "Ingrediente '$nombre': cantidad fuera de rango (0 < cantidad <= 10000).";
            continue;
        }

        $unidad = is_string($ingrediente['unidad'] ?? null) ? trim($ingrediente['unidad']) : '';
        if (mb_strlen($unidad) > 60) {
            $errores[] = "Ingrediente '$nombre': unidad demasiado larga.";
            continue;
        }

        $limpios[] = ['nombre' => $nombre, 'cantidad' => $cantidad, 'unidad' => $unidad];
    }

    if ($limpios === [] && $errores === []) {
        $errores[] = 'No hay ingredientes válidos.';
    }
    return $limpios;
}

/** @param string[] $errores */
function validarPasosReceta(mixed $pasos, array &$errores): array
{
    if (!is_array($pasos) || $pasos === [] || array_is_list($pasos) === false) {
        $errores[] = 'pasos debe ser una lista ordenada no vacía.';
        return [];
    }
    if (count($pasos) > 40) {
        $errores[] = 'Demasiados pasos (máx. 40).';
        return [];
    }

    $limpios = [];
    foreach ($pasos as $i => $paso) {
        if (!is_string($paso)) {
            $errores[] = 'Paso #' . ($i + 1) . ': debe ser texto.';
            continue;
        }
        $paso = trim($paso);
        if (mb_strlen($paso) < 5 || mb_strlen($paso) > 600) {
            $errores[] = 'Paso #' . ($i + 1) . ': largo inválido (5–600 caracteres).';
            continue;
        }
        $limpios[] = $paso;
    }

    if ($limpios === [] && $errores === []) {
        $errores[] = 'No hay pasos válidos.';
    }
    return $limpios;
}

/** @param string[] $errores */
function validarTextoOpcional(mixed $valor, int $largoMaximo, string $campo, array &$errores): ?string
{
    if ($valor === null || $valor === '') {
        return null;
    }
    if (!is_string($valor)) {
        $errores[] = "$campo debe ser texto.";
        return null;
    }
    $valor = trim($valor);
    if (mb_strlen($valor) > $largoMaximo) {
        $errores[] = "$campo demasiado largo (máx. $largoMaximo).";
        return null;
    }
    return $valor === '' ? null : $valor;
}

/** @param string[] $errores */
function validarUrlOpcional(mixed $valor, array &$errores): ?string
{
    if ($valor === null || $valor === '') {
        return null;
    }
    if (!is_string($valor) || mb_strlen($valor) > 500 || !filter_var($valor, FILTER_VALIDATE_URL)) {
        $errores[] = 'url_fuente no es una URL válida.';
        return null;
    }
    if (!preg_match('#^https?://#i', $valor)) {
        $errores[] = 'url_fuente debe ser http(s).';
        return null;
    }
    return $valor;
}

/** @param string[] $errores */
function validarEnteroOpcional(mixed $valor, int $minimo, int $maximo, string $campo, array &$errores): ?int
{
    if ($valor === null || $valor === '') {
        return null;
    }
    if (!is_numeric($valor) || (int) $valor != $valor) {
        $errores[] = "$campo debe ser un entero.";
        return null;
    }
    $valor = (int) $valor;
    if ($valor < $minimo || $valor > $maximo) {
        $errores[] = "$campo fuera de rango ($minimo–$maximo).";
        return null;
    }
    return $valor;
}
