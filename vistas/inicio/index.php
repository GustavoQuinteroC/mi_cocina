<?php

declare(strict_types=1);

/**
 * Inicio: antojo del día, recomendaciones, plan de hoy e historial.
 */

require_once dirname(__DIR__, 2) . '/controladores/globales/funcionesPhp.php';
require_once dirname(__DIR__, 2) . '/controladores/globales/funcionesIA.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/platillos.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/historial.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/plan_semanal.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/inicio.php';

validarSesion();

$iaActiva = iaActiva() ? 'true' : 'false';

$tono = get('tono');
if (!in_array($tono, TONOS_PLATILLO, true)) {
    $tono = null;
}

$resultado = recomendarPlatillos($tono);
$recomendados = $resultado['recomendados'];
$cicloCompleto = $resultado['ciclo_completo'];
$ultimaEntrada = $resultado['ultima_entrada'];
$planHoy = planDeHoy();
$historial = historialReciente(8);

$fichasTono = [
    'dulce' => '🍯 Dulce',
    'salado' => '🧂 Salado',
    'agridulce' => '🍍 Agridulce',
    'picante' => '🌶️ Picante',
    'otro' => '🍽️ Otro',
];

encabezadoHtml('Inicio');
barraNavegacion('inicio');
mensajesFlash();
?>

<header class="mb-6">
    <h1 class="font-display text-3xl sm:text-4xl font-bold">¿Qué se te antoja hoy?</h1>
    <p class="text-stone-500 dark:text-stone-400 mt-1">Elige un tono y te decimos qué cocinar.</p>
</header>

