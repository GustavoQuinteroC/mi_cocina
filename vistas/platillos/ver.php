<?php

declare(strict_types=1);

/**
 * Detalle de platillo (?id=): receta, acciones de IA y modo cocina.
 * También procesa aquí las acciones POST de eliminar platillo/receta.
 */

require_once dirname(__DIR__, 2) . '/controladores/globales/funcionesPhp.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/platillos.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/recetas.php';

validarSesion();

$idPlatillo = (int) get('id', '0');

// --- Acciones POST (eliminar platillo / eliminar receta) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    exigirCsrf();
    $platillo = obtenerPlatillo($idPlatillo);
    if ($platillo === null || !puedeEditarPlatillo($platillo)) {
        flash('error', 'No puedes modificar ese platillo.');
        redirigir('/vistas/platillos/index.php');
    }
    if (post('accion') === 'eliminar_platillo') {
        eliminarPlatillo($idPlatillo);
        flash('success', 'Platillo eliminado.');
        redirigir('/vistas/platillos/index.php');
    }
    if (post('accion') === 'eliminar_receta') {
        eliminarRecetaDePlatillo($idPlatillo);
        flash('success', 'Receta eliminada.');
    }
    redirigir('/vistas/platillos/ver.php?id=' . $idPlatillo);
}

$platillo = obtenerPlatilloConMeta($idPlatillo);
if ($platillo === null) {
    http_response_code(404);
    require dirname(__DIR__) . '/error404.php';
    exit;
}
$receta = obtenerRecetaDePlatillo($idPlatillo);
$puedeEditar = puedeEditarPlatillo($platillo);

$metaTono = [
    'dulce' => ['🍯', 'from-pink-300 to-rose-400'],
    'salado' => ['🧂', 'from-amber-300 to-orange-400'],
    'agridulce' => ['🍍', 'from-lime-300 to-yellow-400'],
    'picante' => ['🌶️', 'from-red-400 to-orange-500'],
    'otro' => ['🍽️', 'from-stone-300 to-stone-400'],
];
[$emojiTono, $gradienteTono] = $metaTono[$platillo['tono']] ?? $metaTono['otro'];

// Configuración para el componente Alpine (ver assets/js/app.js: fichaPlatillo).
$configuracion = [
    'idPlatillo' => (int) $platillo['id'],
    'favorito' => (bool) $platillo['es_favorito'],
    'cocinado' => (bool) $platillo['cocinado_hoy'],
    'receta' => $receta === null ? null : [
        'ingredientes' => $receta['ingredientes'],
        'pasos' => $receta['pasos'],
        'notas' => $receta['notas'],
        'url_fuente' => $receta['url_fuente'],
        'porciones' => (int) $platillo['porciones'],
        'tiempo_preparacion' => $platillo['tiempo_preparacion'] !== null ? (int) $platillo['tiempo_preparacion'] : null,
        'verificada' => (bool) $receta['verificada'],
        'es_ia' => (bool) $receta['generada_ia'],
    ],
];

encabezadoHtml($platillo['nombre']);
barraNavegacion('platillos');
mensajesFlash();
include __DIR__ . '/_datalist_ingredientes.php';
?>

