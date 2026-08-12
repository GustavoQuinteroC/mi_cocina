<?php

declare(strict_types=1);

/**
 * Funciones globales de Mi Cocina.
 * Aquí vive la conexión Medoo ($database), la sesión, el guardián
 * multi-familia (tenant), el CSRF y los helpers de layout y utilería.
 * Este archivo se incluye en TODAS las vistas y controladores.
 */

$raizProyecto = dirname(__DIR__, 2);

if (is_file($raizProyecto . '/vendor/autoload.php')) {
    require_once $raizProyecto . '/vendor/autoload.php';
}

// ---------------------------------------------------------------------------
// Variables de entorno (.env) — los secretos SOLO viven ahí
// ---------------------------------------------------------------------------

if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable($raizProyecto)->safeLoad();
}

// Zona horaria: por defecto la de Guadalajara/CDMX (la app es para una familia
// tapatía). Se puede cambiar con APP_TIMEZONE en .env. Sin esto, PHP usa UTC y
// "hoy"/"ayer", el sello "Hecho hoy" y el resaltado del plan se desfasan ~6 h.
date_default_timezone_set(leerEnv('APP_TIMEZONE', 'America/Mexico_City') ?: 'America/Mexico_City');

/** Lee una variable de entorno con valor por defecto. */
function leerEnv(string $clave, ?string $defecto = null): ?string
{
    $valor = $_ENV[$clave] ?? $_SERVER[$clave] ?? getenv($clave);
    if ($valor === false || $valor === null || $valor === '') {
        return $defecto;
    }
    return (string) $valor;
}

/** Lee una variable de entorno como booleano. */
function leerEnvBool(string $clave, bool $defecto = false): bool
{
    $valor = leerEnv($clave);
    if ($valor === null) {
        return $defecto;
    }
    return in_array(strtolower($valor), ['1', 'true', 'yes', 'on'], true);
}

// ---------------------------------------------------------------------------
// Errores: visibles en dev, a log en producción
// ---------------------------------------------------------------------------

if (leerEnv('APP_ENV', 'prod') === 'dev') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $raizProyecto . '/storage/logs/php-error.log');
}

// ---------------------------------------------------------------------------
// Conexión a la base de datos (Medoo). Nunca concatenar SQL a mano.
// ---------------------------------------------------------------------------

/** @var Medoo\Medoo $database */
$database = new Medoo\Medoo([
    'type' => 'mysql',
    'host' => leerEnv('DB_HOST', '127.0.0.1'),
    'port' => (int) leerEnv('DB_PORT', '3306'),
    'database' => leerEnv('DB_DATABASE', 'mi_cocina'),
    'username' => leerEnv('DB_USERNAME', 'root'),
    'password' => leerEnv('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'error' => PDO::ERRMODE_EXCEPTION,
]);

// ---------------------------------------------------------------------------
// Sesión segura
// ---------------------------------------------------------------------------

/** Inicia la sesión con cookie httponly + samesite (secure en producción). */
function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $segura = leerEnv('APP_ENV', 'prod') === 'prod'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_name('micocina_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $segura,
        'samesite' => 'Lax',
    ]);
    session_start();
}

iniciarSesionSegura();

// ---------------------------------------------------------------------------
// Cabeceras de seguridad (defensa en profundidad). Se envían en TODA respuesta
// porque este archivo se incluye antes de imprimir nada.
// ---------------------------------------------------------------------------

