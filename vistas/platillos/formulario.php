<?php

declare(strict_types=1);

/**
 * Alta y edición de platillo (?id= para editar).
 * Captura en un mismo paso: datos del platillo + ingredientes (con
 * autocompletado del catálogo) + preparación. El POST se procesa aquí;
 * si algo falla se vuelve a mostrar el formulario con lo ya escrito.
 */

require_once dirname(__DIR__, 2) . '/controladores/globales/funcionesPhp.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/platillos.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/recetas.php';

validarSesion();

$idPlatillo = (int) get('id', '0');
$platillo = null;

if ($idPlatillo > 0) {
    $platillo = obtenerPlatillo($idPlatillo);
    if ($platillo === null || !puedeEditarPlatillo($platillo)) {
        flash('error', 'No puedes editar ese platillo.');
        redirigir('/vistas/platillos/index.php');
    }
}
$esEdicion = $platillo !== null;
$enviado = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$errores = [];

// --- Procesar guardado (platillo + receta) ---
if ($enviado) {
    exigirCsrf();

    $datos = validarFormularioPlatillo($errores);

    // La receta es opcional: solo se valida/guarda si hay ingredientes o pasos.
    $recetaCruda = recetaDesdeFormulario();
    $tieneReceta = $recetaCruda['ingredientes'] !== [] || $recetaCruda['pasos'] !== [];
    $recetaLimpia = null;
    if ($tieneReceta) {
        // Captura manual: se permite receta con solo ingredientes o solo pasos.
        $recetaLimpia = validarReceta($recetaCruda, $errores, exigirCompleta: false);
    }

    if ($errores === []) {
        if ($esEdicion) {
            actualizarPlatillo($idPlatillo, $datos);
        } else {
            $idPlatillo = crearPlatillo($datos);
        }
        if ($tieneReceta) {
            guardarRecetaDePlatillo($idPlatillo, $recetaLimpia, esIa: false);
        }
        limpiarFormulario();
        flash('success', $esEdicion ? 'Platillo actualizado.' : 'Platillo guardado.');
        redirigir('/vistas/platillos/ver.php?id=' . $idPlatillo);
    }
    // Con errores: se cae al render de abajo conservando lo enviado ($_POST).
}

// Valor de un campo escalar: $_POST tras un error, el platillo en edición, o el defecto.
$valor = function (string $campo, string $defecto = '') use ($enviado, $esEdicion, $platillo): string {
    if ($enviado) {
        return e($_POST[$campo] ?? $defecto);
    }
    if ($esEdicion) {
        return e($platillo[$campo] ?? $defecto);
    }
    return e($defecto);
};

$seleccion = function (string $campo, string $defecto) use ($enviado, $esEdicion, $platillo): string {
    if ($enviado) {
        return (string) ($_POST[$campo] ?? $defecto);
    }
    if ($esEdicion) {
        return (string) ($platillo[$campo] ?? $defecto);
    }
    return $defecto;
};

// Datos iniciales de la receta para el editor (Alpine).
if ($enviado) {
    $r = recetaDesdeFormulario();
    $recetaInicial = ['ingredientes' => $r['ingredientes'], 'pasos' => $r['pasos'], 'notas' => $r['notas'] ?? '', 'url_fuente' => $r['url_fuente'] ?? ''];
} elseif ($esEdicion && ($rx = obtenerRecetaDePlatillo($idPlatillo)) !== null) {
    $recetaInicial = ['ingredientes' => $rx['ingredientes'], 'pasos' => $rx['pasos'], 'notas' => $rx['notas'] ?? '', 'url_fuente' => $rx['url_fuente'] ?? ''];
} else {
    $recetaInicial = ['ingredientes' => [], 'pasos' => [], 'notas' => '', 'url_fuente' => ''];
}

$dificultadInicial = (int) ($enviado ? ($_POST['dificultad'] ?? 2) : ($esEdicion ? $platillo['dificultad'] : 2));

