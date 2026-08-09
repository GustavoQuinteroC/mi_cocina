<?php

declare(strict_types=1);

/**
 * Listado de platillos con filtros (?tono=, ?comida=, ?q=).
 */

require_once dirname(__DIR__, 2) . '/controladores/globales/funcionesPhp.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/platillos.php';

validarSesion();

$filtros = [
    'tono' => get('tono'),
    'tipo_comida' => get('comida'),
    'q' => mb_substr(get('q'), 0, 100),
];
$platillos = listarPlatillos($filtros);

encabezadoHtml('Platillos');
barraNavegacion('platillos');
mensajesFlash();
?>

<header class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="font-display text-3xl font-bold">Platillos</h1>
        <p class="text-stone-500 dark:text-stone-400 text-sm mt-0.5"><?= count($platillos) ?> en el recetario de la casa</p>
    </div>
    <a href="/vistas/platillos/formulario.php" class="px-5 py-3 rounded-2xl bg-barro-500 text-white font-bold hover:bg-barro-600 shadow-lg shadow-barro-500/25 transition-all active:scale-95">
        + Nuevo platillo
    </a>
</header>

<!-- Filtros -->
<form method="get" action="/vistas/platillos/index.php" class="flex flex-wrap gap-2 mb-6">
    <input name="q" value="<?= e($filtros['q']) ?>" placeholder="Buscar por nombre…"
           class="flex-1 min-w-40 px-4 py-2.5 rounded-2xl border border-crema-200 dark:border-stone-700 bg-white dark:bg-stone-900 focus:outline-none focus:ring-2 focus:ring-barro-400 text-sm">

    <select name="tono" onchange="this.form.submit()"
            class="px-3 py-2.5 rounded-2xl border border-crema-200 dark:border-stone-700 bg-white dark:bg-stone-900 text-sm font-semibold">
        <option value="">Todos los tonos</option>
        <?php foreach (TONOS_PLATILLO as $t): ?>
        <option value="<?= e($t) ?>" <?= $filtros['tono'] === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="comida" onchange="this.form.submit()"
            class="px-3 py-2.5 rounded-2xl border border-crema-200 dark:border-stone-700 bg-white dark:bg-stone-900 text-sm font-semibold">
        <option value="">Cualquier comida</option>
        <?php foreach (TIPOS_COMIDA as $tc): ?>
        <option value="<?= e($tc) ?>" <?= $filtros['tipo_comida'] === $tc ? 'selected' : '' ?>><?= e(ucfirst($tc)) ?></option>
        <?php endforeach; ?>
    </select>

    <button class="px-4 py-2.5 rounded-2xl bg-stone-800 dark:bg-stone-700 text-white text-sm font-bold hover:bg-stone-700 transition-colors">Filtrar</button>
</form>

<?php if ($platillos === []): ?>
<div class="p-12 rounded-3xl bg-white dark:bg-stone-900 border border-dashed border-crema-200 dark:border-stone-700 text-center">
    <div class="text-5xl mb-3">🍳</div>
    <h2 class="font-display font-bold text-xl mb-1">No hay platillos con esos filtros</h2>
    <p class="text-stone-500 dark:text-stone-400 text-sm mb-4">Agrega el primero o limpia los filtros.</p>
    <a href="/vistas/platillos/index.php" class="font-bold text-barro-600 dark:text-barro-400 hover:underline">Ver todos</a>
</div>
<?php else: ?>
<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($platillos as $platillo): ?>
        <?php include __DIR__ . '/_tarjeta.php'; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php pieHtml(); ?>
