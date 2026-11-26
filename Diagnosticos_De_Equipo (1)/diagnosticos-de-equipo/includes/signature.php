<?php
/**
 * Firmas del sistema.
 *
 * Una "firma" es un HMAC-SHA256 calculado con una llave secreta del servidor sobre los datos
 * de la cuenta de quien firma, la solicitud, el momento de la firma y el diagnóstico:
 *   · Administrador: id y nombre de su cuenta.
 *   · Usuario:       id, nombre completo y teléfono de su cuenta (solo dígitos; si no tiene, queda vacío).
 *
 * Se usa HMAC con llave y no un hash simple del teléfono o del nombre porque un hash simple se puede
 * recalcular (un teléfono tiene pocas combinaciones posibles y un nombre es público) y cualquiera
 * podría fabricar una firma igual. Con la llave del servidor, la firma no se puede adivinar ni falsificar
 * desde fuera, y el teléfono no se puede deducir del código impreso en el PDF.
 *
 * En la base se guarda el HMAC completo (64 caracteres hex); en pantalla y en el PDF se muestran
 * sus primeros 16 caracteres con el formato 3F9A-C10B-77D2-48E5.
 */
require_once __DIR__ . '/../config/db.php';

// Zona horaria con la que se estampan las firmas (se puede sobrescribir en config/db.local.php
// con define('APP_TIMEZONE', '...') o con la variable de entorno APP_TIMEZONE).
defined('APP_TIMEZONE') || define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: 'America/Mexico_City');

/** Fecha y hora actuales ('Y-m-d H:i:s') en la zona horaria de la aplicación. */
function nowLocal(): string
{
    return (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d H:i:s');
}

/**
 * Llave secreta de las firmas. Orden de búsqueda:
 *   1. constante SIGNATURE_SECRET (config/db.local.php)
 *   2. variable de entorno SIGNATURE_SECRET
 *   3. archivo config/signature.key (se crea solo la primera vez; config/ está protegida por .htaccess)
 * Lanza RuntimeException si no puede obtenerla (nunca firma con una llave por defecto).
 */
function signatureSecret(): string
{
    static $secret = null;
    if ($secret !== null) return $secret;

    if (defined('SIGNATURE_SECRET') && SIGNATURE_SECRET !== '') {
        return $secret = (string)SIGNATURE_SECRET;
    }
    $env = getenv('SIGNATURE_SECRET');
    if (is_string($env) && $env !== '') {
        return $secret = $env;
    }

    $file = __DIR__ . '/../config/signature.key';
    if (!is_file($file)) {
        // Modo 'x': solo uno de dos procesos simultáneos puede crear el archivo
        $fh = @fopen($file, 'x');
        if ($fh !== false) {
            fwrite($fh, bin2hex(random_bytes(32)) . "\n");
            fclose($fh);
            @chmod($file, 0600);
        }
    }
    $s = is_file($file) ? trim((string)@file_get_contents($file)) : '';
    if ($s === '') {
        throw new RuntimeException('No se pudo leer ni crear config/signature.key (revisa los permisos de config/)');
    }
    return $secret = $s;
}

/**
 * Calcula una firma. $role distingue la firma del administrador de la del usuario;
 * $parts son los datos que se firman (el orden importa).
 */
function makeSignature(string $role, array $parts): string
{
    $payload = $role . "\x1f" . implode("\x1f", array_map('strval', $parts));
    return hash_hmac('sha256', $payload, signatureSecret());
}

/** 64 caracteres hex → "3F9A-C10B-77D2-48E5" ('' si no hay firma). */
function formatSignature(?string $hex): string
{
    if ($hex === null || $hex === '') return '';
    return implode('-', str_split(strtoupper(substr($hex, 0, 16)), 4));
}

/** 'Y-m-d H:i:s' → 'd/m/Y H:i' ('' si no hay fecha). La hora se muestra tal cual se guardó. */
function formatSignatureDate(?string $dt): string
{
    if ($dt === null || $dt === '') return '';
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $dt);
    return $d ? $d->format('d/m/Y H:i') : '';
}

/** Teléfono de la cuenta reducido a dígitos (así "312 000 0000" y "3120000000" firman igual). */
function normalizePhone(?string $phone): string
{
    return preg_replace('/\D+/', '', (string)$phone);
}

/**
 * Prepara una fila de `solicitudes` para enviarla al navegador: el HMAC completo nunca sale del
 * servidor; solo se envían los códigos con formato.
 */
function exposeSignatures(array $row): array
{
    $row['firma_admin_code']   = formatSignature($row['firma_admin']   ?? null);
    $row['firma_usuario_code'] = formatSignature($row['firma_usuario'] ?? null);
    unset($row['firma_admin'], $row['firma_usuario']);
    return $row;
}
