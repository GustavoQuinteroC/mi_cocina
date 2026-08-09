<?php
/**
 * Tarjeta de platillo (parcial reutilizable). Espera $platillo con los
 * metadatos de listarPlatillos(). De un vistazo: foto (o gradiente por
 * tono), tono, tiempo, dificultad, favorito y si ya se hizo hoy.
 */
$metaTono = [
    'dulce' => ['🍯', 'from-pink-300 to-rose-400', 'bg-pink-100 text-pink-800 dark:bg-pink-950 dark:text-pink-300'],
    'salado' => ['🧂', 'from-amber-300 to-orange-400', 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'],
    'agridulce' => ['🍍', 'from-lime-300 to-yellow-400', 'bg-lime-100 text-lime-800 dark:bg-lime-950 dark:text-lime-300'],
    'picante' => ['🌶️', 'from-red-400 to-orange-500', 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300'],
    'otro' => ['🍽️', 'from-stone-300 to-stone-400', 'bg-stone-100 text-stone-700 dark:bg-stone-800 dark:text-stone-300'],
];
[$emojiTono, $gradienteTono, $badgeTono] = $metaTono[$platillo['tono']] ?? $metaTono['otro'];
$urlVer = '/vistas/platillos/ver.php?id=' . (int) $platillo['id'];
?>
<article x-data="tarjetaPlatillo(<?= (int) $platillo['id'] ?>, <?= $platillo['es_favorito'] ? 'true' : 'false' ?>, <?= $platillo['cocinado_hoy'] ? 'true' : 'false' ?>)"
         class="group relative bg-white dark:bg-stone-900 rounded-3xl overflow-hidden border border-crema-200 dark:border-stone-800 shadow-sm hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200"
         :class="cocinado && 'opacity-80'">

    <a href="<?= e($urlVer) ?>" class="block">
        <div class="h-32 sm:h-36 relative bg-gradient-to-br <?= $gradienteTono ?>">
            <?php if (!empty($platillo['imagen_url'])): ?>
            <img src="<?= e($platillo['imagen_url']) ?>" alt="<?= e($platillo['nombre']) ?>" loading="lazy"
                 class="absolute inset-0 w-full h-full object-cover">
            <?php else: ?>
            <div class="absolute inset-0 grid place-items-center text-5xl drop-shadow-lg transition-transform group-hover:scale-110"><?= $emojiTono ?></div>
            <?php endif; ?>

            <?php if ($platillo['generado_ia']): ?>
            <span class="absolute top-2 left-2 text-[10px] font-bold px-2 py-1 rounded-full bg-violet-600/90 text-white">✨ IA</span>
            <?php endif; ?>

            <!-- Hecho hoy: sello visual -->
            <div x-show="cocinado" x-cloak class="absolute inset-0 bg-stone-900/40 grid place-items-center">
                <span class="text-white font-display font-bold text-lg rotate-[-8deg] border-2 border-white rounded-xl px-3 py-1">✔ Hecho hoy</span>
            </div>
        </div>
    </a>

    <!-- Favorito -->
    <button @click="alternarFavorito()" aria-label="Favorito"
            class="absolute top-2 right-2 w-9 h-9 rounded-full grid place-items-center bg-white/90 dark:bg-stone-900/90 shadow transition-transform active:scale-125"
            :class="favorito ? 'text-yellow-500' : 'text-stone-300 hover:text-yellow-400'">
        <span x-text="favorito ? '★' : '☆'" class="text-xl leading-none"></span>
    </button>

    <div class="p-4">
        <a href="<?= e($urlVer) ?>">
            <h3 class="font-display font-bold text-lg leading-tight hover:text-barro-600 transition-colors"
                :class="cocinado && 'line-through decoration-2 decoration-oliva-500/60'"><?= e($platillo['nombre']) ?></h3>
        </a>

        <div class="flex flex-wrap gap-1.5 mt-2 text-[11px] font-bold">
            <span class="px-2 py-0.5 rounded-full <?= $badgeTono ?>"><?= $emojiTono ?> <?= e($platillo['tono']) ?></span>
            <span class="px-2 py-0.5 rounded-full bg-crema-100 text-stone-600 dark:bg-stone-800 dark:text-stone-300"><?= e($platillo['tipo_comida']) ?></span>
            <?php if ($platillo['tiempo_preparacion']): ?>
            <span class="px-2 py-0.5 rounded-full bg-crema-100 text-stone-600 dark:bg-stone-800 dark:text-stone-300">⏱ <?= (int) $platillo['tiempo_preparacion'] ?> min</span>
            <?php endif; ?>
            <span class="px-2 py-0.5 rounded-full bg-crema-100 text-stone-600 dark:bg-stone-800 dark:text-stone-300" title="Dificultad">
                <?= str_repeat('●', (int) $platillo['dificultad']) . str_repeat('○', 5 - (int) $platillo['dificultad']) ?>
            </span>
        </div>

        <div class="flex items-center justify-between mt-3">
            <span class="text-xs text-stone-400 dark:text-stone-500 font-semibold">
                <?= $platillo['tiene_receta'] ? ($platillo['receta_verificada'] ? '📗 Receta ✓' : '📙 Receta sin verificar') : '📄 Sin receta' ?>
                · <?= e(etiquetaDias($platillo['ultima_cocinada'])) ?>
            </span>
            <button @click="marcarHecho()" x-show="!cocinado"
                    class="text-xs font-bold px-3 py-1.5 rounded-xl bg-oliva-500/10 text-oliva-600 dark:text-oliva-500 hover:bg-oliva-500 hover:text-white transition-colors">
                Lo hice hoy
            </button>
        </div>
    </div>
</article>
