<?php

declare(strict_types=1);

/**
 * Integración con OpenAI para generar/ajustar recetas SIN alucinaciones:
 *
 * 1. Grounding: si OPENAI_USE_WEB_SEARCH=true usa la Responses API con la
 *    herramienta web_search, de modo que la receta se base en fuentes
 *    reales y devuelva url_fuente. Si no, exige a la IA limitarse a
 *    recetas tradicionales ampliamente documentadas.
 * 2. Salida estructurada: SOLO JSON con el shape exacto de `recetas`.
 * 3. Todo lo que devuelven estas funciones pasa después por validarReceta()
 *    antes de persistirse (lo hace controladores/ajax.php).
 * 4. Caché en storage/cache/ia por hash del prompt para no quemar tokens.
 *
 * La API key vive únicamente en .env (OPENAI_API_KEY).
 */

/** ¿Está configurada la IA? */
function iaActiva(): bool
{
    $key = leerEnv('OPENAI_API_KEY');
    return $key !== null && $key !== '';
}

/**
 * Genera la receta completa de un platillo por su nombre.
 *
 * @param array $contexto tono, tipo_comida, porciones, cocina, preferencias[]
 * @return array Receta cruda (a validar con validarReceta()).
 */
function generarRecetaIA(string $nombrePlatillo, array $contexto = []): array
{
    $prompt = "Genera la receta REAL y tradicional del platillo: \"$nombrePlatillo\".\n"
        . bloqueContextoIA($contexto)
        . "Si el platillo no es una preparación real y conocida, responde exactamente {\"error\":\"desconocido\"}.";

    return pedirRecetaJsonIA($prompt);
}

/**
 * Sugiere un platillo nuevo según el tono del día y devuelve
 * {platillo: {...}, receta: {...}} para crearlo completo.
 */
function sugerirPlatilloIA(string $tono, ?string $tipoComida, array $preferencias, array $nombresExistentes, int $porciones = 4): array
{
    $evitar = $nombresExistentes === [] ? '' :
        'Evita repetir estos platillos que la familia ya tiene: ' . implode(', ', array_slice($nombresExistentes, 0, 40)) . ".\n";

    $prompt = "Sugiere UN platillo real y conocido de tono \"$tono\""
        . ($tipoComida ? " para \"$tipoComida\"" : '') . ".\n"
        . $evitar
        . "La receta debe rendir EXACTAMENTE $porciones porciones (ajusta todas las cantidades a ese número).\n"
        . bloquePreferenciasIA($preferencias)
        . "Responde SOLO JSON con este shape exacto:\n"
        . '{"platillo":{"nombre":"...","descripcion":"...","tono":"' . $tono . '","tipo_comida":"desayuno|comida|cena|snack","cocina":"...","dificultad":1,"tiempo_preparacion":30,"porciones":' . $porciones . '},'
        . '"receta":' . shapeRecetaIA() . '}' . "\n" . reglaCantidadesIA();

    $datos = llamarIAJson($prompt, cacheable: true);
    if (!isset($datos['platillo'], $datos['receta']) || !is_array($datos['platillo']) || !is_array($datos['receta'])) {
        descartarCacheIA(); // respuesta malformada: no la reutilices en el reintento
        throw new RuntimeException('La IA no devolvió el formato esperado (platillo + receta).');
    }
    return $datos;
}

/**
 * Ajusta una receta existente según una instrucción
 * ("hazla vegetariana", "para 6 personas", "más picante"...).
 */
function ajustarRecetaIA(string $nombrePlatillo, array $receta, string $instruccion): array
{
    $actual = json_encode([
        'ingredientes' => $receta['ingredientes'],
        'pasos' => $receta['pasos'],
        'porciones' => $receta['porciones'] ?? null,
        'tiempo_preparacion' => $receta['tiempo_preparacion'] ?? null,
    ], JSON_UNESCAPED_UNICODE);

    $prompt = "Esta es la receta actual de \"$nombrePlatillo\":\n$actual\n\n"
        . "Ajústala según esta instrucción: \"$instruccion\".\n"
        . "Mantén la receta realista y coherente (cantidades y pasos válidos). "
        . "Si la instrucción cambia porciones, escala TODOS los ingredientes proporcionalmente.";

    return pedirRecetaJsonIA($prompt, cacheable: false);
}

