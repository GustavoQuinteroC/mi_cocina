<?php

declare(strict_types=1);

/**
 * Lista de compras: alta manual, palomeado y generación automática a
 * partir del plan de la semana (suma ingredientes de las recetas planeadas
 * y resta lo que hay en la despensa).
 */

require_once dirname(__DIR__, 2) . '/controladores/globales/funcionesPhp.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/lista_compras.php';

validarSesion();

$lunes = date('Y-m-d', strtotime('monday this week'));
$articulos = listarCompras();
$pendientes = count(array_filter($articulos, fn($a) => (int) $a['comprado'] === 0));

encabezadoHtml('Lista de compras');
barraNavegacion('compras');
mensajesFlash();
?>

<script type="application/json" id="compras-datos"><?= json_encode(['items' => $articulos, 'lunes' => $lunes], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

<div x-data="listaCompras()" x-init="iniciar()">

    <header class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-display text-3xl font-bold">🛒 Lista de compras</h1>
            <p class="text-stone-500 dark:text-stone-400 text-sm mt-0.5">
                <span x-text="items.filter(i => !i.comprado).length"></span> por comprar ·
                <span x-text="items.length"></span> en total
            </p>
        </div>
        <button @click="generarDesdePlan()"
                class="px-5 py-3 rounded-2xl bg-oliva-600 text-white font-bold hover:bg-oliva-500 shadow-lg shadow-oliva-600/25 transition-all active:scale-95">
            🧾 Generar del plan semanal
        </button>
    </header>

    <!-- Alta manual -->
    <div class="flex flex-wrap gap-2 mb-6 bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-4">
        <input x-model="nuevo.cantidad" type="number" step="0.01" min="0.01" placeholder="Cant."
               class="w-20 px-3 py-2.5 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm">
        <input x-model="nuevo.unidad" placeholder="unidad" maxlength="40"
               class="w-24 px-3 py-2.5 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm">
        <input x-model="nuevo.articulo" list="lista-ingredientes" placeholder="¿Qué hay que comprar?" maxlength="160" @keydown.enter.prevent="agregar()"
               class="flex-1 min-w-40 px-4 py-2.5 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm">
        <button @click="agregar()" class="px-5 py-2.5 rounded-2xl bg-barro-500 text-white font-bold text-sm hover:bg-barro-600 transition-colors">+ Agregar</button>
    </div>

    <!-- Sugerencias del catálogo para el alta manual -->
    <?php
    require_once dirname(__DIR__, 2) . '/controladores/individuales/platillos.php';
    include dirname(__DIR__) . '/platillos/_datalist_ingredientes.php';
    ?>

    <!-- Lista -->
    <template x-if="items.length === 0">
        <div class="p-12 rounded-3xl bg-white dark:bg-stone-900 border border-dashed border-crema-200 dark:border-stone-700 text-center">
            <div class="text-5xl mb-3">🧺</div>
            <h2 class="font-display font-bold text-xl mb-1">La lista está vacía</h2>
            <p class="text-stone-500 dark:text-stone-400 text-sm">Agrega artículos a mano o genérala desde el plan de la semana.</p>
        </div>
    </template>

    <ul class="space-y-2" x-show="items.length > 0">
        <template x-for="item in items" :key="item.id">
            <li class="flex items-center gap-3 bg-white dark:bg-stone-900 rounded-2xl border border-crema-200 dark:border-stone-800 px-4 py-3"
                :class="item.comprado && 'opacity-60'">
                <button @click="alternar(item)" aria-label="Palomear"
                        class="w-6 h-6 shrink-0 rounded-lg border-2 grid place-items-center transition-colors"
                        :class="item.comprado ? 'bg-oliva-500 border-oliva-500 text-white' : 'border-stone-300 dark:border-stone-600'">
                    <span x-show="item.comprado" class="text-sm">✓</span>
                </button>
                <span class="font-bold text-barro-600 dark:text-barro-400 text-sm whitespace-nowrap"
                      x-text="formatearCantidad(item.cantidad) + ' ' + (item.unidad || '')"></span>
                <span class="flex-1 font-semibold" :class="item.comprado && 'line-through decoration-2'" x-text="item.articulo"></span>
                <span x-show="item.origen === 'plan'" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-oliva-500/10 text-oliva-600 dark:text-oliva-500">del plan</span>
                <button @click="eliminar(item)" class="text-stone-300 hover:text-red-500 transition-colors" aria-label="Eliminar">✕</button>
            </li>
        </template>
    </ul>
</div>

<?php pieHtml(); ?>
