<?php

declare(strict_types=1);

/**
 * Familias: alta, código de invitación, miembros y preferencias.
 * crearFamilia() y familiaPorCodigo() se usan ANTES de tener sesión
 * (registro), por eso no llevan filtro; el resto siempre va scoped.
 */

/** Crea una familia con código de invitación único y devuelve su id. */
function crearFamilia(string $nombre): int
{
    global $database;
    do {
        // Código legible de 8 caracteres, sin ambiguos (0/O, 1/I).
        $codigo = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8);
        $existe = $database->has('familias', ['codigo_invitacion' => $codigo]);
    } while ($existe);

    $database->insert('familias', ['nombre' => $nombre, 'codigo_invitacion' => $codigo]);
    return (int) $database->id();
}

function familiaPorCodigo(string $codigo): ?array
{
    global $database;
    $fila = $database->get('familias', '*', [
        'codigo_invitacion' => strtoupper(trim($codigo)),
        'activa' => 1,
    ]);
    return $fila ?: null;
}

/** Datos de la familia en sesión. */
function familiaActual(): ?array
{
    global $database;
    $fila = $database->get('familias', '*', ['id' => idFamiliaActual()]);
    return $fila ?: null;
}

/** Regenera el código de invitación de la familia en sesión (solo admin). */
function regenerarCodigoInvitacion(): string
{
    global $database;
    do {
        $codigo = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8);
        $existe = $database->has('familias', ['codigo_invitacion' => $codigo]);
    } while ($existe);

    $database->update('familias', ['codigo_invitacion' => $codigo], ['id' => idFamiliaActual()]);
    return $codigo;
}

/** Miembros de la familia en sesión. */
function miembrosFamilia(): array
{
    global $database;
    return $database->select(
        'usuarios',
        ['id', 'nombre', 'correo', 'rol', 'activo', 'sistema_creacion'],
        filtroFamilia(['ORDER' => ['rol' => 'ASC', 'nombre' => 'ASC']])
    );
}

/** Expulsa a un miembro (nunca a un admin; eso lo valida la vista). */
function expulsarMiembro(int $idUsuario): bool
{
    global $database;
    $resultado = $database->delete('usuarios', filtroFamilia(['id' => $idUsuario, 'rol' => 'miembro']));
    return $resultado->rowCount() > 0;
}

/** Cuántas personas hay en la familia (para escalar las recetas de la IA). */
function contarMiembrosFamilia(): int
{
    global $database;
    return max(1, (int) $database->count('usuarios', filtroFamilia()));
}

/** Preferencias (alergias/gustos/dietas) de todos los miembros, para la IA. */
function preferenciasFamilia(): array
{
    global $database;
    $idsMiembros = $database->select('usuarios', 'id', filtroFamilia());
    if ($idsMiembros === []) {
        return [];
    }
    return $database->select('preferencias', ['tipo', 'valor'], ['id_usuario' => $idsMiembros]);
}