/**
 * Verifica y corrige un ingrediente que un usuario quiere agregar al catálogo
 * global. La IA revisa que sea un ingrediente real, corrige ortografía/acentos,
 * lo clasifica y sugiere una unidad. Devuelve:
 *   {valido, nombre, categoria, unidad_sugerida, correccion, motivo}
 */
function verificarIngredienteIA(string $nombre): array
{
    $categorias = 'chiles, verduras, frutas, hierbas y especias, carnes, aves, mariscos, '
        . 'lácteos, abarrotes, leguminosas, panadería, semillas, endulzantes, bebidas, '
        . 'salsas, internacional, tapatío, otros';

    $prompt = "Un usuario quiere agregar el ingrediente de cocina: \"$nombre\".\n"
        . "1) Decide si es un ingrediente de cocina real y comestible.\n"
        . "2) Corrige ortografía, acentos y usa mayúscula inicial (ej. 'jitomate' -> 'Jitomate').\n"
        . "3) Clasifícalo en UNA de estas categorías exactas: $categorias.\n"
        . "4) Sugiere una unidad común: g, ml, pza, tza, cda, cdta, diente, manojo, rama, hoja, pizca, lata o rebanada.\n"
        . "Si NO es un ingrediente de cocina (grosería, texto sin sentido, objeto no comestible), márcalo inválido.\n"
        . "Responde SOLO JSON con este shape exacto:\n"
        . '{"valido":true,"nombre":"Nombre corregido","categoria":"una de la lista","unidad_sugerida":"g","correccion":"nota breve si corregiste algo o cadena vacía","motivo":"si es inválido, explica por qué; si no, cadena vacía"}';

    return llamarIAJson($prompt, cacheable: true);
}

/**
 * Recomienda platillos reales que la familia puede cocinar con lo que hay en
 * casa. Prioriza los que se hacen solo con esos ingredientes e incluye los que
 * necesitan 1 o 2 más, indicando cuáles faltan. Escala a las porciones dadas.
 *
 * @return array<int,array{nombre:string,descripcion:string,tono:string,tipo_comida:string,faltantes:array}>
 */
function recomendarPorDespensaIA(array $ingredientesEnCasa, array $preferencias, int $porciones): array
{
    $lista = implode(', ', array_slice($ingredientesEnCasa, 0, 120));

    $prompt = "En casa tengo estos ingredientes: $lista.\n"
        . "Da por hecho que también tengo básicos como sal, agua, aceite y pimienta.\n"
        . bloquePreferenciasIA($preferencias)
        . "Recomiéndame entre 3 y 6 platillos REALES y conocidos que pueda cocinar. "
        . "Prioriza los que se hacen SOLO con lo que tengo. Incluye también platillos a los que "
        . "solo les falten 1 o 2 ingredientes, poniéndolos en 'faltantes'. Cada platillo para $porciones porciones.\n"
        . 'Responde SOLO JSON con este shape exacto: '
        . '{"recomendaciones":[{"nombre":"...","descripcion":"breve","tono":"dulce|salado|agridulce|picante|otro",'
        . '"tipo_comida":"desayuno|comida|cena|snack","faltantes":["ingrediente que falta"]}]}';

    $datos = llamarIAJson($prompt, cacheable: false);
    if (!isset($datos['recomendaciones']) || !is_array($datos['recomendaciones'])) {
        throw new RuntimeException('La IA no devolvió recomendaciones válidas.');
    }
    return $datos['recomendaciones'];
}

/**
 * Antojo libre: el usuario pide con sus propias palabras ("dame la receta de
 * birria", "se me antojan camarones cremosos", "quiero algo italiano con lo
 * que tengo") y la IA decide entre DOS respuestas:
 *
 *  - {tipo:"receta", platillo:{...}, receta:{...}}   → pidió un platillo
 *    concreto: se devuelve su versión MÁS tradicional y popular.
 *  - {tipo:"recomendaciones", recomendaciones:[...]} → petición abierta:
 *    entre 3 y 6 platillos reales que encajen con lo pedido.
 *
 * La despensa se pasa como contexto y solo se usa si la petición la menciona.
 */
