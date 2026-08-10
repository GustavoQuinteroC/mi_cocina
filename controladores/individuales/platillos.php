<?php

declare(strict_types=1);

/**
 * Platillos: catálogo de la familia con sus metadatos de tarjeta,
 * favoritos y validación del formulario.
 * Todas las consultas van con filtroFamilia(): un id de otra familia
 * simplemente no existe.
 */

const TONOS_PLATILLO = ['dulce', 'salado', 'agridulce', 'picante', 'otro'];
const TIPOS_COMIDA = ['desayuno', 'comida', 'cena', 'snack'];

/**
 * Listado con metadatos para las tarjetas: si tiene receta, si es favorito
 * del usuario actual, última vez cocinado y si se hizo hoy.
 *
 * @param array{tono?:string,tipo_comida?:string,q?:string} $filtros
 */
function listarPlatillos(array $filtros = []): array
{
    global $database;

    $where = filtroFamilia();
    if (!empty($filtros['tono']) && in_array($filtros['tono'], TONOS_PLATILLO, true)) {
        $where['tono'] = $filtros['tono'];
    }
    if (!empty($filtros['tipo_comida']) && in_array($filtros['tipo_comida'], TIPOS_COMIDA, true)) {
        $where['tipo_comida'] = $filtros['tipo_comida'];
    }
    if (!empty($filtros['q'])) {
        $where['nombre[~]'] = $filtros['q']; // LIKE %q% parametrizado por Medoo
    }
    $where['ORDER'] = ['nombre' => 'ASC'];

    return agregarMetaPlatillos($database->select('platillos', '*', $where));
}

/** Un platillo (de la familia en sesión) o null. */
function obtenerPlatillo(int $id): ?array
{
    global $database;
    $fila = $database->get('platillos', '*', filtroFamilia(['id' => $id]));
    return $fila ?: null;
}

/** Un platillo con sus metadatos de tarjeta. */
function obtenerPlatilloConMeta(int $id): ?array
{
    $platillo = obtenerPlatillo($id);
    if ($platillo === null) {
        return null;
    }
    return agregarMetaPlatillos([$platillo])[0];
}

function crearPlatillo(array $datos): int
{
    global $database;
    $database->insert('platillos', [
        'id_familia' => idFamiliaActual(),
        'nombre' => $datos['nombre'],
        'descripcion' => $datos['descripcion'] ?: null,
        'tono' => $datos['tono'],
        'tipo_comida' => $datos['tipo_comida'],
        'cocina' => $datos['cocina'] ?: '',
        'dificultad' => $datos['dificultad'],
        'tiempo_preparacion' => $datos['tiempo_preparacion'] ?: null,
        'porciones' => $datos['porciones'],
        'imagen_url' => $datos['imagen_url'] ?: '',
        'generado_ia' => $datos['generado_ia'] ?? 0,
        'id_creado_por' => idUsuarioActual(),
    ]);
    return (int) $database->id();
}

function actualizarPlatillo(int $id, array $datos): void
{
    global $database;
    $database->update('platillos', [
        'nombre' => $datos['nombre'],
        'descripcion' => $datos['descripcion'] ?: null,
        'tono' => $datos['tono'],
        'tipo_comida' => $datos['tipo_comida'],
        'cocina' => $datos['cocina'] ?: '',
        'dificultad' => $datos['dificultad'],
        'tiempo_preparacion' => $datos['tiempo_preparacion'] ?: null,
        'porciones' => $datos['porciones'],
        'imagen_url' => $datos['imagen_url'] ?: '',
    ], filtroFamilia(['id' => $id]));
}