<div x-data="recomendadorDespensa(<?= $iaActiva ?>)">
    <!-- Antojo libre: pídele a la IA con tus propias palabras -->
    <form @submit.prevent="pedirAntojo()" class="mb-4">
        <div class="flex flex-col sm:flex-row gap-2">
            <label class="sr-only" for="antojo-ia">Pídele a la IA</label>
            <input id="antojo-ia" type="text" x-model="antojo" maxlength="400" :disabled="cargandoAntojo"
                   :class="grabando ? 'ring-2 ring-red-400 border-red-300' : ''"
                   :placeholder="grabando ? '🔴 Escuchando… habla y el texto irá apareciendo' : 'Pídeme lo que sea: &quot;dame la receta de birria&quot; o &quot;se me antojan camarones cremosos&quot;…'"
                   class="flex-1 min-w-0 px-4 py-3 rounded-2xl bg-white dark:bg-stone-900 border border-crema-200 dark:border-stone-800 text-sm focus:outline-none focus:ring-2 focus:ring-barro-400 disabled:opacity-60 placeholder:text-stone-400 transition-shadow">

            <!-- Dictado por micrófono (transcrito con IA) -->
            <button type="button" x-show="soportaMic" @click="alternarDictado()" :disabled="transcribiendo || cargandoAntojo"
                    :class="grabando ? 'bg-red-600 hover:bg-red-700 shadow-red-600/30' : 'bg-oliva-600 hover:bg-oliva-500 shadow-oliva-600/30'"
                    class="px-4 py-3 rounded-2xl font-bold text-sm text-white shadow-lg disabled:opacity-50 transition-all active:scale-95 shrink-0"
                    :title="grabando ? 'Detener dictado' : 'Dictar con el micrófono'">
                <span x-show="!grabando && !transcribiendo">🎤 Dictar</span>
                <span x-show="grabando" x-cloak class="inline-flex items-center gap-1.5"><span class="animate-pulse">⏺</span> <span x-text="segundosDictado + ' s'"></span> · Listo</span>
                <span x-show="transcribiendo" x-cloak class="inline-flex items-center gap-2"><span class="animate-spin inline-block">🎧</span> Transcribiendo…</span>
            </button>

            <button type="submit" :disabled="cargandoAntojo || grabando || transcribiendo"
                    class="px-5 py-3 rounded-2xl font-bold text-sm bg-barro-500 text-white hover:bg-barro-600 shadow-lg shadow-barro-500/30 disabled:opacity-50 transition-all active:scale-95 shrink-0">
                <span x-show="!cargandoAntojo">🪄 Pedir a la IA</span>
                <span x-show="cargandoAntojo" x-cloak class="inline-flex items-center gap-2"><span class="animate-spin inline-block">🍲</span> Buscando…</span>
            </button>
        </div>
        <p class="text-xs text-stone-400 mt-1.5">Escríbelo o díctalo con el 🎤: pide un platillo concreto y te doy su receta más tradicional, o cuéntame tu antojo y te recomiendo qué cocinar.</p>
    </form>

    <!-- Filtro por tono del día + botones de IA -->
    <div class="flex flex-wrap gap-2 mb-4">
        <a href="/vistas/inicio/index.php" class="px-4 py-2.5 rounded-2xl font-bold text-sm transition-all <?= $tono === null
            ? 'bg-barro-500 text-white shadow-lg shadow-barro-500/30'
            : 'bg-white dark:bg-stone-900 border border-crema-200 dark:border-stone-800 hover:border-barro-400' ?>">
            ✨ Todos
        </a>
        <?php foreach ($fichasTono as $clave => $etiqueta): ?>
        <a href="/vistas/inicio/index.php?tono=<?= e($clave) ?>" class="px-4 py-2.5 rounded-2xl font-bold text-sm transition-all <?= $tono === $clave
            ? 'bg-barro-500 text-white shadow-lg shadow-barro-500/30'
            : 'bg-white dark:bg-stone-900 border border-crema-200 dark:border-stone-800 hover:border-barro-400' ?>">
            <?= e($etiqueta) ?>
        </a>
        <?php endforeach; ?>

        <!-- Sorpréndeme: la IA sugiere y crea un platillo del tono elegido -->
        <button @click="sugerirConIA('<?= e($tono ?? 'salado') ?>')"
                class="px-4 py-2.5 rounded-2xl font-bold text-sm bg-violet-600 text-white hover:bg-violet-700 shadow-lg shadow-violet-600/30 transition-all active:scale-95">
            ✨ Sorpréndeme con IA
        </button>

        <!-- Cocinar con lo que tengo en casa (misma recomendación que en Despensa) -->
        <button @click="recomendar()" :disabled="cargandoReco"
                class="px-4 py-2.5 rounded-2xl font-bold text-sm bg-oliva-600 text-white hover:bg-oliva-500 shadow-lg shadow-oliva-600/30 disabled:opacity-50 transition-all active:scale-95">
            <span x-show="!cargandoReco">🧺 Cocinar con lo que tengo</span>
            <span x-show="cargandoReco" x-cloak class="inline-flex items-center gap-2"><span class="animate-spin inline-block">🍳</span> Pensando…</span>
        </button>
    </div>

    <!-- Recomendaciones de la IA (por despensa o por antojo libre) -->
    <section x-show="recomendaciones.length > 0" x-cloak class="mb-8">
        <h2 class="font-display font-bold text-xl mb-3" x-text="tituloReco"></h2>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <template x-for="(reco, idx) in recomendaciones" :key="idx">
                <div class="bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-5 flex flex-col">
                    <h3 class="font-display font-bold text-lg" x-text="reco.nombre"></h3>
                    <p class="text-sm text-stone-500 dark:text-stone-400 mt-1 flex-1" x-text="reco.descripcion"></p>
                    <template x-if="reco.faltantes && reco.faltantes.length">
                        <p class="text-xs mt-2 text-amber-700 dark:text-amber-400 font-semibold">Te falta: <span x-text="reco.faltantes.join(', ')"></span></p>
                    </template>
                    <template x-if="recoConDespensa && (!reco.faltantes || !reco.faltantes.length)">
                        <p class="text-xs mt-2 text-oliva-600 dark:text-oliva-500 font-semibold">✓ Tienes todo</p>
                    </template>
                    <button @click="crearPlatillo(reco)" :disabled="reco._creando"
                            class="mt-3 px-4 py-2 rounded-xl bg-barro-500 text-white text-sm font-bold hover:bg-barro-600 disabled:opacity-50 transition-colors">
                        <span x-show="!reco._creando">Crear con receta (IA)</span>
                        <span x-show="reco._creando" x-cloak>Creando…</span>
                    </button>
                </div>
            </template>
        </div>
        <p class="text-xs text-stone-400 mt-2">¿No tienes ingredientes en tu despensa? <a href="/vistas/despensa/index.php" class="font-bold text-barro-600 dark:text-barro-400 hover:underline">Agrégalos aquí</a>.</p>
    </section>
</div>