function pedirAntojoIA(string $peticion, array $ingredientesEnCasa, array $preferencias, int $porciones): array
{
    $despensa = $ingredientesEnCasa === [] ? '(vacía)'
        : implode(', ', array_slice($ingredientesEnCasa, 0, 120));

    $prompt = "Un usuario te pide algo de cocina con sus propias palabras:\n\"$peticion\"\n\n"
        . "Contexto:\n"
        . "- En su despensa tienen: $despensa. SOLO usa esta lista si la petición se refiere a "
        . "\"lo que tengo\", \"lo que hay en casa\" o pide aprovechar sus ingredientes; si no, ignórala.\n"
        . "- Toda receta debe rendir EXACTAMENTE $porciones porciones (ajusta las cantidades).\n"
        . bloquePreferenciasIA($preferencias) . "\n\n"
        . "Decide UNA de estas respuestas:\n"
        . "1) Si pide un platillo CONCRETO y reconocible (ej. \"birria\", \"dame la receta de caldo de res\"): "
        . "responde con la versión MÁS tradicional, popular y ampliamente documentada de ese platillo.\n"
        . '   JSON: {"tipo":"receta","platillo":{"nombre":"...","descripcion":"breve","tono":"dulce|salado|agridulce|picante|otro","tipo_comida":"desayuno|comida|cena|snack","cocina":"...","dificultad":1},"receta":' . shapeRecetaIA() . "}\n"
        . "2) Si la petición es ABIERTA (un antojo, un tipo de cocina, ingredientes, \"algo cremoso con camarones\"): "
        . "recomienda entre 3 y 6 platillos REALES y conocidos que encajen con lo pedido, "
        . "explicando en la descripción por qué encajan.\n"
        . '   JSON: {"tipo":"recomendaciones","recomendaciones":[{"nombre":"...","descripcion":"breve","tono":"dulce|salado|agridulce|picante|otro","tipo_comida":"desayuno|comida|cena|snack","faltantes":["solo si usaste la despensa: qué falta"]}]}' . "\n"
        . '3) Si la petición NO tiene que ver con cocina o comida, responde exactamente {"error":"fuera_de_tema"}.' . "\n\n"
        . 'IMPORTANTE: tu respuesta COMPLETA debe ser UN solo objeto JSON válido, sin texto antes ni después, '
        . 'sin markdown y sin comas colgantes. No expliques nada fuera del JSON. ' . reglaCantidadesIA();

    $datos = llamarIAJson($prompt, cacheable: true);

    if (($datos['error'] ?? '') === 'fuera_de_tema') {
        throw new RuntimeException('Eso no parece un antojo de cocina. Pídeme un platillo o describe qué se te antoja.');
    }
    $tipo = $datos['tipo'] ?? '';
    $formaValida = ($tipo === 'receta' && isset($datos['platillo'], $datos['receta']) && is_array($datos['platillo']) && is_array($datos['receta']))
        || ($tipo === 'recomendaciones' && isset($datos['recomendaciones']) && is_array($datos['recomendaciones']) && $datos['recomendaciones'] !== []);
    if (!$formaValida) {
        descartarCacheIA(); // respuesta malformada: no la reutilices en el reintento
        throw new RuntimeException('La IA no devolvió el formato esperado. Intenta de nuevo.');
    }
    return $datos;
}

/**
 * Transcribe un audio de dictado (micrófono) a texto en español con la API
 * de OpenAI. El modelo se configura con OPENAI_MODEL_TRANSCRIPCION
 * (por defecto gpt-4o-mini-transcribe; también sirve whisper-1).
 */
function transcribirAudioIA(string $rutaArchivo, string $nombreArchivo, string $mime): string
{
    $modelo = leerEnv('OPENAI_MODEL_TRANSCRIPCION', 'gpt-4o-mini-transcribe') ?: 'gpt-4o-mini-transcribe';

    $respuesta = peticionOpenAIMultipart('https://api.openai.com/v1/audio/transcriptions', [
        'model' => $modelo,
        'language' => 'es',
        // Pista de contexto: mejora el reconocimiento de platillos e ingredientes.
        'prompt' => 'Dictado de cocina en español de México: antojos, platillos e ingredientes '
            . '(birria, pozole, camarones, jitomate, cilantro).',
    ], $rutaArchivo, $nombreArchivo, $mime);

    $texto = trim((string) ($respuesta['text'] ?? ''));
    if ($texto === '') {
        throw new RuntimeException('No se entendió el audio. Intenta dictar de nuevo.');
    }
    return $texto;
}

