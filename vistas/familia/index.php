<?php

declare(strict_types=1);

/**
 * Gestión de la familia: código de invitación y miembros.
 * Las acciones de administración (expulsar, regenerar código) requieren
 * rol admin y se procesan aquí mismo por POST.
 */

require_once dirname(__DIR__, 2) . '/controladores/globales/funcionesPhp.php';
require_once dirname(__DIR__, 2) . '/controladores/individuales/familia.php';

validarSesion();

// --- Acciones POST (solo admin) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    exigirCsrf();

    if (!usuarioEsAdmin()) {
        flash('error', 'Solo el administrador puede hacer eso.');
        redirigir('/vistas/familia/index.php');
    }

    if (post('accion') === 'regenerar_codigo') {
        regenerarCodigoInvitacion();
        registroLog('familia', 'Código de invitación regenerado');
        flash('success', 'Código de invitación regenerado.');
    }

    if (post('accion') === 'expulsar') {
        $idUsuario = (int) post('id_usuario');
        if ($idUsuario === idUsuarioActual()) {
            flash('error', 'No puedes expulsarte a ti mismo.');
        } else {
            $expulsado = expulsarMiembro($idUsuario);
            if ($expulsado) {
                registroLog('familia', 'Miembro expulsado: ' . $idUsuario);
            }
            flash($expulsado ? 'success' : 'error', $expulsado
                ? 'Miembro expulsado de la familia.'
                : 'No se pudo expulsar (¿es admin o no existe?).');
        }
    }

    redirigir('/vistas/familia/index.php');
}

$familia = familiaActual();
$miembros = miembrosFamilia();

encabezadoHtml('Mi familia');
barraNavegacion('familia');
mensajesFlash();
?>

<header class="mb-6">
    <h1 class="font-display text-3xl font-bold">👨‍👩‍👧‍👦 <?= e($familia['nombre'] ?? 'Mi familia') ?></h1>
    <p class="text-stone-500 dark:text-stone-400 text-sm mt-0.5">Cada quien ve solo lo de su familia. Invita a los tuyos con el código.</p>
</header>

<div class="grid md:grid-cols-2 gap-6 max-w-4xl">

    <!-- Código de invitación -->
    <section class="bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-6">
        <h2 class="font-display font-bold text-xl mb-3">🎟️ Código de invitación</h2>
        <p class="text-sm text-stone-500 dark:text-stone-400 mb-4">
            Compártelo con tu familia: al registrarse eligen <em>"Tengo un código"</em> y lo escriben.
        </p>

        <div class="flex items-center gap-2" x-data="{ copiado: false }">
            <code class="flex-1 text-center text-2xl font-mono font-bold tracking-[.3em] px-4 py-3 rounded-2xl bg-crema-100 dark:bg-stone-800 text-barro-700 dark:text-barro-400 select-all">
                <?= e($familia['codigo_invitacion'] ?? '') ?>
            </code>
            <button @click="navigator.clipboard.writeText('<?= e($familia['codigo_invitacion'] ?? '') ?>'); copiado = true; setTimeout(() => copiado = false, 1500)"
                    class="px-4 py-3 rounded-2xl bg-barro-500 text-white font-bold hover:bg-barro-600 transition-all active:scale-95">
                <span x-show="!copiado">📋</span><span x-show="copiado" x-cloak>✔</span>
            </button>
        </div>

        <?php if (usuarioEsAdmin()): ?>
        <form method="post" class="mt-4"
              onsubmit="return confirm('El código actual dejará de funcionar. ¿Regenerar?')">
            <input type="hidden" name="accion" value="regenerar_codigo">
            <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
            <button class="text-sm font-bold text-stone-400 hover:text-barro-600 transition-colors">↺ Regenerar código</button>
        </form>
        <?php endif; ?>
    </section>

    <!-- Miembros -->
    <section class="bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-6">
        <h2 class="font-display font-bold text-xl mb-4">Miembros (<?= count($miembros) ?>)</h2>
        <ul class="space-y-3">
            <?php foreach ($miembros as $miembro): ?>
            <li class="flex items-center gap-3">
                <span class="w-10 h-10 rounded-full bg-gradient-to-br from-barro-400 to-barro-600 text-white grid place-items-center font-bold">
                    <?= e(mb_strtoupper(mb_substr($miembro['nombre'], 0, 1))) ?>
                </span>
                <div class="flex-1 min-w-0">
                    <p class="font-bold truncate">
                        <?= e($miembro['nombre']) ?>
                        <?php if ((int) $miembro['id'] === idUsuarioActual()): ?><span class="text-xs text-stone-400 font-semibold">(tú)</span><?php endif; ?>
                    </p>
                    <p class="text-xs text-stone-400 truncate"><?= e($miembro['correo']) ?></p>
                </div>
                <span class="text-[10px] font-bold px-2 py-1 rounded-full uppercase tracking-wide <?= $miembro['rol'] === 'admin'
                    ? 'bg-barro-500/10 text-barro-600 dark:text-barro-400'
                    : 'bg-stone-100 dark:bg-stone-800 text-stone-500' ?>">
                    <?= e($miembro['rol'] === 'admin' ? 'Admin' : 'Miembro') ?>
                </span>
                <?php if (usuarioEsAdmin() && $miembro['rol'] === 'miembro' && (int) $miembro['id'] !== idUsuarioActual()): ?>
                <form method="post" onsubmit="return confirm('¿Expulsar a <?= e($miembro['nombre']) ?> de la familia?')">
                    <input type="hidden" name="accion" value="expulsar">
                    <input type="hidden" name="id_usuario" value="<?= (int) $miembro['id'] ?>">
                    <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
                    <button class="text-red-500 hover:scale-110 transition-transform" title="Expulsar">✕</button>
                </form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<?php pieHtml(); ?>