<?php if ($cicloCompleto): ?>
<!-- Cierre de ciclo: ya se cocinó todo -->
<div class="mb-8 p-5 rounded-3xl bg-gradient-to-r from-oliva-500/15 to-barro-500/15 border border-oliva-500/30">
    <h2 class="font-display font-bold text-xl">🏆 ¡Ya cocinaron todos los platillos!</h2>
    <p class="text-sm text-stone-600 dark:text-stone-300 mt-1">
        <?php if ($ultimaEntrada): ?>
        El último fue <a class="font-bold text-barro-600 dark:text-barro-400 hover:underline" href="/vistas/platillos/ver.php?id=<?= (int) $ultimaEntrada['id_platillo'] ?>"><?= e($ultimaEntrada['nombre_platillo']) ?></a>
        (<?= e(etiquetaDias($ultimaEntrada['fecha_cocinado'])) ?>, por <?= e($ultimaEntrada['nombre_cocinero'] ?? 'alguien de la casa') ?>).
        <?php endif; ?>
        Toca rotar: te recomendamos los que llevan más tiempo sin cocinarse — sin repetir el de ayer.
    </p>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-8">
    <!-- Recomendaciones -->
    <section class="lg:col-span-2">
        <h2 class="font-display font-bold text-xl mb-4">
            <?= $tono ? 'Para tu antojo ' . e($fichasTono[$tono]) : 'Recomendados para hoy' ?>
        </h2>

        <?php if ($recomendados === []): ?>
        <div class="p-8 rounded-3xl bg-white dark:bg-stone-900 border border-dashed border-crema-200 dark:border-stone-700 text-center">
            <div class="text-4xl mb-2">🥘</div>
            <p class="font-semibold text-stone-500 dark:text-stone-400">
                <?= $tono ? 'No hay platillos de ese tono todavía (o ya se hicieron hoy/ayer).' : 'Aún no hay platillos por recomendar.' ?>
            </p>
            <a href="/vistas/platillos/formulario.php" class="inline-block mt-4 px-5 py-2.5 rounded-2xl bg-barro-500 text-white font-bold hover:bg-barro-600 transition-colors">+ Agregar platillo</a>
        </div>
        <?php else: ?>
        <div class="grid sm:grid-cols-2 gap-4">
            <?php foreach ($recomendados as $platillo): ?>
                <?php include dirname(__DIR__) . '/platillos/_tarjeta.php'; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- Columna lateral -->
    <aside class="space-y-6">
        <!-- Plan de hoy -->
        <section class="bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-display font-bold text-lg">📅 Hoy en el menú</h2>
                <a href="/vistas/plan/index.php" class="text-xs font-bold text-barro-600 dark:text-barro-400 hover:underline">Ver semana →</a>
            </div>
            <?php if ($planHoy === []): ?>
            <p class="text-sm text-stone-400">Nada planeado para hoy. <a href="/vistas/plan/index.php" class="text-barro-600 dark:text-barro-400 font-bold hover:underline">Planear</a></p>
            <?php else: ?>
            <ul class="space-y-2">
                <?php foreach ($planHoy as $celda): ?>
                <li class="flex items-center gap-3 text-sm">
                    <span class="w-20 shrink-0 text-xs font-bold uppercase tracking-wide text-stone-400"><?= e($celda['tipo_comida']) ?></span>
                    <a href="/vistas/platillos/ver.php?id=<?= (int) $celda['id_platillo'] ?>" class="font-semibold hover:text-barro-600 transition-colors"><?= e($celda['nombre_platillo']) ?></a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <!-- Historial reciente -->
        <section class="bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-5">
            <h2 class="font-display font-bold text-lg mb-3">🕰️ Últimos cocinados</h2>
            <?php if ($historial === []): ?>
            <p class="text-sm text-stone-400">Aún no marcan nada como hecho. En cada platillo usa <em>"Lo hice hoy"</em>.</p>
            <?php else: ?>
            <ul class="space-y-2.5">
                <?php foreach ($historial as $entrada): ?>
                <li class="flex items-center justify-between gap-2 text-sm">
                    <a href="/vistas/platillos/ver.php?id=<?= (int) $entrada['id_platillo'] ?>" class="font-semibold truncate hover:text-barro-600 transition-colors"><?= e($entrada['nombre_platillo']) ?></a>
                    <span class="text-xs text-stone-400 shrink-0"><?= e(etiquetaDias($entrada['fecha_cocinado'])) ?> · <?= e($entrada['nombre_cocinero'] ?? '—') ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </aside>
</div>

<?php pieHtml(); ?>