/** Cabeceras de seguridad básicas (anti-clickjacking, anti-sniffing, referrer). */
function cabecerasSeguridad(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

cabecerasSeguridad();

/** ¿Hay un usuario autenticado con familia? */
function sesionActiva(): bool
{
    return isset($_SESSION['idusuario'], $_SESSION['idfamilia']);
}

/**
 * Revalida contra la BD que el usuario de la sesión siga existiendo, activo y en
 * su familia. Refresca también el rol (para que promover/degradar admin surta
 * efecto sin re-login). Evita que un usuario expulsado o desactivado siga
 * operando con una sesión vieja. Cacheado por petición.
 */
function usuarioSesionValido(): bool
{
    static $valido = null;
    if ($valido !== null) {
        return $valido;
    }
    if (!sesionActiva()) {
        return $valido = false;
    }
    global $database;
    $usuario = $database->get('usuarios', ['activo', 'id_familia', 'rol'], ['id' => idUsuarioActual()]);
    $valido = $usuario !== null
        && (int) $usuario['activo'] === 1
        && (int) $usuario['id_familia'] === (int) ($_SESSION['idfamilia'] ?? -1);
    if ($valido) {
        $_SESSION['rolusuario'] = $usuario['rol']; // mantener el rol fresco
    }
    return $valido;
}

/**
 * Corta la petición si no hay sesión: redirige al login (o 401 en AJAX).
 * Llamar al inicio de TODA vista y endpoint privado.
 */
function validarSesion(): void
{
    if (sesionActiva() && usuarioSesionValido()) {
        return;
    }
    // Sesión inexistente o revocada (usuario expulsado/desactivado): se limpia.
    unset($_SESSION['idusuario'], $_SESSION['idfamilia'], $_SESSION['nombreusuario'], $_SESSION['rolusuario']);
    if (peticionEsperaJson()) {
        respuestaJson(['error' => 'No autenticado'], 401);
    }
    header('Location: /index.php');
    exit;
}

/** Cierra la sesión por completo y regresa al login. */
function cerrarSesion(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $p['path'],
            'domain' => $p['domain'],
            'secure' => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
    header('Location: /index.php');
    exit;
}

function idUsuarioActual(): int
{
    return (int) ($_SESSION['idusuario'] ?? 0);
}

function nombreUsuarioActual(): string
{
    return (string) ($_SESSION['nombreusuario'] ?? '');
}

function usuarioEsAdmin(): bool
{
    return ($_SESSION['rolusuario'] ?? '') === 'admin';
}

// ---------------------------------------------------------------------------
// Guardián multi-familia (tenant): el id_familia sale SIEMPRE de la sesión,
// nunca de la petición. Toda consulta a tablas con id_familia pasa por aquí.
// ---------------------------------------------------------------------------

/** id_familia de la sesión. Revienta si no hay: mejor eso que fugar datos. */
function idFamiliaActual(): int
{
    if (!isset($_SESSION['idfamilia'])) {
        throw new RuntimeException('Guardián de familia: no hay familia en sesión.');
    }
    return (int) $_SESSION['idfamilia'];
}

/** Inyecta el filtro de familia en un WHERE de Medoo. */
function filtroFamilia(array $where = []): array
{
    $where['id_familia'] = idFamiliaActual();
    return $where;
}

/**
 * Segunda línea de defensa: verifica que una fila pertenezca a la familia
 * en sesión. Si no, responde 404 y corta (un id ajeno "no existe").
 */
function verificarFamiliaDeFila(?array $fila): array
{
    if ($fila === null || $fila === [] || !isset($fila['id_familia'])
        || (int) $fila['id_familia'] !== idFamiliaActual()) {
        if (peticionEsperaJson()) {
            respuestaJson(['error' => 'Recurso inexistente.'], 404);
        }
        http_response_code(404);
        require dirname(__DIR__, 2) . '/vistas/error404.php';
        exit;
    }
    return $fila;
}

// ---------------------------------------------------------------------------
// CSRF: token por sesión, obligatorio en TODO POST
// ---------------------------------------------------------------------------

function tokenCsrf(): string
{
    if (empty($_SESSION['_token_csrf'])) {
        $_SESSION['_token_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_token_csrf'];
}

/** Valida el token (campo _csrf o cabecera X-CSRF-Token) y corta si falla. */
function exigirCsrf(): void
{
    $recibido = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $token = $_SESSION['_token_csrf'] ?? '';
    if (!is_string($recibido) || $recibido === '' || $token === '' || !hash_equals($token, $recibido)) {
        if (peticionEsperaJson()) {
            respuestaJson(['error' => 'Token CSRF inválido. Recarga la página.'], 419);
        }
        http_response_code(419);
        exit('Token CSRF inválido. Regresa y recarga la página.');
    }
}

// ---------------------------------------------------------------------------
// Peticiones y respuestas
// ---------------------------------------------------------------------------

/** ¿La petición espera JSON (AJAX)? */
function peticionEsperaJson(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $conQue = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return str_contains($accept, 'application/json') || $conQue === 'fetch';
}

/** Respuesta JSON y fin de la petición. */
function respuestaJson(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Cuerpo JSON de la petición como arreglo (o vacío). */
function cuerpoJson(): array
{
    $crudo = file_get_contents('php://input') ?: '';
    $datos = json_decode($crudo, true);
    return is_array($datos) ? $datos : [];
}

/** Campo POST como string recortado. */
function post(string $campo, string $defecto = ''): string
{
    $valor = $_POST[$campo] ?? $defecto;
    return is_string($valor) ? trim($valor) : $defecto;
}

/** Campo GET como string recortado. */
function get(string $campo, string $defecto = ''): string
{
    $valor = $_GET[$campo] ?? $defecto;
    return is_string($valor) ? trim($valor) : $defecto;
}

function redirigir(string $destino): never
{
    header('Location: ' . $destino);
    exit;
}

// ---------------------------------------------------------------------------
// Utilería
// ---------------------------------------------------------------------------

/** Escapa salida HTML (anti-XSS). Úsalo con TODO dato dinámico en las vistas. */
function e(mixed $valor): string
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Mensaje flash de un solo uso. */
function flash(string $tipo, string $mensaje): void
{
    $_SESSION['_flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

/** @return array<int,array{tipo:string,mensaje:string}> Consume los flashes. */
function obtenerFlashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

/** Guarda los valores del POST para repoblar un formulario tras un error. */
function conservarFormulario(array $excepto = ['password', '_csrf']): void
{
    $_SESSION['_anterior'] = array_diff_key($_POST, array_flip($excepto));
}

/** Valor anterior de un formulario (ya escapado). */
function anterior(string $campo, string $defecto = ''): string
{
    return e($_SESSION['_anterior'][$campo] ?? $defecto);
}

function limpiarFormulario(): void
{
    unset($_SESSION['_anterior']);
}

/**
 * URL de un asset con "cache busting" por fecha de modificación (?v=...).
 * Así, al cambiar app.js/app.css, la URL cambia y ni el navegador ni el
 * service worker pueden servir una versión vieja cacheada.
 */
function assetUrl(string $rutaWeb): string
{
    $archivo = dirname(__DIR__, 2) . $rutaWeb;
    $version = is_file($archivo) ? filemtime($archivo) : time();
    return $rutaWeb . '?v=' . $version;
}

/** Etiqueta humana de "hace X días". */
function etiquetaDias(?string $fechahora): string
{
    if ($fechahora === null || $fechahora === '') {
        return 'Nunca';
    }
    $dias = (int) floor((time() - strtotime($fechahora)) / 86400);
    return match (true) {
        $dias <= 0 => 'Hoy',
        $dias === 1 => 'Ayer',
        default => "Hace $dias días",
    };
}

/**
 * Bitácora simple por fecha en storage/logs/.
 * NUNCA registrar contraseñas ni datos sensibles.
 */
function registroLog(string $accion, string $mensaje): void
{
    $ruta = dirname(__DIR__, 2) . '/storage/logs/' . date('Y-m-d') . '.log';
    $linea = sprintf(
        "[%s] [%s] [usuario:%d] %s\n",
        date('H:i:s'),
        $accion,
        (int) ($_SESSION['idusuario'] ?? 0),
        $mensaje
    );
    @file_put_contents($ruta, $linea, FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------------------
// Limitador de tasa simple por archivo (login, registro y acciones de IA).
// No requiere BD ni extensiones: guarda marcas de tiempo en storage/cache/rate.
// ---------------------------------------------------------------------------

/** Ruta del archivo de marcas para una clave de tasa. */
function rutaArchivoTasa(string $clave): string
{
    $dir = dirname(__DIR__, 2) . '/storage/cache/rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/' . hash('sha256', $clave) . '.json';
}

/** Cuántos intentos hay registrados para $clave dentro de la ventana (segundos). */
function contarIntentos(string $clave, int $ventanaSegundos): int
{
    $archivo = rutaArchivoTasa($clave);
    if (!is_file($archivo)) {
        return 0;
    }
    $ahora = time();
    $marcas = json_decode((string) @file_get_contents($archivo), true) ?: [];
    $marcas = array_filter($marcas, fn($t) => ($ahora - (int) $t) < $ventanaSegundos);
    return count($marcas);
}

/** Registra un intento para $clave (conservando solo los de la última hora). */
function registrarIntento(string $clave): void
{
    $archivo = rutaArchivoTasa($clave);
    $ahora = time();
    $marcas = is_file($archivo) ? (json_decode((string) @file_get_contents($archivo), true) ?: []) : [];
    $marcas = array_values(array_filter($marcas, fn($t) => ($ahora - (int) $t) < 3600));
    $marcas[] = $ahora;
    @file_put_contents($archivo, json_encode($marcas), LOCK_EX);
}

/**
 * Permite la acción y registra el intento si no se superó el límite en la ventana.
 * Devuelve false si ya se alcanzó el tope (no registra en ese caso).
 */
function controlarTasa(string $clave, int $limite, int $ventanaSegundos): bool
{
    if (contarIntentos($clave, $ventanaSegundos) >= $limite) {
        return false;
    }
    registrarIntento($clave);
    return true;
}

/** IP del cliente (considera el proxy de Coolify) para las claves de tasa. */
function ipCliente(): string
{
    $reenviada = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($reenviada !== '') {
        return trim(explode(',', $reenviada)[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
}

// ---------------------------------------------------------------------------
// Layout compartido (todas las vistas autenticadas)
// ---------------------------------------------------------------------------

/** Imprime el <head> completo + apertura de <body>. */
function encabezadoHtml(string $titulo): void
{
    ?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e($titulo) ?> · Mi Cocina</title>

    <meta name="csrf-token" content="<?= e(tokenCsrf()) ?>">
    <meta name="theme-color" content="#9a3412">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/assets/iconos/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/iconos/icon-192.png">

    <!-- Modo oscuro sin parpadeo: se aplica antes del primer render -->
    <script>
        if (localStorage.tema === 'oscuro' || (!('tema' in localStorage) && matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        // Paleta cálida "cocina de casa"
                        crema: { 50: '#fdf9f3', 100: '#faf1e4', 200: '#f3e3cb' },
                        barro: { 400: '#e07a4f', 500: '#c95d33', 600: '#a94a26', 700: '#8a3c20', 800: '#6f3019', 900: '#571f0f' },
                        oliva: { 500: '#7a8450', 600: '#5f6a3c' },
                    },
                    fontFamily: {
                        display: ['Fraunces', 'Georgia', 'serif'],
                        body: ['Nunito', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    },
                },
            },
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(assetUrl('/assets/css/app.css')) ?>">

    <!-- app.js va PRIMERO: registra los componentes en 'alpine:init' antes de que Alpine arranque -->
    <script defer src="<?= e(assetUrl('/assets/js/app.js')) ?>"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="font-body bg-crema-50 text-stone-800 dark:bg-stone-950 dark:text-stone-100 min-h-screen antialiased">
    <?php
}

/** Barra de navegación superior + navegación inferior móvil. */
function barraNavegacion(string $moduloActivo): void
{
    $menu = [
        'inicio' => ['/vistas/inicio/index.php', '🏠', 'Inicio'],
        'platillos' => ['/vistas/platillos/index.php', '🍽️', 'Platillos'],
        'despensa' => ['/vistas/despensa/index.php', '🧺', 'Despensa'],
        'plan' => ['/vistas/plan/index.php', '📅', 'Plan'],
        'compras' => ['/vistas/lista_compras/index.php', '🛒', 'Compras'],
        'familia' => ['/vistas/familia/index.php', '👨‍👩‍👧‍👦', 'Familia'],
    ];
    ?>
<nav class="sticky top-0 z-40 backdrop-blur bg-crema-50/90 dark:bg-stone-950/90 border-b border-crema-200 dark:border-stone-800">
    <div class="max-w-6xl mx-auto px-4 flex items-center justify-between h-16">
        <a href="/vistas/inicio/index.php" class="flex items-center gap-2 group">
            <span class="text-2xl transition-transform group-hover:-rotate-12">🍳</span>
            <span class="font-display font-bold text-xl text-barro-700 dark:text-barro-400">Mi Cocina</span>
        </a>

        <div class="hidden md:flex items-center gap-1">
            <?php foreach ($menu as $modulo => [$url, $icono, $etiqueta]): ?>
            <a href="<?= e($url) ?>"
               class="px-3 py-2 rounded-xl text-sm font-semibold transition-colors <?= $modulo === $moduloActivo
                   ? 'bg-barro-500/10 text-barro-700 dark:text-barro-400'
                   : 'text-stone-500 dark:text-stone-400 hover:text-barro-600 hover:bg-barro-500/5' ?>">
                <span class="mr-1"><?= $icono ?></span><?= e($etiqueta) ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="cambiarTema()" aria-label="Cambiar tema"
                    class="w-9 h-9 rounded-xl grid place-items-center text-lg hover:bg-barro-500/10 transition-colors">
                <span class="dark:hidden">🌙</span><span class="hidden dark:inline">☀️</span>
            </button>
            <span class="hidden sm:inline text-sm font-semibold text-stone-500 dark:text-stone-400">
                Hola, <?= e(nombreUsuarioActual()) ?>
            </span>
            <form method="post" action="/index.php">
                <input type="hidden" name="accion" value="salir">
                <input type="hidden" name="_csrf" value="<?= e(tokenCsrf()) ?>">
                <button class="text-sm font-semibold px-3 py-2 rounded-xl text-stone-500 dark:text-stone-400 hover:bg-red-500/10 hover:text-red-600 transition-colors">
                    Salir
                </button>
            </form>
        </div>
    </div>
</nav>

<!-- Navegación inferior (móvil) -->
<nav class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 dark:bg-stone-900/95 backdrop-blur border-t border-crema-200 dark:border-stone-800 pb-[env(safe-area-inset-bottom)]">
    <div class="grid grid-cols-6">
        <?php foreach ($menu as $modulo => [$url, $icono, $etiqueta]): ?>
        <a href="<?= e($url) ?>" class="flex flex-col items-center py-2 text-[9px] font-semibold <?= $modulo === $moduloActivo ? 'text-barro-600 dark:text-barro-400' : 'text-stone-400' ?>">
            <span class="text-xl"><?= $icono ?></span><?= e($etiqueta) ?>
        </a>
        <?php endforeach; ?>
    </div>
</nav>
    <?php
}

/** Imprime los mensajes flash pendientes (toasts). */
function mensajesFlash(): void
{
    ?>
<div id="avisos" class="fixed top-20 right-4 z-50 space-y-2 max-w-sm w-[calc(100%-2rem)] sm:w-auto">
    <?php foreach (obtenerFlashes() as $flash): ?>
    <div class="aviso flex items-start gap-2 px-4 py-3 rounded-2xl shadow-lg text-sm font-semibold animate-aviso
        <?= $flash['tipo'] === 'error' ? 'bg-red-600 text-white' : 'bg-oliva-600 text-white' ?>">
        <span><?= $flash['tipo'] === 'error' ? '⚠️' : '✅' ?></span>
        <span><?= e($flash['mensaje']) ?></span>
    </div>
    <?php endforeach; ?>
</div>
<main class="max-w-6xl mx-auto px-4 py-6 pb-24 md:pb-10">
    <?php
}

/** Cierra el layout. */
function pieHtml(): void
{
    ?>
    <footer class="mt-10 pt-6 border-t border-crema-200 dark:border-stone-800 text-center text-xs text-stone-400 dark:text-stone-600">
        Powered by <a href="https://alkes.dev" target="_blank" rel="noopener noreferrer" class="font-semibold text-barro-600 dark:text-barro-400 hover:underline">Alkes</a>
    </footer>
</main>
</body>
</html>
<?php
}
