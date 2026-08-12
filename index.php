<?php

declare(strict_types=1);

/**
 * Mi Cocina — index raíz: login y registro.
 * Como en los demás proyectos de la casa, el login vive aquí con su
 * propia vista; si ya hay sesión se redirige al inicio.
 */

require_once __DIR__ . '/controladores/globales/login.php';

// --- Acciones POST (login / registro / salir) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    exigirCsrf();
    match (post('accion')) {
        'login' => procesarLogin(),
        'registro' => procesarRegistro(),
        'salir' => cerrarSesion(),
        default => redirigir('/index.php'),
    };
}

// --- Sesión activa: directo al inicio ---
if (sesionActiva()) {
    redirigir('/vistas/inicio/index.php');
}

$pantalla = get('pantalla') === 'registro' ? 'registro' : 'login';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pantalla === 'registro' ? 'Crear cuenta' : 'Entrar' ?> · Mi Cocina</title>
    <meta name="theme-color" content="#9a3412">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/assets/iconos/icon.svg" type="image/svg+xml">
    <script>
        if (localStorage.tema === 'oscuro' || (!('tema' in localStorage) && matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: {
                colors: {
                    crema: { 50: '#fdf9f3', 100: '#faf1e4', 200: '#f3e3cb' },
                    barro: { 400: '#e07a4f', 500: '#c95d33', 600: '#a94a26', 700: '#8a3c20' },
                    oliva: { 600: '#5f6a3c' },
                },
                fontFamily: {
                    display: ['Fraunces', 'Georgia', 'serif'],
                    body: ['Nunito', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                },
            } },
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(assetUrl('/assets/css/app.css')) ?>">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="font-body bg-crema-100 dark:bg-stone-950 text-stone-800 dark:text-stone-100 min-h-screen grid place-items-center p-4">

<div class="w-full max-w-md">
    <div class="text-center mb-6">
        <div class="text-5xl mb-2">🍳</div>
        <h1 class="font-display font-bold text-3xl text-barro-700 dark:text-barro-400">Mi Cocina</h1>
        <p class="text-stone-500 dark:text-stone-400 text-sm mt-1">Organiza qué cocinar, en familia.</p>
    </div>

    <?php foreach (obtenerFlashes() as $flash): ?>
    <div class="mb-3 px-4 py-3 rounded-2xl text-sm font-semibold <?= $flash['tipo'] === 'error' ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' : 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' ?>">
        <?= e($flash['mensaje']) ?>
    </div>
    <?php endforeach; ?>

    <?php if ($pantalla === 'login'): ?>
    <!-- ==================== LOGIN ==================== -->
    <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-xl shadow-barro-500/5 p-6 sm:p-8 border border-crema-200 dark:border-stone-800">
        <h2 class="font-display text-2xl font-bold mb-5">Entrar</h2>

        <form method="post" action="/index.php" class="space-y-4">
            <input type="hidden" name="accion" value="login">
            <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">

            <div>
                <label class="block text-sm font-bold mb-1" for="correo">Correo</label>
                <input id="correo" name="correo" type="email" required value="<?= anterior('correo') ?>"
                       class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-barro-400"
                       placeholder="tu@correo.com" autocomplete="email">
            </div>

            <div>
                <label class="block text-sm font-bold mb-1" for="password">Contraseña</label>
                <input id="password" name="password" type="password" required
                       class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-barro-400"
                       placeholder="••••••••" autocomplete="current-password">
            </div>

            <button class="w-full py-3 rounded-2xl bg-barro-500 hover:bg-barro-600 text-white font-bold text-lg transition-all active:scale-[.98]">
                Entrar 🍲
            </button>
        </form>

        <p class="text-sm text-center mt-5 text-stone-500 dark:text-stone-400">
            ¿No tienes cuenta?
            <a href="/index.php?pantalla=registro" class="font-bold text-barro-600 dark:text-barro-400 hover:underline">Crea una o únete a tu familia</a>
        </p>
    </div>

    <?php else: ?>
    <!-- ==================== REGISTRO ==================== -->
    <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-xl shadow-barro-500/5 p-6 sm:p-8 border border-crema-200 dark:border-stone-800"
         x-data="{ modo: '<?= anterior('modo', 'crear') === 'unirme' ? 'unirme' : 'crear' ?>' }">
        <h2 class="font-display text-2xl font-bold mb-5">Crear cuenta</h2>

        <form method="post" action="/index.php" class="space-y-4">
            <input type="hidden" name="accion" value="registro">
            <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
            <input type="hidden" name="modo" :value="modo">

            <div>
                <label class="block text-sm font-bold mb-1" for="nombre">Tu nombre</label>
                <input id="nombre" name="nombre" required minlength="2" maxlength="120" value="<?= anterior('nombre') ?>"
                       class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-barro-400"
                       placeholder="Ana">
            </div>

            <div>
                <label class="block text-sm font-bold mb-1" for="correo">Correo</label>
                <input id="correo" name="correo" type="email" required value="<?= anterior('correo') ?>"
                       class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-barro-400"
                       placeholder="tu@correo.com" autocomplete="email">
            </div>

            <div>
                <label class="block text-sm font-bold mb-1" for="password">Contraseña <span class="font-normal text-stone-400">(mínimo 8)</span></label>
                <input id="password" name="password" type="password" required minlength="8" maxlength="72"
                       class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-barro-400"
                       autocomplete="new-password">
            </div>

            <!-- Crear familia nueva o unirse con código -->
            <div class="grid grid-cols-2 gap-2 p-1 rounded-2xl bg-crema-100 dark:bg-stone-800">
                <button type="button" @click="modo = 'crear'"
                        :class="modo === 'crear' ? 'bg-white dark:bg-stone-700 shadow font-bold' : 'text-stone-500'"
                        class="py-2 rounded-xl text-sm transition-all">🏡 Nueva familia</button>
                <button type="button" @click="modo = 'unirme'"
                        :class="modo === 'unirme' ? 'bg-white dark:bg-stone-700 shadow font-bold' : 'text-stone-500'"
                        class="py-2 rounded-xl text-sm transition-all">🎟️ Tengo un código</button>
            </div>

            <div x-show="modo === 'crear'" x-cloak>
                <label class="block text-sm font-bold mb-1" for="nombre_familia">Nombre de tu familia / casa</label>
                <input id="nombre_familia" name="nombre_familia" maxlength="120" value="<?= anterior('nombre_familia') ?>"
                       class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-barro-400"
                       placeholder="Casa García">
                <p class="text-xs text-stone-400 mt-1">Serás administrador y podrás invitar a los demás.</p>
            </div>

            <div x-show="modo === 'unirme'" x-cloak>
                <label class="block text-sm font-bold mb-1" for="codigo_invitacion">Código de invitación</label>
                <input id="codigo_invitacion" name="codigo_invitacion" maxlength="8" value="<?= anterior('codigo_invitacion') ?>"
                       class="w-full px-4 py-3 rounded-2xl border border-crema-200 dark:border-stone-700 bg-crema-50 dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-barro-400 uppercase tracking-widest font-mono"
                       placeholder="ABCD1234">
                <p class="text-xs text-stone-400 mt-1">Pídelo a quien administra tu familia (está en la sección Familia).</p>
            </div>

            <button class="w-full py-3 rounded-2xl bg-barro-500 hover:bg-barro-600 text-white font-bold text-lg transition-all active:scale-[.98]">
                Crear cuenta 🥄
            </button>
        </form>

        <p class="text-sm text-center mt-5 text-stone-500 dark:text-stone-400">
            ¿Ya tienes cuenta? <a href="/index.php" class="font-bold text-barro-600 dark:text-barro-400 hover:underline">Entrar</a>
        </p>
    </div>
    <?php endif; ?>

    <p class="text-center text-xs text-stone-400 dark:text-stone-600 mt-6">
        Powered by <a href="https://alkes.dev" target="_blank" rel="noopener noreferrer" class="font-semibold text-barro-600 dark:text-barro-400 hover:underline">Alkes</a>
    </p>
</div>

</body>
</html>
