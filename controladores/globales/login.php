<?php

declare(strict_types=1);

/**
 * Autenticación: login, registro (crear familia o unirse por código) y salida.
 * Lo consume únicamente el index.php de la raíz.
 * Contraseñas con password_hash()/password_verify(); jamás en texto plano.
 */

require_once __DIR__ . '/funcionesPhp.php';
require_once dirname(__DIR__) . '/individuales/familia.php';

// ---------------------------------------------------------------------------
// Usuarios (pre-sesión: sin filtro de familia)
// ---------------------------------------------------------------------------

function usuarioPorCorreo(string $correo): ?array
{
    global $database;
    $fila = $database->get('usuarios', '*', ['correo' => strtolower(trim($correo))]);
    return $fila ?: null;
}

function correoExiste(string $correo): bool
{
    global $database;
    return $database->has('usuarios', ['correo' => strtolower(trim($correo))]);
}

/** Alta de usuario. La contraseña llega en claro y se hashea aquí. */
function crearUsuario(int $idFamilia, string $nombre, string $correo, string $password, string $rol): array
{
    global $database;
    $database->insert('usuarios', [
        'id_familia' => $idFamilia,
        'nombre' => $nombre,
        'correo' => strtolower(trim($correo)),
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'rol' => $rol,
        'activo' => 1,
    ]);
    return $database->get('usuarios', '*', ['id' => (int) $database->id()]);
}

/** Abre la sesión del usuario regenerando el id (evita fijación de sesión). */
function abrirSesionUsuario(array $usuario): void
{
    session_regenerate_id(true);
    $_SESSION['idusuario'] = (int) $usuario['id'];
    $_SESSION['idfamilia'] = (int) $usuario['id_familia'];
    $_SESSION['nombreusuario'] = $usuario['nombre'];
    $_SESSION['rolusuario'] = $usuario['rol'];
}

// ---------------------------------------------------------------------------
// Acciones del index.php
// ---------------------------------------------------------------------------

/** Procesa el POST de login. Redirige siempre. */
function procesarLogin(): never
{
    $correo = post('correo');
    $password = (string) ($_POST['password'] ?? '');

    if ($correo === '' || $password === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Correo o contraseña inválidos.');
        conservarFormulario();
        redirigir('/index.php');
    }

    // Anti fuerza bruta: máx. 6 intentos fallidos por IP+correo en 10 minutos.
    $claveTasa = 'login:' . ipCliente() . ':' . strtolower($correo);
    if (contarIntentos($claveTasa, 600) >= 6) {
        flash('error', 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.');
        conservarFormulario();
        registroLog('login', 'Bloqueo por exceso de intentos para el correo ' . strtolower($correo));
        redirigir('/index.php');
    }

    $usuario = usuarioPorCorreo($correo);
    if ($usuario === null || (int) ($usuario['activo'] ?? 1) !== 1
        || !password_verify($password, $usuario['password'])) {
        registrarIntento($claveTasa);
        flash('error', 'Credenciales incorrectas.');
        conservarFormulario();
        registroLog('login', 'Intento fallido para el correo ' . strtolower($correo));
        redirigir('/index.php');
    }

    abrirSesionUsuario($usuario);
    limpiarFormulario();
    registroLog('login', 'Inicio de sesión correcto');
    redirigir('/vistas/inicio/index.php');
}

/** Procesa el POST de registro (crear familia o unirse por código). */
function procesarRegistro(): never
{
    $nombre = post('nombre');
    $correo = post('correo');
    $password = (string) ($_POST['password'] ?? '');
    $modo = post('modo'); // 'crear' (nueva familia) | 'unirme' (por código)
    $nombreFamilia = post('nombre_familia');
    $codigoInvitacion = post('codigo_invitacion');

    // Anti-abuso: máx. 10 registros por IP en 1 hora (frena la fuerza bruta de
    // códigos de invitación creando cuentas en cadena).
    if (!controlarTasa('registro:' . ipCliente(), 10, 3600)) {
        flash('error', 'Demasiados registros desde esta red. Inténtalo más tarde.');
        redirigir('/index.php?pantalla=registro');
    }

    // --- Validación de entrada ---
    $errores = [];
    if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
        $errores[] = 'El nombre debe tener entre 2 y 120 caracteres.';
    }
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($correo) > 190) {
        $errores[] = 'El correo no es válido.';
    }
    if (strlen($password) < 8) {
        $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
    }
    // bcrypt (password_hash) trunca en 72 bytes: evitamos truncados silenciosos.
    if (strlen($password) > 72) {
        $errores[] = 'La contraseña es demasiado larga (máximo 72 caracteres).';
    }
    if (!in_array($modo, ['crear', 'unirme'], true)) {
        $errores[] = 'Elige crear una familia o unirte a una.';
    }
    if ($modo === 'crear' && (mb_strlen($nombreFamilia) < 2 || mb_strlen($nombreFamilia) > 120)) {
        $errores[] = 'El nombre de la familia debe tener entre 2 y 120 caracteres.';
    }

    if ($errores === [] && correoExiste($correo)) {
        $errores[] = 'Ese correo ya está registrado.';
    }

    $familia = null;
    if ($errores === [] && $modo === 'unirme') {
        $familia = familiaPorCodigo($codigoInvitacion);
        if ($familia === null) {
            $errores[] = 'El código de invitación no existe.';
        }
    }

    if ($errores !== []) {
        foreach ($errores as $error) {
            flash('error', $error);
        }
        conservarFormulario();
        redirigir('/index.php?pantalla=registro');
    }

    // --- Alta atómica: familia + usuario en una transacción. Si el segundo
    //     insert falla (p. ej. dos registros con el mismo correo a la vez), se
    //     revierte la familia y no queda basura huérfana. ---
    global $database;
    $rol = $modo === 'crear' ? 'admin' : 'miembro'; // quien crea la familia la administra
    $idFamilia = 0;
    $usuario = null;
    try {
        $database->action(function () use (&$usuario, &$idFamilia, $modo, $familia, $nombreFamilia, $nombre, $correo, $password, $rol) {
            $idFamilia = $modo === 'crear' ? crearFamilia($nombreFamilia) : (int) $familia['id'];
            $usuario = crearUsuario($idFamilia, $nombre, $correo, $password, $rol);
        });
    } catch (PDOException $excepcion) {
        // 1062/23000 = choca la unicidad del correo (carrera). Otro error: log + genérico.
        if (($excepcion->errorInfo[1] ?? 0) === 1062 || (string) $excepcion->getCode() === '23000') {
            flash('error', 'Ese correo ya está registrado.');
        } else {
            error_log('[mi_cocina/registro] ' . $excepcion->getMessage());
            flash('error', 'No se pudo completar el registro. Inténtalo de nuevo.');
        }
        conservarFormulario();
        redirigir('/index.php?pantalla=registro');
    }
    if ($usuario === null) {
        flash('error', 'No se pudo completar el registro. Inténtalo de nuevo.');
        conservarFormulario();
        redirigir('/index.php?pantalla=registro');
    }

    abrirSesionUsuario($usuario);
    limpiarFormulario();
    registroLog('registro', 'Nuevo usuario en familia ' . $idFamilia . ' con rol ' . $rol);
    flash('success', '¡Bienvenido a Mi Cocina, ' . $usuario['nombre'] . '!');
    redirigir('/vistas/inicio/index.php');
}
