<?php
/**
 * Parcial: <datalist> de ingredientes del catálogo para autocompletar,
 * más un mapa global nombre→unidad_sugerida que usan los editores para
 * rellenar la unidad automáticamente al elegir un ingrediente.
 * Requiere que catalogoIngredientes() esté disponible (platillos.php).
 */
$catalogo = catalogoIngredientes();
$mapaUnidades = [];
foreach ($catalogo as $ingrediente) {
    $mapaUnidades[mb_strtolower($ingrediente['nombre'])] = $ingrediente['unidad_sugerida'];
}
?>
<datalist id="lista-ingredientes">
    <?php foreach ($catalogo as $ingrediente): ?>
    <option value="<?= e($ingrediente['nombre']) ?>"><?= e($ingrediente['categoria']) ?></option>
    <?php endforeach; ?>
</datalist>
<script>
    // Mapa nombre(minúsculas) -> unidad sugerida, para autocompletar la unidad.
    window.CATALOGO_UNIDADES = <?= json_encode($mapaUnidades, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
