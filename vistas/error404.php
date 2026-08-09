<?php
/**
 * Página 404 (también la usa verificarFamiliaDeFila cuando un recurso
 * no pertenece a la familia en sesión).
 */
if (!function_exists('encabezadoHtml')) {
    require_once dirname(__DIR__) . '/controladores/globales/funcionesPhp.php';
}
encabezadoHtml('No encontrado');
if (sesionActiva()) {
    barraNavegacion('');
}
mensajesFlash();
?>
<div class="text-center py-24">
    <div class="text-6xl mb-4">🍽️</div>
    <h1 class="font-display text-3xl font-bold text-barro-700 dark:text-barro-400 mb-2">Aquí no hay nada servido</h1>
    <p class="text-stone-500 dark:text-stone-400 mb-6">La página que buscas no existe o pertenece a otra cocina.</p>
    <a href="/vistas/inicio/index.php" class="inline-block px-5 py-3 rounded-2xl bg-barro-500 text-white font-bold hover:bg-barro-600 transition-colors">Volver al inicio</a>
</div>
<?php pieHtml(); ?>