/** Elimina el platillo y todo lo que cuelga de él (sin llaves foráneas en BD). */
function eliminarPlatillo(int $id): bool
{
    global $database;
    if (!$database->has('platillos', filtroFamilia(['id' => $id]))) {
        return false;
    }
    $database->delete('recetas', ['id_platillo' => $id]);
    $database->delete('favoritos', ['id_platillo' => $id]);
    $database->delete('historial_cocina', ['id_platillo' => $id]);
    $database->delete('plan_semanal', ['id_platillo' => $id]);
    $database->delete('platillos_etiquetas', ['id_platillo' => $id]);
    $database->delete('votos', ['id_platillo' => $id]);
    $resultado = $database->delete('platillos', filtroFamilia(['id' => $id]));
    return $resultado->rowCount() > 0;
}

/** ¿El usuario actual puede editar este platillo? (admin: todos; miembro: los suyos) */
function puedeEditarPlatillo(array $platillo): bool
{
    return usuarioEsAdmin() || (int) ($platillo['id_creado_por'] ?? 0) === idUsuarioActual();
}

/**
 * Catálogo global de ingredientes (cocina tapatía + despensa latina).
 * Alimenta el autocompletado al capturar recetas. No lleva id_familia:
 * es una lista de referencia compartida por todas las familias.
 */
function catalogoIngredientes(): array
{
    global $database;
    return $database->select(
        'catalogo_ingredientes',
        ['nombre', 'categoria', 'unidad_sugerida'],
        ['ORDER' => ['categoria' => 'ASC', 'nombre' => 'ASC']]
    );
}

/** Busca un ingrediente en el catálogo (sin distinguir mayúsculas). */
function ingredienteEnCatalogo(string $nombre): ?array
{
    global $database;
    // La colación utf8mb4_unicode_ci ya ignora mayúsculas/acentos al comparar.
    $fila = $database->get('catalogo_ingredientes', ['nombre', 'categoria', 'unidad_sugerida'], ['nombre' => trim($nombre)]);
    return $fila ?: null;
}

/**
 * Agrega un ingrediente al catálogo global (origen = usuario). Los datos ya
 * vienen verificados/corregidos por la IA. Si ya existía, devuelve el existente.
 */
function agregarIngredienteCatalogo(string $nombre, string $categoria, string $unidad): array
{
    global $database;
    if (!$database->has('catalogo_ingredientes', ['nombre' => $nombre])) {
        $database->insert('catalogo_ingredientes', [
            'nombre' => $nombre,
            'categoria' => $categoria ?: 'otros',
            'unidad_sugerida' => $unidad,
            'origen' => 'usuario',
        ]);
    }
    return $database->get('catalogo_ingredientes', ['nombre', 'categoria', 'unidad_sugerida'], ['nombre' => $nombre]);
}

/** Marca/desmarca favorito. Devuelve el estado final (true = es favorito). */
function alternarFavorito(int $idPlatillo): bool
{
    global $database;
    $platillo = $database->get('platillos', ['id', 'id_familia'], ['id' => $idPlatillo]);
    verificarFamiliaDeFila($platillo ?: null);

    $where = ['id_usuario' => idUsuarioActual(), 'id_platillo' => $idPlatillo];
    if ($database->has('favoritos', $where)) {
        $database->delete('favoritos', $where);
        return false;
    }
    try {
        $database->insert('favoritos', $where);
    } catch (PDOException) {
        // Doble clic simultáneo: choca uq_usuario_platillo; ya es favorito.
        return true;
    }
    return true;
}

/**
 * Valida y normaliza el formulario de platillo.
 * Devuelve los datos limpios, o null dejando los errores en $errores.
 *
 * @param string[] $errores
 */
