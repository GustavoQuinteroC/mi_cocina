<?php

declare(strict_types=1);

/**
 * Planificador semanal: cuadrícula día × tipo de comida.
 * ?semana=YYYY-MM-DD (cualquier día; se normaliza al lunes).
 */

require_once dirname(__DIR__, 2) . '/controladores/globales/funcionesPhp.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/platillos.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/plan_semanal.php';

validarSesion();

$referencia = get('semana', date('Y-m-d'));
$marcaTiempo = strtotime($referencia) ?: time();
$lunes = date('Y-m-d', strtotime('monday this week', $marcaTiempo));

$dias = [];
foreach (range(0, 6) as $desfase) {
    $dias[] = date('Y-m-d', strtotime($lunes . " +$desfase days"));
}

$plan = planDeSemana($lunes);
$platillos = listarPlatillos([]);
$semanaAnterior = date('Y-m-d', strtotime($lunes . ' -7 days'));
$semanaSiguiente = date('Y-m-d', strtotime($lunes . ' +7 days'));

$nombresDias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$iconosComida = ['desayuno' => '🌅', 'comida' => '☀️', 'cena' => '🌙', 'snack' => '🍿'];
$hoy = date('Y-m-d');

// Datos ligeros de platillos para los <select> de cada celda.
$opcionesPlatillos = array_map(fn($p) => ['id' => (int) $p['id'], 'nombre' => $p['nombre']], $platillos);

encabezadoHtml('Plan semanal');
barraNavegacion('plan');
mensajesFlash();
?>

<header class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="font-display text-3xl font-bold">Plan semanal</h1>
        <p class="text-stone-500 dark:text-stone-400 text-sm mt-0.5">
            Semana del <?= e(date('d/m', strtotime($lunes))) ?> al <?= e(date('d/m/Y', strtotime($lunes . ' +6 days'))) ?>
        </p>
    </div>
    <div class="flex gap-2">
        <a href="/vistas/plan/index.php?semana=<?= e($semanaAnterior) ?>" class="px-4 py-2.5 rounded-2xl bg-white dark:bg-stone-900 border border-crema-200 dark:border-stone-700 font-bold text-sm hover:border-barro-400 transition-colors">← Anterior</a>
        <a href="/vistas/plan/index.php" class="px-4 py-2.5 rounded-2xl bg-white dark:bg-stone-900 border border-crema-200 dark:border-stone-700 font-bold text-sm hover:border-barro-400 transition-colors">Hoy</a>
        <a href="/vistas/plan/index.php?semana=<?= e($semanaSiguiente) ?>" class="px-4 py-2.5 rounded-2xl bg-white dark:bg-stone-900 border border-crema-200 dark:border-stone-700 font-bold text-sm hover:border-barro-400 transition-colors">Siguiente →</a>
    </div>
</header>

<?php if ($platillos === []): ?>
<div class="p-10 rounded-3xl bg-white dark:bg-stone-900 border border-dashed border-crema-200 dark:border-stone-700 text-center">
    <p class="font-semibold text-stone-500 dark:text-stone-400 mb-3">Primero agrega platillos para poder planear la semana.</p>
    <a href="/vistas/platillos/formulario.php" class="inline-block px-5 py-3 rounded-2xl bg-barro-500 text-white font-bold">+ Nuevo platillo</a>
</div>
<?php else: ?>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7" x-data="planSemanal()">
    <?php foreach ($dias as $i => $fecha): $esHoy = $fecha === $hoy; ?>
    <section class="rounded-3xl border p-3 <?= $esHoy
        ? 'bg-barro-500/5 border-barro-400/50 ring-2 ring-barro-400/30'
        : 'bg-white dark:bg-stone-900 border-crema-200 dark:border-stone-800' ?>">
        <h2 class="font-display font-bold text-center mb-2 <?= $esHoy ? 'text-barro-600 dark:text-barro-400' : '' ?>">
            <?= e($nombresDias[$i]) ?>
            <span class="block text-xs font-body font-semibold text-stone-400"><?= e(date('d/m', strtotime($fecha))) ?><?= $esHoy ? ' · hoy' : '' ?></span>
        </h2>

        <div class="space-y-2">
            <?php foreach (TIPOS_COMIDA as $tipoComida):
                $celda = $plan[$fecha][$tipoComida] ?? null; ?>
            <div class="rounded-2xl bg-crema-50 dark:bg-stone-800/60 p-2">
                <label class="flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-stone-400 mb-1 px-1">
                    <?= $iconosComida[$tipoComida] ?> <?= e($tipoComida) ?>
                </label>
                <select @change="asignarCelda('<?= e($fecha) ?>', '<?= e($tipoComida) ?>', $event.target.value)"
                        class="w-full text-xs font-semibold rounded-xl border-0 bg-white dark:bg-stone-800 py-2 px-2 focus:ring-2 focus:ring-barro-400 <?= $celda ? 'text-stone-800 dark:text-stone-100' : 'text-stone-400' ?>">
                    <option value="">— libre —</option>
                    <?php foreach ($opcionesPlatillos as $opcion): ?>
                    <option value="<?= (int) $opcion['id'] ?>" <?= $celda !== null && (int) $celda['id_platillo'] === $opcion['id'] ? 'selected' : '' ?>>
                        <?= e($opcion['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($celda): ?>
                <a href="/vistas/platillos/ver.php?id=<?= (int) $celda['id_platillo'] ?>" class="block px-1 mt-1 text-[11px] font-bold text-barro-600 dark:text-barro-400 hover:underline truncate">
                    Ver receta →
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>

<p class="text-xs text-stone-400 mt-4">Los cambios se guardan solos al elegir un platillo. Elige "— libre —" para quitarlo.</p>
<?php endif; ?>

<?php pieHtml(); ?>