// ---------------------------------------------------------------------------
// Internos
// ---------------------------------------------------------------------------

/** Pide una receta con el shape de `recetas` y devuelve el arreglo crudo. */
function pedirRecetaJsonIA(string $prompt, bool $cacheable = true): array
{
    $prompt .= "\nResponde SOLO JSON con este shape exacto (sin markdown, sin texto extra):\n" . shapeRecetaIA()
        . "\n" . reglaCantidadesIA();

    $datos = llamarIAJson($prompt, $cacheable);
    if (isset($datos['error'])) {
        throw new RuntimeException('La IA no reconoce ese platillo como una receta real. Revisa el nombre.');
    }
    return $datos;
}

function shapeRecetaIA(): string
{
    return '{"ingredientes":[{"nombre":"string","cantidad":1.5,"unidad":"g|ml|tza|cda|cdta|pza|..."}],'
        . '"pasos":["Paso 1...","Paso 2..."],"porciones":4,"tiempo_preparacion":30,'
        . '"notas":"string opcional o null","url_fuente":"URL de la fuente real o null"}';
}

/**
 * Regla anti-JSON-roto: los modelos tienden a escribir "cantidad":al gusto
 * (sin comillas), lo que invalida TODO el JSON. Se anexa a los prompts de receta.
 */
function reglaCantidadesIA(): string
{
    return 'REGLA ESTRICTA: en los ingredientes, "cantidad" SIEMPRE es un número mayor a 0, jamás texto '
        . '(nada de "al gusto" como cantidad). Para un ingrediente al gusto usa cantidad 1 y unidad "al gusto".';
}

function bloqueContextoIA(array $contexto): string
{
    $lineas = [];
    if (!empty($contexto['tono'])) {
        $lineas[] = 'Tono: ' . $contexto['tono'] . '.';
    }
    if (!empty($contexto['tipo_comida'])) {
        $lineas[] = 'Tipo de comida: ' . $contexto['tipo_comida'] . '.';
    }
    if (!empty($contexto['cocina'])) {
        $lineas[] = 'Cocina: ' . $contexto['cocina'] . '.';
    }
    if (!empty($contexto['porciones'])) {
        $lineas[] = 'Prepara la receta para EXACTAMENTE ' . (int) $contexto['porciones']
            . ' porciones y ajusta TODAS las cantidades a ese número de personas.';
    }
    $lineas[] = bloquePreferenciasIA($contexto['preferencias'] ?? []);
    return implode("\n", array_filter($lineas)) . "\n";
}

/** Alergias/dietas de la familia: la IA DEBE respetarlas. */
function bloquePreferenciasIA(array $preferencias): string
{
    if ($preferencias === []) {
        return '';
    }
    $partes = [];
    foreach ($preferencias as $preferencia) {
        $partes[] = $preferencia['tipo'] . ': ' . $preferencia['valor'];
    }
    return 'Restricciones de la familia que DEBES respetar (sobre todo alergias): ' . implode('; ', $partes) . '.';
}

/**
 * Llama a OpenAI y devuelve el JSON decodificado.
 * Con caché por hash del prompt (modelo incluido) en storage/cache/ia.
 */
