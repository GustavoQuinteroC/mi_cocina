<?php

declare(strict_types=1);

/**
 * Despensa: tabla con TODO el catálogo de ingredientes. Por cada uno, dos
 * switches: "Tengo" y "Falta". Los que tengo suben hasta arriba. Buscador
 * global que filtra. También recomendaciones de la IA con lo que tengo y
 * alta de ingredientes nuevos al catálogo (verificados por IA).
 */

require_once dirname(__DIR__, 2) . '/controladores/globales/funcionesPhp.php';
require_once dirname(__DIR__, 2) . '/controladores/globales/funcionesIA.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/platillos.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/despensa.php';

validarSesion();

$datos = [
    'items' => catalogoConEstadoDespensa(),
    'ia' => iaActiva(),
];

encabezadoHtml('Despensa');
barraNavegacion('despensa');
mensajesFlash();
?>

<!-- Datos iniciales en un <script> (no en el atributo x-data, para no romper con acentos/comillas) -->
<script type="application/json" id="despensa-datos"><?= json_encode($datos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

<div x-data="despensa()" x-init="iniciar()">

    <header class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h1 class="font-display text-3xl font-bold">🧺 Mi despensa</h1>
            <p class="text-stone-500 dark:text-stone-400 text-sm mt-0.5">
                Marca lo que tienes en casa y lo que hace falta.
                <span class="font-semibold text-oliva-600 dark:text-oliva-500" x-text="'Tengo ' + tengoCount"></span> ·
                <span class="font-semibold text-amber-600" x-text="faltaCount + ' por comprar'"></span>
            </p>
        </div>
        <button @click="recomendar()" :disabled="cargandoReco"
                class="px-5 py-3 rounded-2xl bg-violet-600 text-white font-bold hover:bg-violet-700 shadow-lg shadow-violet-600/25 disabled:opacity-50 transition-all active:scale-95">
            <span x-show="!cargandoReco">✨ ¿Qué cocino con esto?</span>
            <span x-show="cargandoReco" x-cloak class="inline-flex items-center gap-2"><span class="animate-spin inline-block">🍳</span> Pensando…</span>
        </button>
    </header>

    <!-- Recomendaciones de la IA -->
    <section x-show="recomendaciones.length > 0" x-cloak class="mb-6">
        <h2 class="font-display font-bold text-xl mb-3">Con lo que tienes puedes hacer:</h2>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <template x-for="(reco, idx) in recomendaciones" :key="idx">
                <div class="bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-5 flex flex-col">
                    <h3 class="font-display font-bold text-lg" x-text="reco.nombre"></h3>
                    <p class="text-sm text-stone-500 dark:text-stone-400 mt-1 flex-1" x-text="reco.descripcion"></p>
                    <template x-if="reco.faltantes && reco.faltantes.length">
                        <p class="text-xs mt-2 text-amber-700 dark:text-amber-400 font-semibold">Te falta: <span x-text="reco.faltantes.join(', ')"></span></p>
                    </template>
                    <template x-if="!reco.faltantes || !reco.faltantes.length">
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
    </section>

    <!-- Buscador global -->
    <div class="sticky top-16 z-30 bg-crema-50 dark:bg-stone-950 py-2 mb-2">
        <div class="flex items-center gap-2">
            <div class="relative flex-1 min-w-0">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-stone-400">🔍</span>
                <input x-model="busqueda" type="search" placeholder="Buscar ingrediente… (jitomate, chile, queso…)"
                       class="w-full pl-11 pr-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-white dark:bg-stone-900 focus:outline-none focus:ring-2 focus:ring-barro-400">
            </div>
            <button x-show="items.some(i => i.hace_falta)" x-cloak @click="aCompras()"
                    class="shrink-0 px-4 py-3 rounded-2xl bg-oliva-600 text-white font-bold text-sm hover:bg-oliva-500 transition-colors">
                🛒 <span class="hidden sm:inline">Faltantes a compras</span><span class="sm:hidden">A compras</span>
            </button>
        </div>
    </div>

    <!-- Si no aparece en el catálogo: agregarlo con IA -->
    <div x-show="sinResultados" x-cloak class="mb-4 p-4 rounded-2xl bg-violet-500/5 border border-violet-500/20 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm">No encontramos <b x-text="busqueda"></b> en el catálogo.</p>
        <button @click="agregarAlCatalogo(busqueda)" :disabled="guardandoCatalogo"
                class="px-4 py-2 rounded-xl bg-violet-600 text-white text-sm font-bold hover:bg-violet-700 disabled:opacity-50 transition-colors">
            <span x-show="!guardandoCatalogo">➕ Agregarlo al catálogo (IA)</span>
            <span x-show="guardandoCatalogo" x-cloak>Revisando…</span>
        </button>
    </div>

    <!-- Encabezado de la tabla -->
    <div class="hidden sm:flex items-center gap-2 px-4 pb-1 text-[11px] font-bold uppercase tracking-wider text-stone-400">
        <span class="flex-1">Ingrediente</span>
        <span class="w-24 text-center">Tengo</span>
        <span class="w-24 text-center">Falta</span>
    </div>

    <!-- Lista de ingredientes (los que tengo, arriba) -->
    <ul class="space-y-1.5">
        <template x-for="item in filtrados" :key="item.nombre">
            <li class="flex items-center gap-2 bg-white dark:bg-stone-900 rounded-2xl border px-4 py-2.5 transition-colors"
                :class="item.tengo ? 'border-oliva-400/50' : (item.hace_falta ? 'border-amber-400/50' : 'border-crema-200 dark:border-stone-800')">
                <div class="flex-1 min-w-0">
                    <span class="font-semibold text-sm" x-text="item.nombre"></span>
                    <span class="ml-2 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-crema-100 dark:bg-stone-800 text-stone-500" x-text="item.categoria"></span>
                </div>
                <button @click="fijar(item, 'tengo')" title="Lo tengo en casa"
                        class="w-[4.25rem] sm:w-24 shrink-0 py-1.5 rounded-xl text-xs font-bold transition-colors"
                        :class="item.tengo ? 'bg-oliva-500 text-white' : 'bg-stone-100 dark:bg-stone-800 text-stone-400 hover:text-oliva-600'">
                    <span x-text="item.tengo ? '✓ Tengo' : 'Tengo'"></span>
                </button>
                <button @click="fijar(item, 'hace_falta')" title="Hace falta / se está acabando"
                        class="w-[4.25rem] sm:w-24 shrink-0 py-1.5 rounded-xl text-xs font-bold transition-colors"
                        :class="item.hace_falta ? 'bg-amber-500 text-white' : 'bg-stone-100 dark:bg-stone-800 text-stone-400 hover:text-amber-600'">
                    <span x-text="item.hace_falta ? '⚠ Falta' : 'Falta'"></span>
                </button>
            </li>
        </template>
    </ul>

    <p x-show="filtrados.length === 0 && !sinResultados" x-cloak class="text-center text-stone-400 py-8">Sin resultados.</p>
</div>

<?php pieHtml(); ?>
