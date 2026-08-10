<?php

declare(strict_types=1);

/**
 * Familias: alta, código de invitación, miembros y preferencias.
 * crearFamilia() y familiaPorCodigo() se usan ANTES de tener sesión
 * (registro), por eso no llevan filtro; el resto siempre va scoped.
 */

/**
 * Genera un código de invitación de 8 caracteres, legible (sin 0/O, 1/I) y con
 * random_int (CSPRNG): no debe ser adivinable, porque cualquiera con el código
 * puede unirse a la familia y ver todos sus datos.
 */
function generarCodigoInvitacionUnico(): string
{
    global $database;
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $maximo = strlen($alfabeto) - 1;
    do {
        $codigo = '';
        for ($i = 0; $i < 8; $i++) {
            $codigo .= $alfabeto[random_int(0, $maximo)];
        }
        $existe = $database->has('familias', ['codigo_invitacion' => $codigo]);
    } while ($existe);

    return $codigo;
}

/** Crea una familia con código de invitación único y devuelve su id. */
function crearFamilia(string $nombre): int
{
    global $database;
    $database->insert('familias', ['nombre' => $nombre, 'codigo_invitacion' => generarCodigoInvitacionUnico()]);
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
    $codigo = generarCodigoInvitacionUnico();
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

// ---------------------------------------------------------------------------
// Preferencias del usuario en sesión (alergias / gustos / dietas).
// Cada quien administra las suyas; la IA las respeta al recomendar/generar.
// La tabla `preferencias` se scopea por id_usuario (siempre el de la sesión).
// ---------------------------------------------------------------------------

const TIPOS_PREFERENCIA = ['alergia', 'gusto', 'dieta'];

/** Preferencias del usuario en sesión. */
function preferenciasDeUsuario(): array
{
    global $database;
    return $database->select(
        'preferencias',
        ['id', 'tipo', 'valor'],
        ['id_usuario' => idUsuarioActual(), 'ORDER' => ['tipo' => 'ASC', 'valor' => 'ASC']]
    );
}

/** Agrega una preferencia del usuario en sesión (sin duplicar). Devuelve true si se agregó. */
function agregarPreferencia(string $tipo, string $valor): bool
{
    global $database;
    if (!in_array($tipo, TIPOS_PREFERENCIA, true)) {
        return false;
    }
    $valor = mb_substr(trim($valor), 0, 255);
    if (mb_strlen($valor) < 2) {
        return false;
    }
    $where = ['id_usuario' => idUsuarioActual(), 'tipo' => $tipo, 'valor' => $valor];
    if ($database->has('preferencias', $where)) {
        return false;
    }
    $database->insert('preferencias', $where);
    return true;
}

/** Elimina una preferencia (solo si es del usuario en sesión). */
function eliminarPreferencia(int $id): void
{
    global $database;
    $database->delete('preferencias', ['id' => $id, 'id_usuario' => idUsuarioActual()]);
}

// ---------------------------------------------------------------------------
// Gestión de cuenta y familia
// ---------------------------------------------------------------------------

/**
 * Restablece la contraseña de un miembro de la familia (solo admin) y devuelve
 * una contraseña temporal para que el admin se la comparta. No hay envío de
 * correo, así que se muestra una sola vez.
 */
function restablecerPasswordMiembro(int $idUsuario): ?string
{
    global $database;
    $miembro = $database->get('usuarios', ['id'], filtroFamilia(['id' => $idUsuario]));
    if (!$miembro) {
        return null;
    }
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $maximo = strlen($alfabeto) - 1;
    $temporal = '';
    for ($i = 0; $i < 10; $i++) {
        $temporal .= $alfabeto[random_int(0, $maximo)];
    }
    $database->update('usuarios', ['password' => password_hash($temporal, PASSWORD_DEFAULT)], filtroFamilia(['id' => $idUsuario]));
    return $temporal;
}

/**
 * Transfiere la administración a otro miembro de la familia: lo asciende a admin
 * y degrada a quien lo hace (el admin actual) a miembro. Solo un admin puede
 * llamarla. Devuelve true si se realizó.
 */
function transferirAdministracion(int $idNuevoAdmin): bool
{
    global $database;
    if ($idNuevoAdmin === idUsuarioActual()) {
        return false;
    }
    $nuevo = $database->get('usuarios', ['id', 'rol'], filtroFamilia(['id' => $idNuevoAdmin]));
    if (!$nuevo) {
        return false;
    }
    $database->update('usuarios', ['rol' => 'admin'], filtroFamilia(['id' => $idNuevoAdmin]));
    $database->update('usuarios', ['rol' => 'miembro'], filtroFamilia(['id' => idUsuarioActual()]));
    return true;
}

/**
 * El usuario en sesión abandona la familia (solo miembros: un admin debe
 * transferir antes). Borra su fila y sus datos personales (favoritos,
 * preferencias); los platillos e historial quedan en la familia.
 */
function abandonarFamilia(): bool
{
    global $database;
    if (usuarioEsAdmin()) {
        return false;
    }
    $id = idUsuarioActual();
    $database->delete('favoritos', ['id_usuario' => $id]);
    $database->delete('preferencias', ['id_usuario' => $id]);
    $resultado = $database->delete('usuarios', filtroFamilia(['id' => $id, 'rol' => 'miembro']));
    return $resultado->rowCount() > 0;
}

/**
 * Cambia la contraseña del usuario en sesión validando la actual.
 * Devuelve un mensaje de error, o null si el cambio fue correcto.
 */
function cambiarMiPassword(string $actual, string $nueva): ?string
{
    global $database;
    if (strlen($nueva) < 8) {
        return 'La nueva contraseña debe tener al menos 8 caracteres.';
    }
    if (strlen($nueva) > 72) {
        return 'La nueva contraseña es demasiado larga (máximo 72 caracteres).';
    }
    $usuario = $database->get('usuarios', ['password'], ['id' => idUsuarioActual()]);
    if (!$usuario || !password_verify($actual, $usuario['password'])) {
        return 'La contraseña actual no es correcta.';
    }
    $database->update('usuarios', ['password' => password_hash($nueva, PASSWORD_DEFAULT)], ['id' => idUsuarioActual()]);
    return null;
}

/**
 * Elimina por completo la familia en sesión y TODO lo que cuelga de ella
 * (platillos, recetas, historial, plan, despensa, compras, preferencias,
 * favoritos, etiquetas, votos y usuarios). Sin llaves foráneas: se limpia a
 * mano, dentro de una transacción. Acción destructiva e irreversible (solo admin).
 */
function eliminarFamilia(): void
{
    global $database;
    $idFamilia = idFamiliaActual();

    $database->action(function () use ($database, $idFamilia) {
        $idsUsuarios = array_map('intval', $database->select('usuarios', 'id', ['id_familia' => $idFamilia]));
        $idsPlatillos = array_map('intval', $database->select('platillos', 'id', ['id_familia' => $idFamilia]));

        if ($idsPlatillos !== []) {
            $database->delete('recetas', ['id_platillo' => $idsPlatillos]);
            $database->delete('platillos_etiquetas', ['id_platillo' => $idsPlatillos]);
        }
        if ($idsUsuarios !== []) {
            $database->delete('favoritos', ['id_usuario' => $idsUsuarios]);
            $database->delete('preferencias', ['id_usuario' => $idsUsuarios]);
        }
        $database->delete('votos', ['id_familia' => $idFamilia]);
        $database->delete('etiquetas', ['id_familia' => $idFamilia]);
        $database->delete('lista_compras', ['id_familia' => $idFamilia]);
        $database->delete('despensa', ['id_familia' => $idFamilia]);
        $database->delete('plan_semanal', ['id_familia' => $idFamilia]);
        $database->delete('historial_cocina', ['id_familia' => $idFamilia]);
        $database->delete('platillos', ['id_familia' => $idFamilia]);
        $database->delete('usuarios', ['id_familia' => $idFamilia]);
        $database->delete('familias', ['id' => $idFamilia]);
    });
}