function llamarIAJson(string $prompt, bool $cacheable): array
{
    if (!iaActiva()) {
        throw new RuntimeException('Funciones de IA desactivadas: falta OPENAI_API_KEY en .env.');
    }

    $modelo = leerEnv('OPENAI_MODEL', 'gpt-4o-mini');
    $conBusqueda = leerEnvBool('OPENAI_USE_WEB_SEARCH', true);
    $directorioCache = dirname(__DIR__, 2) . '/storage/cache/ia';

    $llaveCache = hash('sha256', $modelo . '|' . (int) $conBusqueda . '|' . $prompt);
    $archivoCache = $directorioCache . '/' . $llaveCache . '.json';

    // Se recuerda la última llave para poder descartarla si la receta resulta
    // inválida más adelante (ver descartarCacheIA), y no dejar "atascado" un platillo.
    $GLOBALS['_ultima_llave_cache_ia'] = $cacheable ? $llaveCache : '';

    if ($cacheable && is_file($archivoCache)) {
        $cacheado = json_decode((string) file_get_contents($archivoCache), true);
        if (is_array($cacheado)) {
            return $cacheado;
        }
    }

    $sistema = 'Eres un chef experto y riguroso. Solo propones recetas REALES, probadas y documentadas; '
        . 'jamás inventas platillos, ingredientes ni cantidades improbables. '
        . ($conBusqueda
            ? 'Usa la búsqueda web para basarte en una fuente real y devuelve su URL en url_fuente. '
            : 'Limítate a recetas tradicionales ampliamente conocidas; si dudas de la existencia del platillo devuelve {"error":"desconocido"}. ')
        . 'Tu respuesta es EXCLUSIVAMENTE un objeto JSON válido: sin markdown, sin ```, sin preámbulos ni texto extra.';

    $texto = $conBusqueda
        ? llamarResponsesApi($modelo, $sistema, $prompt)
        : llamarChatCompletions($modelo, $sistema, $prompt);

    try {
        $datos = extraerJsonIA($texto);
    } catch (RuntimeException) {
        // El modelo a veces contesta con prosa o JSON roto: se registra la
        // respuesta cruda para diagnóstico y se reintenta UNA vez.
        registroLog('ia_error', 'Respuesta no-JSON del modelo (reintentando): ' . mb_substr($texto, 0, 600));
        $texto = $conBusqueda
            ? llamarResponsesApi($modelo, $sistema, $prompt)
            : llamarChatCompletions($modelo, $sistema, $prompt);
        try {
            $datos = extraerJsonIA($texto);
        } catch (RuntimeException $excepcion) {
            registroLog('ia_error', 'Respuesta no-JSON también en el reintento: ' . mb_substr($texto, 0, 600));
            throw $excepcion;
        }
    }

    // NO se cachean respuestas de error (p. ej. {"error":"desconocido"}): si no,
    // un platillo real que falló una vez quedaría atascado devolviendo el error
    // para siempre y "Intenta de nuevo" nunca funcionaría.
    if ($cacheable && !isset($datos['error'])) {
        @mkdir($directorioCache, 0775, true);
        @file_put_contents($archivoCache, json_encode($datos, JSON_UNESCAPED_UNICODE));
    }
    return $datos;
}

/**
 * Borra de la caché la última respuesta de IA. Se llama cuando una receta de IA
 * ya cacheada NO pasa validarReceta(), para que el siguiente intento vuelva a
 * consultar al modelo en lugar de releer una respuesta inválida.
 */
function descartarCacheIA(): void
{
    $llave = $GLOBALS['_ultima_llave_cache_ia'] ?? '';
    if ($llave === '') {
        return;
    }
    $archivo = dirname(__DIR__, 2) . '/storage/cache/ia/' . $llave . '.json';
    if (is_file($archivo)) {
        @unlink($archivo);
    }
    $GLOBALS['_ultima_llave_cache_ia'] = '';
}

/** Responses API con web_search (grounding en fuentes reales). */
function llamarResponsesApi(string $modelo, string $sistema, string $prompt): string
{
    // El nombre de la herramienta cambió entre versiones de la API
    // (web_search / web_search_preview). Configurable para no tocar código si
    // OpenAI lo renombra o el modelo requiere una variante.
    $herramienta = leerEnv('OPENAI_WEB_SEARCH_TOOL', 'web_search') ?: 'web_search';
    $respuesta = peticionOpenAI('https://api.openai.com/v1/responses', [
        'model' => $modelo,
        'instructions' => $sistema,
        'input' => $prompt,
        'tools' => [['type' => $herramienta]],
    ]);

    // La Responses API expone el texto final en output[] -> content[] -> text.
    if (isset($respuesta['output_text']) && is_string($respuesta['output_text'])) {
        return $respuesta['output_text'];
    }
    $texto = '';
    foreach ($respuesta['output'] ?? [] as $item) {
        if (($item['type'] ?? '') === 'message') {
            foreach ($item['content'] ?? [] as $contenido) {
                if (($contenido['type'] ?? '') === 'output_text') {
                    $texto .= $contenido['text'] ?? '';
                }
            }
        }
    }
    if ($texto === '') {
        throw new RuntimeException('OpenAI no devolvió texto (Responses API).');
    }
    return $texto;
}

