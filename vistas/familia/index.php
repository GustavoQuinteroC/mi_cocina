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

// --- Acciones POST ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    exigirCsrf();
    $accion = post('accion');

    // ---- Acciones sobre la propia cuenta (cualquier miembro) ----
    if ($accion === 'agregar_preferencia') {
        $agregada = agregarPreferencia(post('tipo'), post('valor'));
        flash($agregada ? 'success' : 'error', $agregada
            ? 'Preferencia agregada. La IA la tomará en cuenta.'
            : 'No se pudo agregar (vacía, muy larga o repetida).');
        redirigir('/vistas/familia/index.php');
    }
    if ($accion === 'eliminar_preferencia') {
        eliminarPreferencia((int) post('id'));
        flash('success', 'Preferencia eliminada.');
        redirigir('/vistas/familia/index.php');
    }
    if ($accion === 'cambiar_password') {
        $error = cambiarMiPassword((string) ($_POST['password_actual'] ?? ''), (string) ($_POST['password_nueva'] ?? ''));
        flash($error === null ? 'success' : 'error', $error ?? 'Contraseña actualizada.');
        redirigir('/vistas/familia/index.php');
    }
    if ($accion === 'salir_familia') {
        if (abandonarFamilia()) {
            registroLog('familia', 'El usuario abandonó la familia');
            cerrarSesion(); // limpia la sesión y regresa al login
        }
        flash('error', 'Como administrador no puedes salir: transfiere primero la administración.');
        redirigir('/vistas/familia/index.php');
    }

    // ---- Acciones solo admin ----
    if (!usuarioEsAdmin()) {
        flash('error', 'Solo el administrador puede hacer eso.');
        redirigir('/vistas/familia/index.php');
    }

    if ($accion === 'regenerar_codigo') {
        regenerarCodigoInvitacion();
        registroLog('familia', 'Código de invitación regenerado');
        flash('success', 'Código de invitación regenerado.');
    }

    if ($accion === 'expulsar') {
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

    if ($accion === 'restablecer_password') {
        $idUsuario = (int) post('id_usuario');
        if ($idUsuario === idUsuarioActual()) {
            flash('error', 'Cambia tu propia contraseña desde "Mi cuenta".');
        } else {
            $temporal = restablecerPasswordMiembro($idUsuario);
            if ($temporal !== null) {
                registroLog('familia', 'Contraseña restablecida para el usuario ' . $idUsuario);
                flash('success', 'Contraseña temporal: ' . $temporal . ' — compártela y pídele que la cambie al entrar.');
            } else {
                flash('error', 'No se pudo restablecer (¿miembro inexistente?).');
            }
        }
    }

    if ($accion === 'transferir_admin') {
        $idUsuario = (int) post('id_usuario');
        if (transferirAdministracion($idUsuario)) {
            registroLog('familia', 'Administración transferida a: ' . $idUsuario);
            flash('success', 'Administración transferida. Ahora eres miembro.');
        } else {
            flash('error', 'No se pudo transferir la administración.');
        }
    }

    if ($accion === 'eliminar_familia') {
        if (post('confirmacion') !== 'ELIMINAR') {
            flash('error', 'Escribe ELIMINAR (en mayúsculas) para confirmar el borrado.');
            redirigir('/vistas/familia/index.php');
        }
        registroLog('familia', 'Familia eliminada por completo');
        eliminarFamilia();
        cerrarSesion(); // borra la sesión y regresa al login
    }

    redirigir('/vistas/familia/index.php');
}