encabezadoHtml($esEdicion ? 'Editar platillo' : 'Nuevo platillo');
barraNavegacion('platillos');
mensajesFlash();
include __DIR__ . '/_datalist_ingredientes.php';
?>

<script type="application/json" id="receta-inicial"><?= json_encode($recetaInicial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

<header class="mb-6">
    <a href="<?= $esEdicion ? '/vistas/platillos/ver.php?id=' . $idPlatillo : '/vistas/platillos/index.php' ?>" class="text-sm font-bold text-stone-400 hover:text-barro-600 transition-colors">← Volver</a>
    <h1 class="font-display text-3xl font-bold mt-1"><?= $esEdicion ? 'Editar platillo' : 'Nuevo platillo' ?></h1>
</header>

<?php if ($errores !== []): ?>
<div class="max-w-2xl mb-4 px-4 py-3 rounded-2xl bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300 text-sm font-semibold">
    <p class="mb-1">Revisa lo siguiente:</p>
    <ul class="list-disc list-inside space-y-0.5 font-normal">
        <?php foreach ($errores as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" class="max-w-2xl space-y-6">
    <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">

    <!-- ===== Datos del platillo ===== -->
    <section class="space-y-5 bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-6">
        <h2 class="font-display font-bold text-lg">Datos del platillo</h2>

        <div>
            <label class="block text-sm font-bold mb-1" for="nombre">Nombre *</label>
            <input id="nombre" name="nombre" required minlength="2" maxlength="160" value="<?= $valor('nombre') ?>"
                   class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-barro-400"
                   placeholder="Tortas ahogadas">
        </div>

        <div>
            <label class="block text-sm font-bold mb-1" for="descripcion">Descripción</label>
            <textarea id="descripcion" name="descripcion" rows="2" maxlength="2000"
                      class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-barro-400"
                      placeholder="¿Qué lo hace especial?"><?= $valor('descripcion') ?></textarea>
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-bold mb-1" for="tono">Tono *</label>
                <select id="tono" name="tono" class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 font-semibold">
                    <?php foreach (TONOS_PLATILLO as $t): ?>
                    <option value="<?= e($t) ?>" <?= $seleccion('tono', 'salado') === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-bold mb-1" for="tipo_comida">Tipo de comida *</label>
                <select id="tipo_comida" name="tipo_comida" class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 font-semibold">
                    <?php foreach (TIPOS_COMIDA as $tc): ?>
                    <option value="<?= e($tc) ?>" <?= $seleccion('tipo_comida', 'comida') === $tc ? 'selected' : '' ?>><?= e(ucfirst($tc)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-bold mb-1" for="tiempo_preparacion">Tiempo (min)</label>
                <input id="tiempo_preparacion" name="tiempo_preparacion" type="number" min="1" max="1440" value="<?= $valor('tiempo_preparacion') ?>"
                       class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800" placeholder="45">
            </div>
            <div>
                <label class="block text-sm font-bold mb-1" for="porciones">Porciones</label>
                <input id="porciones" name="porciones" type="number" min="1" max="50" value="<?= $valor('porciones', '4') ?>"
                       class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800">
            </div>
            <div x-data="{ d: <?= $dificultadInicial ?> }">
                <label class="block text-sm font-bold mb-1">Dificultad</label>
                <input type="hidden" name="dificultad" :value="d">
                <div class="flex gap-1 pt-2.5">
                    <template x-for="i in 5">
                        <button type="button" @click="d = i" class="text-xl transition-transform hover:scale-125"
                                :class="i <= d ? 'opacity-100' : 'opacity-25'">🔥</button>
                    </template>
                </div>
            </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-bold mb-1" for="cocina">Tipo de cocina</label>
                <input id="cocina" name="cocina" maxlength="80" value="<?= $valor('cocina') ?>"
                       class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800" placeholder="tapatía, mexicana…">
            </div>
            <div>
                <label class="block text-sm font-bold mb-1" for="imagen_url">Foto (URL)</label>
                <input id="imagen_url" name="imagen_url" type="url" maxlength="500" value="<?= $valor('imagen_url') ?>"
                       class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800" placeholder="https://…">
            </div>
        </div>
    </section>

    <!-- ===== Ingredientes y preparación ===== -->
    <section class="space-y-5 bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-6" x-data="editorReceta()" x-init="iniciar()">
        <div>
            <h2 class="font-display font-bold text-lg">Ingredientes</h2>
            <p class="text-xs text-stone-400">Escribe o elige de la lista; la unidad se sugiere sola. Opcional (puedes dejarla vacía o usar la IA después).</p>
        </div>

        <div class="space-y-2">
            <template x-for="(ing, i) in ingredientes" :key="i">
                <div class="flex gap-2">
                    <input x-model="ing.cantidad" name="ingrediente_cantidad[]" type="number" step="0.01" min="0.01" placeholder="Cant."
                           class="w-20 px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm">
                    <input x-model="ing.unidad" name="ingrediente_unidad[]" placeholder="unidad" maxlength="40"
                           class="w-24 px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm">
                    <input x-model="ing.nombre" name="ingrediente_nombre[]" list="lista-ingredientes" @input="autoUnidad(i)" placeholder="Ingrediente" maxlength="160"
                           class="flex-1 px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm">
                    <button type="button" @click="quitarIngrediente(i)" class="px-2 text-red-500 hover:scale-110 transition-transform" aria-label="Quitar">✕</button>
                </div>
            </template>
        </div>
        <button type="button" @click="agregarIngrediente()" class="text-sm font-bold text-barro-600 dark:text-barro-400 hover:underline">+ Agregar ingrediente</button>

        <div class="pt-2">
            <h2 class="font-display font-bold text-lg">Preparación</h2>
            <p class="text-xs text-stone-400">Un paso por renglón, en orden.</p>
        </div>

        <div class="space-y-2">
            <template x-for="(paso, i) in pasos" :key="i">
                <div class="flex gap-2 items-start">
                    <span class="w-7 h-9 grid place-items-center font-bold text-sm text-stone-400" x-text="(i + 1) + '.'"></span>
                    <textarea x-model="pasos[i]" name="paso[]" rows="2" maxlength="600" placeholder="Describe el paso…"
                              class="flex-1 px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm"></textarea>
                    <button type="button" @click="quitarPaso(i)" class="px-2 pt-2 text-red-500 hover:scale-110 transition-transform" aria-label="Quitar">✕</button>
                </div>
            </template>
        </div>
        <button type="button" @click="agregarPaso()" class="text-sm font-bold text-barro-600 dark:text-barro-400 hover:underline">+ Agregar paso</button>

        <div class="grid sm:grid-cols-2 gap-4 pt-2">
            <div>
                <label class="block text-sm font-bold mb-1" for="notas">Notas</label>
                <textarea id="notas" name="notas" rows="2" maxlength="2000"
                          class="w-full px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm" placeholder="Trucos de la casa…"><?= e($recetaInicial['notas']) ?></textarea>
            </div>
            <div>
                <label class="block text-sm font-bold mb-1" for="url_fuente">Fuente (URL)</label>
                <input id="url_fuente" name="url_fuente" type="url" maxlength="500" value="<?= e($recetaInicial['url_fuente']) ?>"
                       class="w-full px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm" placeholder="https://…">
            </div>
        </div>
    </section>

    <button class="w-full py-3 rounded-2xl bg-barro-500 hover:bg-barro-600 text-white font-bold text-lg transition-all active:scale-[.98]">
        <?= $esEdicion ? 'Guardar cambios' : 'Guardar platillo' ?>
    </button>
    <p class="text-xs text-stone-400 text-center">También puedes dejar la receta vacía y generarla con IA desde la ficha del platillo.</p>
</form>

<?php pieHtml(); ?>