<script type="application/json" id="config-platillo"><?= json_encode($configuracion, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

<div x-data="fichaPlatillo()" x-init="iniciar()">

    <header class="mb-6">
        <a href="/vistas/platillos/index.php" class="text-sm font-bold text-stone-400 hover:text-barro-600 transition-colors">← Platillos</a>
    </header>

    <!-- Encabezado del platillo -->
    <div class="relative rounded-3xl overflow-hidden mb-6 bg-gradient-to-br <?= $gradienteTono ?>">
        <?php if (!empty($platillo['imagen_url'])): ?>
        <img src="<?= e($platillo['imagen_url']) ?>" alt="<?= e($platillo['nombre']) ?>" class="absolute inset-0 w-full h-full object-cover">
        <div class="absolute inset-0 bg-gradient-to-t from-stone-900/80 to-stone-900/10"></div>
        <?php else: ?>
        <div class="absolute inset-0 bg-gradient-to-t from-stone-900/60 to-transparent"></div>
        <?php endif; ?>

        <div class="relative p-6 sm:p-8 pt-20 sm:pt-28 text-white">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <div class="text-4xl mb-1"><?= empty($platillo['imagen_url']) ? $emojiTono : '' ?></div>
                    <h1 class="font-display text-3xl sm:text-4xl font-bold drop-shadow"><?= e($platillo['nombre']) ?></h1>
                    <?php if (!empty($platillo['descripcion'])): ?>
                    <p class="mt-1 max-w-xl text-white/90 text-sm sm:text-base"><?= e($platillo['descripcion']) ?></p>
                    <?php endif; ?>
                    <div class="flex flex-wrap gap-2 mt-3 text-xs font-bold">
                        <span class="px-2.5 py-1 rounded-full bg-white/20 backdrop-blur"><?= $emojiTono ?> <?= e($platillo['tono']) ?></span>
                        <span class="px-2.5 py-1 rounded-full bg-white/20 backdrop-blur"><?= e($platillo['tipo_comida']) ?></span>
                        <?php if ($platillo['cocina']): ?><span class="px-2.5 py-1 rounded-full bg-white/20 backdrop-blur"><?= e($platillo['cocina']) ?></span><?php endif; ?>
                        <?php if ($platillo['tiempo_preparacion']): ?><span class="px-2.5 py-1 rounded-full bg-white/20 backdrop-blur">⏱ <?= (int) $platillo['tiempo_preparacion'] ?> min</span><?php endif; ?>
                        <span class="px-2.5 py-1 rounded-full bg-white/20 backdrop-blur">🍽 <?= (int) $platillo['porciones'] ?> porciones</span>
                        <span class="px-2.5 py-1 rounded-full bg-white/20 backdrop-blur" title="Dificultad"><?= str_repeat('🔥', (int) $platillo['dificultad']) ?></span>
                        <?php if ($platillo['generado_ia']): ?><span class="px-2.5 py-1 rounded-full bg-violet-500/70">✨ Sugerido por IA</span><?php endif; ?>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button @click="alternarFavorito()"
                            class="px-4 py-2.5 rounded-2xl font-bold text-sm bg-white/20 backdrop-blur hover:bg-white/30 transition-all active:scale-95">
                        <span x-text="favorito ? '★ Favorito' : '☆ Favorito'"></span>
                    </button>
                    <button @click="marcarHecho()" x-show="!cocinado"
                            class="px-4 py-2.5 rounded-2xl font-bold text-sm bg-oliva-500 hover:bg-oliva-600 transition-all active:scale-95">
                        ✔ Lo hice hoy
                    </button>
                    <span x-show="cocinado" x-cloak class="px-4 py-2.5 rounded-2xl font-bold text-sm bg-oliva-600/90">✔ Hecho hoy</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Acciones secundarias -->
    <div class="flex flex-wrap gap-2 mb-8 text-sm">
        <span class="px-3 py-2 text-stone-400 font-semibold">Última vez: <?= e(etiquetaDias($platillo['ultima_cocinada'])) ?> · <?= (int) $platillo['veces_cocinado'] ?> veces</span>
        <span class="flex-1"></span>
        <?php if ($puedeEditar): ?>
        <a href="/vistas/platillos/formulario.php?id=<?= (int) $platillo['id'] ?>" class="px-4 py-2 rounded-xl font-bold bg-white dark:bg-stone-900 border border-crema-200 dark:border-stone-700 hover:border-barro-400 transition-colors">✏️ Editar</a>
        <form method="post" onsubmit="return confirm('¿Eliminar este platillo y su receta? Esta acción no se puede deshacer.')">
            <input type="hidden" name="accion" value="eliminar_platillo">
            <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
            <button class="px-4 py-2 rounded-xl font-bold bg-white dark:bg-stone-900 border border-crema-200 dark:border-stone-700 text-red-600 hover:bg-red-50 dark:hover:bg-red-950 transition-colors">🗑 Eliminar</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- ===================== RECETA ===================== -->
    <section class="grid lg:grid-cols-3 gap-6" x-show="receta && !editando">
        <template x-if="receta">
            <div class="lg:col-span-3 grid lg:grid-cols-3 gap-6">
                <!-- Ingredientes -->
                <div class="bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="font-display font-bold text-xl">🧺 Ingredientes</h2>
                        <span class="text-xs font-bold px-2 py-1 rounded-full"
                              :class="receta.verificada ? 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-400'"
                              x-text="receta.verificada ? '✓ Verificada' : (receta.es_ia ? '✨ IA · sin verificar' : 'Sin verificar')"></span>
                    </div>
                    <p class="text-xs text-stone-400 mb-4" x-show="receta.porciones">🍽 Para <span x-text="receta.porciones"></span> personas · ajústala con la IA si son más o menos</p>
                    <ul class="space-y-2">
                        <template x-for="ing in receta.ingredientes">
                            <li class="flex items-baseline gap-2 text-sm border-b border-dashed border-crema-200 dark:border-stone-800 pb-2">
                                <span class="font-bold text-barro-600 dark:text-barro-400 whitespace-nowrap" x-text="formatearCantidad(ing.cantidad) + ' ' + ing.unidad"></span>
                                <span x-text="ing.nombre"></span>
                            </li>
                        </template>
                    </ul>
                    <p class="text-xs text-stone-400 mt-3" x-show="receta.url_fuente">
                        Fuente: <a :href="receta.url_fuente" target="_blank" rel="noopener noreferrer" class="underline hover:text-barro-600" x-text="receta.url_fuente"></a>
                    </p>
                </div>

                <!-- Pasos -->
                <div class="lg:col-span-2 bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                        <h2 class="font-display font-bold text-xl">👩‍🍳 Preparación</h2>
                        <button @click="abrirModoCocina()" x-show="receta.pasos && receta.pasos.length"
                                class="px-4 py-2 rounded-2xl bg-barro-500 hover:bg-barro-600 text-white font-bold text-sm shadow-lg shadow-barro-500/25 transition-all active:scale-95">
                            🔥 Modo cocina
                        </button>
                    </div>
                    <ol class="space-y-3">
                        <template x-for="(paso, i) in receta.pasos">
                            <li class="flex gap-3 text-sm sm:text-base">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-barro-500/10 text-barro-600 dark:text-barro-400 font-bold grid place-items-center text-sm" x-text="i + 1"></span>
                                <span class="pt-0.5" x-text="paso"></span>
                            </li>
                        </template>
                    </ol>
                    <p class="mt-4 p-3 rounded-2xl bg-crema-100 dark:bg-stone-800 text-sm" x-show="receta.notas">
                        📝 <span x-text="receta.notas"></span>
                    </p>

                    <div class="flex flex-wrap gap-2 mt-5 pt-4 border-t border-crema-200 dark:border-stone-800">
                        <button x-show="!receta.verificada" @click="verificarReceta()"
                                class="px-4 py-2 rounded-xl text-sm font-bold bg-green-600/10 text-green-700 dark:text-green-400 hover:bg-green-600 hover:text-white transition-colors">
                            ✓ Ya la revisé, marcar verificada
                        </button>
                        <button @click="abrirEditor()"
                                class="px-4 py-2 rounded-xl text-sm font-bold bg-white dark:bg-stone-800 border border-crema-200 dark:border-stone-700 hover:border-barro-400 transition-colors">
                            ✏️ Editar receta
                        </button>
                        <button @click="ajusteAbierto = !ajusteAbierto"
                                class="px-4 py-2 rounded-xl text-sm font-bold bg-violet-600/10 text-violet-700 dark:text-violet-400 hover:bg-violet-600 hover:text-white transition-colors">
                            ✨ Ajustar con IA
                        </button>
                    </div>

                    <!-- Ajuste con IA: instrucción + propuesta -->
                    <div x-show="ajusteAbierto" x-cloak class="mt-4 p-4 rounded-2xl bg-violet-500/5 border border-violet-500/20">
                        <label class="block text-sm font-bold mb-2">¿Cómo la ajustamos?</label>
                        <div class="flex flex-wrap gap-2 mb-2 text-xs">
                            <template x-for="sugerencia in ['Hazla vegetariana', 'Para 6 personas', 'Más picante', 'Sin gluten']">
                                <button @click="instruccionAjuste = sugerencia" class="px-2.5 py-1 rounded-full bg-white dark:bg-stone-800 border border-violet-300/40 hover:border-violet-500 font-semibold transition-colors" x-text="sugerencia"></button>
                            </template>
                        </div>
                        <div class="flex gap-2">
                            <input x-model="instruccionAjuste" maxlength="300" placeholder="Ej. hazla vegetariana y para 6 personas"
                                   class="flex-1 px-4 py-2.5 rounded-2xl border border-violet-300/40 bg-white dark:bg-stone-800 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400">
                            <button @click="ajustarConIA()" :disabled="iaOcupada"
                                    class="px-4 py-2.5 rounded-2xl bg-violet-600 text-white font-bold text-sm hover:bg-violet-700 disabled:opacity-50 transition-all">
                                <span x-show="!iaOcupada">Ajustar</span><span x-show="iaOcupada" x-cloak>Pensando…</span>
                            </button>
                        </div>

                        <!-- Propuesta del ajuste -->
                        <div x-show="propuesta" x-cloak class="mt-4 p-4 rounded-2xl bg-white dark:bg-stone-900 border border-violet-300/40">
                            <h3 class="font-bold text-sm mb-2">Vista previa del ajuste (aún no se guarda):</h3>
                            <ul class="text-xs space-y-1 mb-2">
                                <template x-for="ing in (propuesta?.ingredientes || [])">
                                    <li><span class="font-bold" x-text="formatearCantidad(ing.cantidad) + ' ' + ing.unidad"></span> <span x-text="ing.nombre"></span></li>
                                </template>
                            </ul>
                            <ol class="text-xs space-y-1 list-decimal list-inside mb-3">
                                <template x-for="paso in (propuesta?.pasos || [])"><li x-text="paso"></li></template>
                            </ol>
                            <div class="flex gap-2">
                                <button @click="aplicarPropuesta()" class="px-4 py-2 rounded-xl bg-violet-600 text-white text-xs font-bold hover:bg-violet-700">Guardar este ajuste</button>
                                <button @click="propuesta = null" class="px-4 py-2 rounded-xl border border-stone-300 dark:border-stone-700 text-xs font-bold">Descartar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </section>

    <!-- Sin receta: capturar a mano o generar con IA -->
    <section x-show="!receta && !editando" x-cloak
             class="p-10 rounded-3xl bg-white dark:bg-stone-900 border border-dashed border-crema-200 dark:border-stone-700 text-center">
        <div class="text-5xl mb-3">📖</div>
        <h2 class="font-display font-bold text-2xl mb-1">Este platillo aún no tiene receta</h2>
        <p class="text-stone-500 dark:text-stone-400 text-sm mb-6">Puedes dejarla vacía, escribirla tú, o pedirle a la IA que busque la receta real.</p>
        <div class="flex flex-wrap justify-center gap-3">
            <button @click="abrirEditor()"
                    class="px-5 py-3 rounded-2xl font-bold bg-white dark:bg-stone-800 border border-crema-200 dark:border-stone-700 hover:border-barro-400 transition-colors">
                ✍️ Escribirla yo
            </button>
            <button @click="generarConIA()" :disabled="iaOcupada"
                    class="px-5 py-3 rounded-2xl font-bold bg-violet-600 text-white hover:bg-violet-700 shadow-lg shadow-violet-600/25 disabled:opacity-50 transition-all active:scale-95">
                <span x-show="!iaOcupada">✨ Generar con IA</span>
                <span x-show="iaOcupada" x-cloak class="inline-flex items-center gap-2"><span class="animate-spin inline-block">🍳</span> Buscando receta real…</span>
            </button>
        </div>
    </section>

    <!-- Editor de receta (manual) -->
    <section x-show="editando" x-cloak class="bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-6">
        <h2 class="font-display font-bold text-xl mb-4"><span x-text="receta ? 'Editar receta' : 'Nueva receta'"></span></h2>

        <h3 class="font-bold text-sm mb-2">Ingredientes</h3>
        <div class="space-y-2 mb-2">
            <template x-for="(ing, i) in borrador.ingredientes" :key="i">
                <div class="flex gap-2">
                    <input x-model="ing.cantidad" type="number" step="0.01" min="0.01" placeholder="Cant." class="w-20 px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm">
                    <input x-model="ing.unidad" placeholder="unidad" maxlength="40" class="w-24 px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm">
                    <input x-model="ing.nombre" list="lista-ingredientes" @input="autoUnidad(i)" placeholder="Ingrediente" maxlength="160" class="flex-1 px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm">
                    <button @click="borrador.ingredientes.splice(i, 1)" class="px-2 text-red-500 hover:scale-110 transition-transform" aria-label="Quitar">✕</button>
                </div>
            </template>
        </div>
        <button @click="borrador.ingredientes.push({nombre: '', cantidad: 1, unidad: ''})"
                class="text-sm font-bold text-barro-600 dark:text-barro-400 hover:underline mb-5">+ Agregar ingrediente</button>

        <h3 class="font-bold text-sm mb-2">Pasos</h3>
        <div class="space-y-2 mb-2">
            <template x-for="(paso, i) in borrador.pasos" :key="i">
                <div class="flex gap-2 items-start">
                    <span class="w-7 h-9 grid place-items-center font-bold text-sm text-stone-400" x-text="i + 1 + '.'"></span>
                    <textarea x-model="borrador.pasos[i]" rows="2" maxlength="600" placeholder="Describe el paso…"
                              class="flex-1 px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm"></textarea>
                    <button @click="borrador.pasos.splice(i, 1)" class="px-2 pt-2 text-red-500 hover:scale-110 transition-transform" aria-label="Quitar">✕</button>
                </div>
            </template>
        </div>
        <button @click="borrador.pasos.push('')" class="text-sm font-bold text-barro-600 dark:text-barro-400 hover:underline mb-5">+ Agregar paso</button>

        <div class="grid sm:grid-cols-2 gap-4 mb-5">
            <div>
                <label class="block text-sm font-bold mb-1">Notas</label>
                <textarea x-model="borrador.notas" rows="2" maxlength="2000" class="w-full px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm" placeholder="Trucos de la casa…"></textarea>
            </div>
            <div>
                <label class="block text-sm font-bold mb-1">Fuente (URL)</label>
                <input x-model="borrador.url_fuente" type="url" maxlength="500" class="w-full px-3 py-2 rounded-xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm" placeholder="https://…">
            </div>
        </div>

        <div class="flex gap-3">
            <button @click="guardarReceta()" :disabled="iaOcupada" class="px-6 py-3 rounded-2xl bg-barro-500 hover:bg-barro-600 text-white font-bold transition-all active:scale-[.98] disabled:opacity-50">Guardar receta</button>
            <button @click="editando = false" class="px-6 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 font-bold">Cancelar</button>
        </div>
    </section>

    <!-- ===================== MODO COCINA ===================== -->
    <div x-show="modoCocina" x-cloak
         class="fixed inset-0 z-50 bg-stone-950 text-white flex flex-col"
         @keydown.escape.window="cerrarModoCocina()">
        <template x-if="receta">
            <div class="flex flex-col h-full">
                <!-- Barra superior -->
                <div class="flex items-center justify-between p-4 border-b border-white/10">
                    <div class="font-display font-bold text-lg truncate pr-2"><?= e($platillo['nombre']) ?></div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-white/60" x-text="'Paso ' + (pasoActual + 1) + ' de ' + receta.pasos.length"></span>
                        <button @click="cerrarModoCocina()" class="w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 grid place-items-center text-xl" aria-label="Salir">✕</button>
                    </div>
                </div>

                <!-- Progreso -->
                <div class="h-1.5 bg-white/10">
                    <div class="h-full bg-barro-400 transition-all duration-300" :style="'width:' + ((pasoActual + 1) / receta.pasos.length * 100) + '%'"></div>
                </div>

                <!-- Paso actual, grande -->
                <div class="flex-1 grid place-items-center p-6 sm:p-12 overflow-y-auto">
                    <p class="max-w-3xl text-center text-2xl sm:text-4xl font-semibold leading-relaxed" x-text="receta.pasos[pasoActual]"></p>
                </div>

                <!-- Temporizador -->
                <div class="px-6 pb-3 flex flex-wrap items-center justify-center gap-2">
                    <span class="font-mono text-3xl tabular-nums w-28 text-center" :class="segundosTimer > 0 && segundosTimer <= 10 && 'text-red-400 animate-pulse'" x-text="formatearTimer()"></span>
                    <template x-for="minutos in [1, 5, 10]">
                        <button @click="fijarTimer(minutos * 60)" class="px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-sm font-bold" x-text="minutos + ' min'"></button>
                    </template>
                    <button @click="timerCorriendo ? pausarTimer() : iniciarTimer()" class="px-4 py-2 rounded-xl bg-barro-500 hover:bg-barro-600 text-sm font-bold" x-text="timerCorriendo ? '⏸ Pausa' : '▶ Iniciar'"></button>
                    <button @click="reiniciarTimer()" class="px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-sm font-bold">↺</button>
                </div>
                <p class="text-center text-xs text-white/40 pb-2" x-show="pantallaEncendida">🔆 Pantalla encendida mientras cocinas</p>

                <!-- Navegación de pasos -->
                <div class="grid grid-cols-2 gap-3 p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
                    <button @click="pasoActual = Math.max(0, pasoActual - 1)" :disabled="pasoActual === 0"
                            class="py-4 rounded-2xl bg-white/10 hover:bg-white/20 font-bold text-lg disabled:opacity-30">← Anterior</button>
                    <button x-show="pasoActual < receta.pasos.length - 1" @click="pasoActual++"
                            class="py-4 rounded-2xl bg-barro-500 hover:bg-barro-600 font-bold text-lg">Siguiente →</button>
                    <button x-show="pasoActual === receta.pasos.length - 1" x-cloak @click="terminarCocina()"
                            class="py-4 rounded-2xl bg-oliva-500 hover:bg-oliva-600 font-bold text-lg">✔ ¡Listo, lo hice!</button>
                </div>
            </div>
        </template>
    </div>
</div>

<?php pieHtml(); ?>