$familia = familiaActual();
$miembros = miembrosFamilia();
$misPreferencias = preferenciasDeUsuario();

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
                <div class="flex items-center gap-1.5">
                    <form method="post" onsubmit="return confirm('¿Generar una contraseña temporal para <?= e($miembro['nombre']) ?>? La actual dejará de funcionar.')">
                        <input type="hidden" name="accion" value="restablecer_password">
                        <input type="hidden" name="id_usuario" value="<?= (int) $miembro['id'] ?>">
                        <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
                        <button class="text-stone-400 hover:text-barro-600 transition-colors" title="Restablecer contraseña">🔑</button>
                    </form>
                    <form method="post" onsubmit="return confirm('¿Transferir la administración a <?= e($miembro['nombre']) ?>? Tú pasarás a ser miembro.')">
                        <input type="hidden" name="accion" value="transferir_admin">
                        <input type="hidden" name="id_usuario" value="<?= (int) $miembro['id'] ?>">
                        <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
                        <button class="text-stone-400 hover:text-barro-600 transition-colors" title="Hacer administrador">👑</button>
                    </form>
                    <form method="post" onsubmit="return confirm('¿Expulsar a <?= e($miembro['nombre']) ?> de la familia?')">
                        <input type="hidden" name="accion" value="expulsar">
                        <input type="hidden" name="id_usuario" value="<?= (int) $miembro['id'] ?>">
                        <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
                        <button class="text-red-500 hover:scale-110 transition-transform" title="Expulsar">✕</button>
                    </form>
                </div>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<div class="grid md:grid-cols-2 gap-6 max-w-4xl mt-6">

    <!-- Mis preferencias -->
    <section class="bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-6">
        <h2 class="font-display font-bold text-xl mb-1">🥗 Mis preferencias</h2>
        <p class="text-sm text-stone-500 dark:text-stone-400 mb-4">
            Alergias, gustos y dietas. La IA las respeta al generar y recomendar recetas para la familia.
        </p>

        <form method="post" class="flex flex-wrap gap-2 mb-4">
            <input type="hidden" name="accion" value="agregar_preferencia">
            <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
            <select name="tipo" class="px-3 py-2.5 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm font-semibold">
                <option value="alergia">Alergia</option>
                <option value="gusto">Gusto</option>
                <option value="dieta">Dieta</option>
            </select>
            <input name="valor" required minlength="2" maxlength="255" placeholder="cacahuate, picante, vegetariano…"
                   class="flex-1 min-w-40 px-4 py-2.5 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm focus:outline-none focus:ring-2 focus:ring-barro-400">
            <button class="px-4 py-2.5 rounded-2xl bg-barro-500 text-white font-bold text-sm hover:bg-barro-600 transition-colors">+ Agregar</button>
        </form>

        <?php if ($misPreferencias === []): ?>
        <p class="text-sm text-stone-400">Aún no agregas preferencias. Si alguien es alérgico a algo, agrégalo aquí.</p>
        <?php else: ?>
        <ul class="flex flex-wrap gap-2">
            <?php foreach ($misPreferencias as $pref):
                $colorPref = $pref['tipo'] === 'alergia'
                    ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
                    : ($pref['tipo'] === 'dieta'
                        ? 'bg-oliva-500/10 text-oliva-600 dark:text-oliva-500'
                        : 'bg-crema-100 text-stone-600 dark:bg-stone-800 dark:text-stone-300'); ?>
            <li class="flex items-center gap-1.5 pl-3 pr-1 py-1 rounded-full text-xs font-bold <?= $colorPref ?>">
                <span class="uppercase text-[9px] opacity-70"><?= e($pref['tipo']) ?></span>
                <span><?= e($pref['valor']) ?></span>
                <form method="post" class="inline-flex">
                    <input type="hidden" name="accion" value="eliminar_preferencia">
                    <input type="hidden" name="id" value="<?= (int) $pref['id'] ?>">
                    <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
                    <button class="w-5 h-5 grid place-items-center rounded-full hover:bg-black/10 dark:hover:bg-white/10" title="Quitar">✕</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <!-- Mi cuenta -->
    <section class="bg-white dark:bg-stone-900 rounded-3xl border border-crema-200 dark:border-stone-800 p-6">
        <h2 class="font-display font-bold text-xl mb-4">🔒 Mi cuenta</h2>

        <form method="post" class="space-y-3">
            <input type="hidden" name="accion" value="cambiar_password">
            <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
            <div>
                <label class="block text-sm font-bold mb-1" for="password_actual">Contraseña actual</label>
                <input id="password_actual" name="password_actual" type="password" required autocomplete="current-password"
                       class="w-full px-4 py-2.5 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm focus:outline-none focus:ring-2 focus:ring-barro-400">
            </div>
            <div>
                <label class="block text-sm font-bold mb-1" for="password_nueva">Nueva contraseña <span class="font-normal text-stone-400">(8–72)</span></label>
                <input id="password_nueva" name="password_nueva" type="password" required minlength="8" maxlength="72" autocomplete="new-password"
                       class="w-full px-4 py-2.5 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 text-sm focus:outline-none focus:ring-2 focus:ring-barro-400">
            </div>
            <button class="px-4 py-2.5 rounded-2xl bg-barro-500 text-white font-bold text-sm hover:bg-barro-600 transition-colors">Cambiar contraseña</button>
        </form>

        <?php if (!usuarioEsAdmin()): ?>
        <form method="post" class="mt-4 pt-4 border-t border-crema-200 dark:border-stone-800"
              onsubmit="return confirm('¿Seguro que quieres salir de esta familia? Perderás el acceso a sus platillos, plan y despensa.')">
            <input type="hidden" name="accion" value="salir_familia">
            <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
            <button class="text-sm font-bold text-red-600 hover:underline">Salir de esta familia</button>
        </form>
        <?php else: ?>
        <p class="mt-4 pt-4 border-t border-crema-200 dark:border-stone-800 text-xs text-stone-400">
            Eres el administrador. Para salir, primero transfiere la administración a otro miembro (icono 👑 junto a su nombre).
        </p>
        <?php endif; ?>
    </section>
</div>

<?php if (usuarioEsAdmin()): ?>
<!-- Zona de peligro (solo admin) -->
<section class="max-w-4xl mt-6 bg-red-50 dark:bg-red-950/40 rounded-3xl border border-red-300/50 dark:border-red-800/50 p-6">
    <h2 class="font-display font-bold text-xl text-red-700 dark:text-red-400 mb-1">⚠️ Zona de peligro</h2>
    <p class="text-sm text-red-700/80 dark:text-red-300/80 mb-4">
        Eliminar la familia borra <b>para siempre</b> todos sus platillos, recetas, historial, plan,
        despensa, lista de compras y las cuentas de todos sus miembros. No se puede deshacer.
    </p>
    <form method="post" class="flex flex-wrap items-center gap-2"
          onsubmit="return confirm('Esto elimina TODA la familia y no se puede deshacer. ¿Continuar?')">
        <input type="hidden" name="accion" value="eliminar_familia">
        <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
        <input name="confirmacion" required placeholder="Escribe ELIMINAR" autocomplete="off"
               class="px-4 py-2.5 rounded-2xl border border-red-300 dark:border-red-800 bg-white dark:bg-stone-900 text-sm font-mono tracking-widest focus:outline-none focus:ring-2 focus:ring-red-400">
        <button class="px-4 py-2.5 rounded-2xl bg-red-600 text-white font-bold text-sm hover:bg-red-700 transition-colors">Eliminar familia</button>
    </form>
</section>
<?php endif; ?>

<?php pieHtml(); ?>