function validarFormularioPlatillo(array &$errores): ?array
{
    $nombre = mb_substr(post('nombre'), 0, 160);
    $tono = post('tono');
    $tipoComida = post('tipo_comida');
    $dificultad = (int) post('dificultad', '2');
    $tiempo = post('tiempo_preparacion');
    $porciones = (int) post('porciones', '4');
    $imagenUrl = post('imagen_url');

    if (mb_strlen($nombre) < 2) {
        $errores[] = 'El nombre del platillo es obligatorio (mínimo 2 caracteres).';
    }
    if (!in_array($tono, TONOS_PLATILLO, true)) {
        $errores[] = 'Tono inválido.';
    }
    if (!in_array($tipoComida, TIPOS_COMIDA, true)) {
        $errores[] = 'Tipo de comida inválido.';
    }
    if ($dificultad < 1 || $dificultad > 5) {
        $errores[] = 'La dificultad va de 1 a 5.';
    }
    if ($tiempo !== '' && (!ctype_digit($tiempo) || (int) $tiempo < 1 || (int) $tiempo > 1440)) {
        $errores[] = 'El tiempo de preparación debe ser un número de minutos (1–1440).';
    }
    if ($porciones < 1 || $porciones > 50) {
        $errores[] = 'Las porciones van de 1 a 50.';
    }
    if ($imagenUrl !== '' && (!filter_var($imagenUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $imagenUrl))) {
        $errores[] = 'La URL de la imagen no es válida.';
    }

    if ($errores !== []) {
        return null;
    }

    return [
        'nombre' => $nombre,
        'descripcion' => mb_substr(post('descripcion'), 0, 2000),
        'tono' => $tono,
        'tipo_comida' => $tipoComida,
        'cocina' => mb_substr(post('cocina'), 0, 80),
        'dificultad' => $dificultad,
        'tiempo_preparacion' => $tiempo === '' ? null : (int) $tiempo,
        'porciones' => $porciones,
        'imagen_url' => $imagenUrl,
    ];
}

/**
 * Enriquece una lista de platillos (ya con filtro de familia) con:
 * tiene_receta, receta_verificada, es_favorito, ultima_cocinada,
 * cocinado_hoy y veces_cocinado.
 */
function agregarMetaPlatillos(array $platillos): array
{
    global $database;

    if ($platillos === []) {
        return [];
    }
    $ids = array_map(fn($p) => (int) $p['id'], $platillos);

    // Recetas existentes (id_platillo => verificada)
    $recetas = [];
    foreach ($database->select('recetas', ['id_platillo', 'verificada'], ['id_platillo' => $ids]) as $r) {
        $recetas[(int) $r['id_platillo']] = (int) $r['verificada'];
    }

    // Favoritos del usuario actual
    $favoritos = array_map('intval', $database->select('favoritos', 'id_platillo', [
        'id_usuario' => idUsuarioActual(),
        'id_platillo' => $ids,
    ]));

    // Última cocinada y conteo por platillo (de esta familia)
    $ultimaCocinada = [];
    $vecesCocinado = [];
    $historial = $database->select('historial_cocina', ['id_platillo', 'fecha_cocinado'], filtroFamilia(['id_platillo' => $ids]));
    foreach ($historial as $registro) {
        $idPlatillo = (int) $registro['id_platillo'];
        $vecesCocinado[$idPlatillo] = ($vecesCocinado[$idPlatillo] ?? 0) + 1;
        if (!isset($ultimaCocinada[$idPlatillo]) || $registro['fecha_cocinado'] > $ultimaCocinada[$idPlatillo]) {
            $ultimaCocinada[$idPlatillo] = $registro['fecha_cocinado'];
        }
    }

    $hoy = date('Y-m-d');
    foreach ($platillos as &$platillo) {
        $id = (int) $platillo['id'];
        $platillo['tiene_receta'] = isset($recetas[$id]);
        $platillo['receta_verificada'] = $recetas[$id] ?? 0;
        $platillo['es_favorito'] = in_array($id, $favoritos, true);
        $platillo['ultima_cocinada'] = $ultimaCocinada[$id] ?? null;
        $platillo['veces_cocinado'] = $vecesCocinado[$id] ?? 0;
        $platillo['cocinado_hoy'] = isset($ultimaCocinada[$id]) && str_starts_with($ultimaCocinada[$id], $hoy);
    }
    return $platillos;
}