/** Chat Completions con modo JSON (sin búsqueda web). */
function llamarChatCompletions(string $modelo, string $sistema, string $prompt): string
{
    $respuesta = peticionOpenAI('https://api.openai.com/v1/chat/completions', [
        'model' => $modelo,
        'temperature' => 0.2,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $sistema],
            ['role' => 'user', 'content' => $prompt],
        ],
    ]);
    $texto = $respuesta['choices'][0]['message']['content'] ?? '';
    if (!is_string($texto) || $texto === '') {
        throw new RuntimeException('OpenAI no devolvió contenido (Chat Completions).');
    }
    return $texto;
}

/** POST JSON autenticado. Usa Guzzle si está instalado; si no, streams nativos. */
function peticionOpenAI(string $url, array $carga): array
{
    $apiKey = leerEnv('OPENAI_API_KEY');
    $cuerpo = json_encode($carga, JSON_UNESCAPED_UNICODE);
    // Timeout acotado: 90 s bloqueaba un worker PHP-FPM demasiado tiempo y unas
    // pocas llamadas concurrentes podían agotar los workers. Configurable.
    $timeout = max(10, min(60, (int) leerEnv('OPENAI_TIMEOUT', '45')));

    if (class_exists(GuzzleHttp\Client::class)) {
        $cliente = new GuzzleHttp\Client(['timeout' => $timeout]);
        try {
            $res = $cliente->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => $cuerpo,
            ]);
            $crudo = (string) $res->getBody();
        } catch (GuzzleHttp\Exception\RequestException $excepcion) {
            $detalle = $excepcion->hasResponse() ? (string) $excepcion->getResponse()->getBody() : $excepcion->getMessage();
            throw new RuntimeException('Error de OpenAI: ' . mensajeErrorOpenAI($detalle));
        }
    } else {
        $contexto = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer $apiKey\r\nContent-Type: application/json\r\n",
            'content' => $cuerpo,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $crudo = file_get_contents($url, false, $contexto);
        if ($crudo === false) {
            throw new RuntimeException('No se pudo contactar a OpenAI.');
        }
        $estado = 0;
        foreach ($http_response_header ?? [] as $cabecera) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $cabecera, $m)) {
                $estado = (int) $m[1];
            }
        }
        if ($estado >= 400) {
            throw new RuntimeException('Error de OpenAI: ' . mensajeErrorOpenAI($crudo));
        }
    }

    $decodificado = json_decode($crudo, true);
    if (!is_array($decodificado)) {
        throw new RuntimeException('Respuesta de OpenAI no es JSON válido.');
    }
    return $decodificado;
}

/**
 * POST multipart autenticado a OpenAI (subida de audio para transcripción).
 * Usa Guzzle si está instalado; si no, arma el multipart con streams nativos.
 */
function peticionOpenAIMultipart(string $url, array $campos, string $rutaArchivo, string $nombreArchivo, string $mime): array
{
    $apiKey = leerEnv('OPENAI_API_KEY');
    $timeout = max(10, min(60, (int) leerEnv('OPENAI_TIMEOUT', '45')));

    if (class_exists(GuzzleHttp\Client::class)) {
        $partes = [];
        foreach ($campos as $nombre => $valor) {
            $partes[] = ['name' => $nombre, 'contents' => (string) $valor];
        }
        $partes[] = [
            'name' => 'file',
            'contents' => fopen($rutaArchivo, 'rb'),
            'filename' => $nombreArchivo,
            'headers' => ['Content-Type' => $mime],
        ];
        $cliente = new GuzzleHttp\Client(['timeout' => $timeout]);
        try {
            $res = $cliente->post($url, [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey],
                'multipart' => $partes,
            ]);
            $crudo = (string) $res->getBody();
        } catch (GuzzleHttp\Exception\RequestException $excepcion) {
            $detalle = $excepcion->hasResponse() ? (string) $excepcion->getResponse()->getBody() : $excepcion->getMessage();
            throw new RuntimeException('Error de OpenAI: ' . mensajeErrorOpenAI($detalle));
        }
    } else {
        $frontera = '----MiCocina' . bin2hex(random_bytes(12));
        $cuerpo = '';
        foreach ($campos as $nombre => $valor) {
            $cuerpo .= "--$frontera\r\nContent-Disposition: form-data; name=\"$nombre\"\r\n\r\n$valor\r\n";
        }
        $cuerpo .= "--$frontera\r\nContent-Disposition: form-data; name=\"file\"; filename=\"$nombreArchivo\"\r\n"
            . "Content-Type: $mime\r\n\r\n" . (string) file_get_contents($rutaArchivo) . "\r\n--$frontera--\r\n";

        $contexto = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer $apiKey\r\nContent-Type: multipart/form-data; boundary=$frontera\r\n",
            'content' => $cuerpo,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $crudo = file_get_contents($url, false, $contexto);
        if ($crudo === false) {
            throw new RuntimeException('No se pudo contactar a OpenAI.');
        }
        $estado = 0;
        foreach ($http_response_header ?? [] as $cabecera) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $cabecera, $m)) {
                $estado = (int) $m[1];
            }
        }
        if ($estado >= 400) {
            throw new RuntimeException('Error de OpenAI: ' . mensajeErrorOpenAI($crudo));
        }
    }

    $decodificado = json_decode($crudo, true);
    if (!is_array($decodificado)) {
        throw new RuntimeException('Respuesta de OpenAI no es JSON válido.');
    }
    return $decodificado;
}

