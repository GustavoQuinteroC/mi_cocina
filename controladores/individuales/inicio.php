<?php

declare(strict_types=1);

/**
 * Inicio: recomendaciones de "¿qué cocino hoy?".
 * - Prioriza lo que nunca se ha hecho y lo que lleva más tiempo sin cocinarse.
 * - Nunca recomienda lo que se hizo ayer (ni hoy).
 * - Detecta el cierre de ciclo: cuando ya se cocinaron todos los platillos,
 *   lo informa junto con el último platillo hecho.
 */

/**
 * @param string|null $tono Filtro opcional por tono ("qué se antoja hoy").
 * @return array{recomendados: array, ciclo_completo: bool, ultima_entrada: ?array}
 */
function recomendarPlatillos(?string $tono = null, int $limite = 6): array
{
    $platillos = listarPlatillos($tono ? ['tono' => $tono] : []);
    $todos = $tono ? listarPlatillos([]) : $platillos;

    // Ciclo completo = todos los platillos de la familia tienen al menos un registro.
    $cicloCompleto = $todos !== []
        && count(array_filter($todos, fn($p) => $p['veces_cocinado'] === 0)) === 0;

    $excluidos = idsCocinadosAyer();

    $candidatos = array_filter(
        $platillos,
        fn($p) => !in_array((int) $p['id'], $excluidos, true) && !$p['cocinado_hoy']
    );

    // Nunca cocinados primero; después, los de última cocinada más antigua.
    usort($candidatos, function (array $a, array $b): int {
        if (($a['veces_cocinado'] === 0) !== ($b['veces_cocinado'] === 0)) {
            return $a['veces_cocinado'] === 0 ? -1 : 1;
        }
        return strcmp($a['ultima_cocinada'] ?? '', $b['ultima_cocinada'] ?? '');
    });

    return [
        'recomendados' => array_slice(array_values($candidatos), 0, $limite),
        'ciclo_completo' => $cicloCompleto,
        'ultima_entrada' => $cicloCompleto ? ultimaEntradaHistorial() : null,
    ];
}
