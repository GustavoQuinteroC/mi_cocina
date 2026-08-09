<?php

declare(strict_types=1);

/**
 * Endpoint AJAX central de Mi Cocina.
 * Recibe POST con JSON { accion: "...", ...datos } y responde JSON.
 * Toda petición exige sesión activa y token CSRF (cabecera X-CSRF-Token).
 *
 * Acciones: favorito, hecho, guardar_receta, verificar_receta,
 *           plan_celda, ia_receta, ia_sugerir, ia_ajustar.
 */

require_once __DIR__ . '/globales/funcionesPhp.php';
require_once __DIR__ . '/globales/funcionesIA.php';
require_once __DIR__ . '/individuales/platillos.php';
require_once __DIR__ . '/individuales/recetas.php';
require_once __DIR__ . '/individuales/historial.php';
require_once __DIR__ . '/individuales/plan_semanal.php';
require_once __DIR__ . '/individuales/familia.php';
require_once __DIR__ . '/individuales/lista_compras.php';
require_once __DIR__ . '/individuales/despensa.php';

validarSesion();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respuestaJson(['error' => 'Método no permitido.'], 405);
}
exigirCsrf();

$peticion = cuerpoJson();
$accion = (string) ($peticion['accion'] ?? '');

try {
    switch ($accion) {

        // ---- Favorito (toggle) ----
        case 'favorito':
            $esFavorito = alternarFavorito((int) ($peticion['id_platillo'] ?? 0));
            respuestaJson(['ok' => true, 'es_favorito' => $esFavorito]);

        // ---- "Lo hice hoy" ----
        case 'hecho':
            marcarCocinado((int) ($peticion['id_platillo'] ?? 0));
            respuestaJson(['ok' => true, 'cocinado_hoy' => true, 'message' => '¡Registrado! Buen provecho.']);

        // ---- Guardar receta (manual o ajuste de IA confirmado) ----
        case 'guardar_receta':
            $idPlatillo = (int) ($peticion['id_platillo'] ?? 0);
            if (obtenerPlatillo($idPlatillo) === null) {
                respuestaJson(['error' => 'Platillo no encontrado.'], 404);
            }
            $errores = [];
            $limpia = validarReceta($peticion, $errores);
            if ($limpia === null) {
                respuestaJson(['error' => 'Receta inválida.', 'details' => $errores], 422);
            }
            $deIa = !empty($peticion['de_ia']);
            guardarRecetaDePlatillo($idPlatillo, $limpia, $deIa);
            respuestaJson(['ok' => true, 'message' => $deIa
                ? 'Receta guardada. Recuerda revisarla y marcarla como verificada.'
                : 'Receta guardada.']);

        // ---- Marcar receta como verificada por un humano ----
        case 'verificar_receta':
            $idPlatillo = (int) ($peticion['id_platillo'] ?? 0);
            if (obtenerRecetaDePlatillo($idPlatillo) === null) {
                respuestaJson(['error' => 'Ese platillo no tiene receta.'], 404);
            }
            marcarRecetaVerificada($idPlatillo);
            respuestaJson(['ok' => true, 'message' => 'Receta marcada como verificada.']);

        // ---- Celda del plan semanal ----
        case 'plan_celda':
            $fecha = (string) ($peticion['fecha'] ?? '');
            $tipoComida = (string) ($peticion['tipo_comida'] ?? '');
            $idPlatillo = $peticion['id_platillo'] ?? null;

            $fechaValida = DateTime::createFromFormat('Y-m-d', $fecha);
            if ($fechaValida === false || $fechaValida->format('Y-m-d') !== $fecha) {
                respuestaJson(['error' => 'Fecha inválida.'], 422);
            }
            if (!in_array($tipoComida, TIPOS_COMIDA, true)) {
                respuestaJson(['error' => 'Tipo de comida inválido.'], 422);
            }
            if ($idPlatillo !== null && (!is_numeric($idPlatillo) || (int) $idPlatillo < 1)) {
                respuestaJson(['error' => 'Platillo inválido.'], 422);
            }
            asignarCeldaPlan($fecha, $tipoComida, $idPlatillo === null ? null : (int) $idPlatillo);
            respuestaJson(['ok' => true]);

        // ---- IA: generar receta de un platillo existente ----
        case 'ia_receta':
            if (!iaActiva()) {
                respuestaJson(['error' => 'IA desactivada: configura OPENAI_API_KEY en .env.'], 503);
            }
            $idPlatillo = (int) ($peticion['id_platillo'] ?? 0);
            $platillo = obtenerPlatillo($idPlatillo);
            if ($platillo === null) {
                respuestaJson(['error' => 'Platillo no encontrado.'], 404);
            }

            $cruda = generarRecetaIA($platillo['nombre'], [
                'tono' => $platillo['tono'],
                'tipo_comida' => $platillo['tipo_comida'],
                'cocina' => $platillo['cocina'],
                'porciones' => contarMiembrosFamilia(), // escala a las personas de la familia
                'preferencias' => preferenciasFamilia(),
            ]);

            // Validación estricta: si no pasa, NO se persiste.
            $errores = [];
            $limpia = validarReceta($cruda, $errores);
            if ($limpia === null) {
                respuestaJson([
                    'error' => 'La IA devolvió una receta inválida y no se guardó. Intenta de nuevo.',
                    'details' => $errores,
                ], 422);
            }
            guardarRecetaDePlatillo($idPlatillo, $limpia, esIa: true);
            registroLog('ia', 'Receta generada con IA para el platillo ' . $idPlatillo);
            respuestaJson([
                'ok' => true,
                'message' => 'Receta generada con IA. Está marcada como NO verificada hasta que la revises.',
                'receta' => $limpia,
            ]);

        // ---- IA: sugerir platillo + receta según el tono del día ----
        case 'ia_sugerir':
            if (!iaActiva()) {
                respuestaJson(['error' => 'IA desactivada: configura OPENAI_API_KEY en .env.'], 503);
            }
            $tono = (string) ($peticion['tono'] ?? '');
            $tipoComida = $peticion['tipo_comida'] ?? null;

            if (!in_array($tono, TONOS_PLATILLO, true)) {
                respuestaJson(['error' => 'Tono inválido.'], 422);
            }
            if ($tipoComida !== null && !in_array($tipoComida, TIPOS_COMIDA, true)) {
                respuestaJson(['error' => 'Tipo de comida inválido.'], 422);
            }

            $nombresExistentes = array_column(listarPlatillos([]), 'nombre');
            $datos = sugerirPlatilloIA($tono, $tipoComida, preferenciasFamilia(), $nombresExistentes, contarMiembrosFamilia());

            $errores = [];
            $recetaLimpia = validarReceta($datos['receta'], $errores);
            if ($recetaLimpia === null) {
                respuestaJson([
                    'error' => 'La sugerencia de la IA no pasó la validación y no se guardó.',
                    'details' => $errores,
                ], 422);
            }

            // Normaliza el platillo sugerido con los mismos límites del formulario.
            $sugerido = $datos['platillo'];
            $idPlatillo = crearPlatillo([
                'nombre' => mb_substr(trim((string) ($sugerido['nombre'] ?? 'Platillo IA')), 0, 160),
                'descripcion' => mb_substr(trim((string) ($sugerido['descripcion'] ?? '')), 0, 2000),
                'tono' => $tono,
                'tipo_comida' => in_array($sugerido['tipo_comida'] ?? '', TIPOS_COMIDA, true) ? $sugerido['tipo_comida'] : 'comida',
                'cocina' => mb_substr(trim((string) ($sugerido['cocina'] ?? '')), 0, 80),
                'dificultad' => min(5, max(1, (int) ($sugerido['dificultad'] ?? 2))),
                'tiempo_preparacion' => $recetaLimpia['tiempo_preparacion'],
                'porciones' => $recetaLimpia['porciones'] ?? 4,
                'imagen_url' => '',
                'generado_ia' => 1,
            ]);
            guardarRecetaDePlatillo($idPlatillo, $recetaLimpia, esIa: true);
            registroLog('ia', 'Platillo sugerido por IA: ' . $idPlatillo);
            respuestaJson([
                'ok' => true,
                'id_platillo' => $idPlatillo,
                'message' => 'La IA sugirió "' . ($sugerido['nombre'] ?? 'un platillo') . '". Revisa su receta antes de cocinar.',
            ]);

        // ---- IA: ajustar receta (devuelve propuesta, NO persiste) ----
        case 'ia_ajustar':
            if (!iaActiva()) {
                respuestaJson(['error' => 'IA desactivada: configura OPENAI_API_KEY en .env.'], 503);
            }
            $idPlatillo = (int) ($peticion['id_platillo'] ?? 0);
            $instruccion = trim((string) ($peticion['instruccion'] ?? ''));

            if (mb_strlen($instruccion) < 3 || mb_strlen($instruccion) > 300) {
                respuestaJson(['error' => 'Describe el ajuste en 3–300 caracteres.'], 422);
            }
            $platillo = obtenerPlatillo($idPlatillo);
            if ($platillo === null) {
                respuestaJson(['error' => 'Platillo no encontrado.'], 404);
            }
            $receta = obtenerRecetaDePlatillo($idPlatillo);
            if ($receta === null) {
                respuestaJson(['error' => 'Ese platillo aún no tiene receta que ajustar.'], 404);
            }

            $cruda = ajustarRecetaIA($platillo['nombre'], [
                'ingredientes' => $receta['ingredientes'],
                'pasos' => $receta['pasos'],
                'porciones' => $platillo['porciones'],
                'tiempo_preparacion' => $platillo['tiempo_preparacion'],
            ], $instruccion);

            $errores = [];
            $limpia = validarReceta($cruda, $errores);
            if ($limpia === null) {
                respuestaJson(['error' => 'El ajuste de la IA no pasó la validación.', 'details' => $errores], 422);
            }
            // La propuesta se guarda solo cuando el usuario confirma (accion guardar_receta con de_ia=true).
            respuestaJson(['ok' => true, 'propuesta' => $limpia]);

        // ---- Lista de compras: agregar artículo manual ----
        case 'compra_agregar':
            $articulo = trim((string) ($peticion['articulo'] ?? ''));
            if (mb_strlen($articulo) < 2 || mb_strlen($articulo) > 160) {
                respuestaJson(['error' => 'Escribe qué comprar (2–160 caracteres).'], 422);
            }
            $cantidad = $peticion['cantidad'] ?? 1;
            $cantidad = (is_numeric($cantidad) && (float) $cantidad > 0 && (float) $cantidad <= 100000) ? (float) $cantidad : 1.0;
            $unidad = mb_substr(trim((string) ($peticion['unidad'] ?? '')), 0, 40);
            $item = agregarItemCompra($articulo, $cantidad, $unidad);
            respuestaJson(['ok' => true, 'item' => $item]);

        // ---- Lista de compras: palomear / despalomear ----
        case 'compra_toggle':
            $comprado = alternarCompradoItem((int) ($peticion['id'] ?? 0));
            respuestaJson(['ok' => true, 'comprado' => $comprado]);

        // ---- Lista de compras: eliminar artículo ----
        case 'compra_eliminar':
            eliminarItemCompra((int) ($peticion['id'] ?? 0));
            respuestaJson(['ok' => true]);

        // ---- Lista de compras: generar desde el plan de la semana ----
        case 'compra_generar':
            $lunes = (string) ($peticion['lunes'] ?? date('Y-m-d'));
            $marca = strtotime($lunes) ?: time();
            // Normaliza SIEMPRE al lunes de esa semana (no dependemos del cliente).
            $lunes = date('Y-m-d', strtotime('monday this week', $marca));
            $cuantos = generarListaDesdePlan($lunes);
            respuestaJson(['ok' => true, 'message' => $cuantos > 0
                ? "Se agregaron $cuantos artículos del plan de la semana."
                : 'No hay ingredientes por comprar del plan de esta semana (revisa que los platillos planeados tengan receta).']);

        // ---- Catálogo: agregar ingrediente global (verificado por IA) ----
        case 'ingrediente_agregar':
            $nombre = trim((string) ($peticion['nombre'] ?? ''));
            if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
                respuestaJson(['error' => 'Escribe el ingrediente (2–120 caracteres).'], 422);
            }
            // Si ya está en el catálogo, no gastamos IA.
            $existente = ingredienteEnCatalogo($nombre);
            if ($existente !== null) {
                respuestaJson(['ok' => true, 'item' => $existente, 'message' => '"' . $existente['nombre'] . '" ya estaba en el catálogo.']);
            }
            if (!iaActiva()) {
                respuestaJson(['error' => 'Para agregar ingredientes nuevos se necesita la IA (configura OPENAI_API_KEY).'], 503);
            }
            $verificado = verificarIngredienteIA($nombre);
            if (empty($verificado['valido'])) {
                respuestaJson(['error' => ($verificado['motivo'] ?? '') !== '' ? $verificado['motivo'] : 'Eso no parece un ingrediente de cocina.'], 422);
            }
            $limpio = trim((string) ($verificado['nombre'] ?? '')) ?: $nombre;
            $item = agregarIngredienteCatalogo(
                mb_substr($limpio, 0, 120),
                mb_substr(trim((string) ($verificado['categoria'] ?? 'otros')), 0, 60),
                mb_substr(trim((string) ($verificado['unidad_sugerida'] ?? '')), 0, 40)
            );
            $correccion = trim((string) ($verificado['correccion'] ?? ''));
            respuestaJson(['ok' => true, 'item' => $item, 'message' => $correccion !== ''
                ? 'Agregado como "' . $item['nombre'] . '" (' . $correccion . ').'
                : 'Se agregó "' . $item['nombre'] . '" al catálogo.']);

        // ---- Despensa: fijar "tengo" o "hace_falta" de un ingrediente ----
        case 'despensa_set':
            $nombre = trim((string) ($peticion['nombre'] ?? ''));
            if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 160) {
                respuestaJson(['error' => 'Ingrediente inválido.'], 422);
            }
            $campo = ($peticion['campo'] ?? '') === 'hace_falta' ? 'hace_falta' : 'tengo';
            $estado = fijarEstadoDespensa(mb_substr($nombre, 0, 160), $campo, !empty($peticion['valor']));
            respuestaJson(['ok' => true] + $estado);

        // ---- Despensa: pasar lo que hace falta a la lista de compras ----
        case 'despensa_a_compras':
            $cuantos = faltantesDespensaALista();
            respuestaJson(['ok' => true, 'message' => $cuantos > 0
                ? "Se pasaron $cuantos ingredientes a la lista de compras."
                : 'No hay ingredientes marcados como que hacen falta.']);

        // ---- IA: recomendar recetas con lo que hay en casa ----
        case 'ia_despensa':
            if (!iaActiva()) {
                respuestaJson(['error' => 'IA desactivada: configura OPENAI_API_KEY en .env.'], 503);
            }
            $enCasa = nombresDespensa();
            if (count($enCasa) < 2) {
                respuestaJson(['error' => 'Agrega al menos 2 ingredientes a tu despensa para que la IA te recomiende.'], 422);
            }
            $recomendaciones = recomendarPorDespensaIA($enCasa, preferenciasFamilia(), contarMiembrosFamilia());
            respuestaJson(['ok' => true, 'recomendaciones' => $recomendaciones]);

        // ---- IA: crear platillo a partir de un nombre (desde una recomendación) ----
        case 'ia_crear_platillo':
            if (!iaActiva()) {
                respuestaJson(['error' => 'IA desactivada: configura OPENAI_API_KEY en .env.'], 503);
            }
            $nombre = trim((string) ($peticion['nombre'] ?? ''));
            if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 160) {
                respuestaJson(['error' => 'Nombre de platillo inválido.'], 422);
            }
            $tono = in_array($peticion['tono'] ?? '', TONOS_PLATILLO, true) ? $peticion['tono'] : 'salado';
            $tipoComida = in_array($peticion['tipo_comida'] ?? '', TIPOS_COMIDA, true) ? $peticion['tipo_comida'] : 'comida';
            $porciones = contarMiembrosFamilia();

            $cruda = generarRecetaIA($nombre, [
                'tono' => $tono,
                'tipo_comida' => $tipoComida,
                'porciones' => $porciones,
                'preferencias' => preferenciasFamilia(),
            ]);
            $errores = [];
            $limpia = validarReceta($cruda, $errores);
            if ($limpia === null) {
                respuestaJson(['error' => 'La IA no devolvió una receta válida. Intenta de nuevo.', 'details' => $errores], 422);
            }
            $idPlatillo = crearPlatillo([
                'nombre' => mb_substr($nombre, 0, 160),
                'descripcion' => '',
                'tono' => $tono,
                'tipo_comida' => $tipoComida,
                'cocina' => '',
                'dificultad' => 2,
                'tiempo_preparacion' => $limpia['tiempo_preparacion'],
                'porciones' => $limpia['porciones'] ?? $porciones,
                'imagen_url' => '',
                'generado_ia' => 1,
            ]);
            guardarRecetaDePlatillo($idPlatillo, $limpia, esIa: true);
            registroLog('ia', 'Platillo creado desde recomendación de despensa: ' . $idPlatillo);
            respuestaJson(['ok' => true, 'id_platillo' => $idPlatillo, 'message' => 'Platillo creado con IA para ' . $porciones . ' porciones.']);

        default:
            respuestaJson(['error' => 'Acción desconocida.'], 400);
    }
} catch (RuntimeException $excepcion) {
    respuestaJson(['error' => $excepcion->getMessage()], 502);
} catch (Throwable $excepcion) {
    error_log('[mi_cocina/ajax] ' . $excepcion->getMessage());
    respuestaJson(['error' => 'Error interno.'], 500);
}