function mensajeErrorOpenAI(string $cuerpoCrudo): string
{
    $decodificado = json_decode($cuerpoCrudo, true);
    return $decodificado['error']['message'] ?? substr($cuerpoCrudo, 0, 300);
}

/**
 * Extrae el objeto JSON de la respuesta de la IA.
 * Tolera vallas ```json ... ```, texto antes/después y citas del web_search:
 * busca el PRIMER '{' y su '}' balanceado respetando las cadenas, de modo
 * que un '}' dentro de una URL o comentario no rompa la extracción.
 */
function extraerJsonIA(string $texto): array
{
    $texto = trim($texto);

    // Quita las vallas de markdown si el modelo las agregó.
    $texto = preg_replace('/^```(?:json)?\s*/i', '', $texto);
    $texto = preg_replace('/\s*```\s*$/', '', $texto);
    $texto = trim($texto);

    // Intento directo: normalmente ya es JSON puro.
    $datos = decodificarJsonTolerante($texto);
    if ($datos !== null) {
        return $datos;
    }

    // Si viene envuelto en prosa, recorta el primer objeto balanceado.
    $inicio = strpos($texto, '{');
    if ($inicio !== false) {
        $nivel = 0;
        $enCadena = false;
        $escape = false;
        $longitud = strlen($texto); // bytes: llaves y comillas son ASCII, seguro con UTF-8
        for ($i = $inicio; $i < $longitud; $i++) {
            $caracter = $texto[$i];
            if ($enCadena) {
                if ($escape) {
                    $escape = false;
                } elseif ($caracter === '\\') {
                    $escape = true;
                } elseif ($caracter === '"') {
                    $enCadena = false;
                }
                continue;
            }
            if ($caracter === '"') {
                $enCadena = true;
            } elseif ($caracter === '{') {
                $nivel++;
            } elseif ($caracter === '}') {
                $nivel--;
                if ($nivel === 0) {
                    $fragmento = substr($texto, $inicio, $i - $inicio + 1);
                    $datos = decodificarJsonTolerante($fragmento);
                    if ($datos !== null) {
                        return $datos;
                    }
                    break;
                }
            }
        }
    }

    throw new RuntimeException('La IA no devolvió un JSON válido. Intenta de nuevo.');
}

/**
 * Decodifica JSON aceptando defectos comunes de los modelos: si el intento
 * estricto falla, repara y reintenta. Repara:
 *  - comas colgantes antes de } o ]
 *  - valores de texto sin comillas tras una clave ("cantidad":al gusto),
 *    el vicio que rompía las recetas con ingredientes "al gusto".
 * Devuelve null si aun así no se pudo decodificar.
 */
function decodificarJsonTolerante(string $texto): ?array
{
    $datos = json_decode($texto, true);
    if (is_array($datos)) {
        return $datos;
    }

    $reparado = preg_replace('/,\s*([}\]])/', '$1', $texto);
    $reparado = preg_replace_callback(
        '/([{,]\s*"\w+"\s*:\s*)(?!true\b|false\b|null\b)([A-Za-zÁÉÍÓÚÜÑáéíóúüñ][^",}\]]*)/u',
        static fn(array $m): string => $m[1] . '"' . trim($m[2]) . '"',
        $reparado
    );

    $datos = json_decode($reparado, true);
    return is_array($datos) ? $datos : null;
}
