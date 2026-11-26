<?php
/**
 * Fotografías de los equipos.
 *
 * · Se guardan en uploads/<región>/<32 hex>.jpg (o en UPLOADS_DIR, si se define en config/db.local.php).
 *   La carpeta tiene "Require all denied": nadie las abre por URL directa; se entregan con
 *   api/equipo_imagen.php, que comprueba que quien pide sea el dueño o un administrador.
 * · Cada archivo recibido se valida (tamaño, tipo real y dimensiones) y, si GD está disponible, se
 *   RE-CODIFICA a JPEG de hasta 1600 px: lo que queda en disco es siempre una imagen limpia (sin
 *   metadatos ni contenido escondido) y FPDF puede insertarla sin problemas en el PDF.
 *   Sin GD, solo se acepta un JPEG ya validado (el navegador siempre lo envía así) y se guarda tal cual.
 * · Los fallos del servidor (permisos, GD, carpeta temporal) lanzan ImageServerException con una pista
 *   corta y sin rutas, que se muestra al usuario; el detalle completo va al registro de errores de PHP.
 */
require_once __DIR__ . '/../config/db.php';

const IMG_MAX_BYTES    = 5 * 1024 * 1024;   // peso máximo del archivo subido
const IMG_MAX_PIXELS   = 16000000;          // 16 megapíxeles: protege la memoria de GD al decodificar
const IMG_MAX_SIDE     = 1600;              // lado mayor de la imagen guardada (px)
const IMG_JPEG_QUALITY = 85;

/** Problema del servidor (no de la imagen): $detail va al log; $hint, sin rutas, se le muestra al usuario. */
class ImageServerException extends RuntimeException
{
    public function __construct(string $detail, private string $hint = '')
    {
        parent::__construct($detail);
    }

    public function getHint(): string
    {
        return $this->hint;
    }
}

/** "2M", "512K", "1G" o "8388608" (formato de php.ini) → bytes. 0 o -1 significan "sin límite". */
function iniToBytes($value): int
{
    $v = trim((string)$value);
    if ($v === '' || $v === '0' || $v === '-1') return PHP_INT_MAX;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;   // sin break: sigue multiplicando
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

/** Peso máximo real que acepta este servidor para la fotografía (el menor entre nuestro límite y los de php.ini). */
function imageUploadLimitBytes(): int
{
    return min(IMG_MAX_BYTES, iniToBytes(ini_get('upload_max_filesize')), iniToBytes(ini_get('post_max_size')));
}

/** Mensaje con el límite que de verdad aplica, por ejemplo "La imagen es demasiado pesada (máximo 2 MB)". */
function imageTooHeavyMessage(): string
{
    $mb = imageUploadLimitBytes() / 1048576;
    return 'La imagen es demasiado pesada (máximo ' . rtrim(rtrim(number_format($mb, 1), '0'), '.') . ' MB)';
}

/** Carpeta base de las fotografías: UPLOADS_DIR (config/db.local.php) o uploads/ junto al proyecto. */
function uploadsBaseDir(): string
{
    if (defined('UPLOADS_DIR') && UPLOADS_DIR !== '') {
        return rtrim((string)UPLOADS_DIR, '/\\');
    }
    return dirname(__DIR__) . '/uploads';
}

/** Carpeta de las fotografías de una región (la región solo puede ser letras minúsculas, números y guion bajo). */
function uploadsDir(string $region): string
{
    if (!preg_match('/^[a-z0-9_]+$/', $region)) {
        throw new RuntimeException('Región inválida para uploads');
    }
    return uploadsBaseDir() . '/' . $region;
}

/** Crea (si hace falta) y comprueba que se pueda escribir en la carpeta de la región. Devuelve su ruta. */
function ensureUploadsDir(string $region): string
{
    $dir = uploadsDir($region);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        clearstatcache(true, $dir);
    }
    if (!is_dir($dir)) {
        throw new ImageServerException(
            "No se pudo crear la carpeta $dir",
            'el servidor web no puede crear la carpeta uploads/ (faltan permisos de escritura)'
        );
    }
    if (!is_writable($dir)) {
        throw new ImageServerException(
            "La carpeta $dir no es escribible",
            'la carpeta uploads/ no tiene permisos de escritura para el servidor web'
        );
    }
    return $dir;
}

/**
 * Valida y guarda la fotografía recibida en $_FILES['imagen'].
 * Devuelve el nombre del archivo guardado.
 * Lanza InvalidArgumentException con un mensaje apto para el usuario si la imagen no sirve,
 * e ImageServerException si el problema es del servidor.
 */
function saveEquipoImage(?array $file, string $region): string
{
    if (!$file || !isset($file['error'])) {
        throw new InvalidArgumentException('Debes subir una fotografía del equipo');
    }
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            throw new InvalidArgumentException('Debes subir una fotografía del equipo');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new InvalidArgumentException(imageTooHeavyMessage());
        case UPLOAD_ERR_PARTIAL:
            throw new InvalidArgumentException('La imagen se subió incompleta. Inténtalo de nuevo');
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            throw new ImageServerException(
                'Error de subida de PHP: ' . $file['error'],
                'PHP no puede escribir en su carpeta temporal de subidas (upload_tmp_dir)'
            );
        default:
            throw new ImageServerException('Error de subida de PHP: ' . $file['error'], 'PHP rechazó la subida del archivo');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Archivo no válido');
    }
    if ($file['size'] <= 0) {
        throw new InvalidArgumentException('El archivo está vacío. Elige otra imagen');
    }
    if ($file['size'] > IMG_MAX_BYTES) {
        throw new InvalidArgumentException(imageTooHeavyMessage());
    }

    // El tipo se determina por el contenido, no por el nombre ni por lo que declare el navegador
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new InvalidArgumentException('La fotografía debe ser una imagen JPG, PNG o WebP');
    }
    [$w, $h] = $info;
    if ($w < 1 || $h < 1 || $w * $h > IMG_MAX_PIXELS) {
        throw new InvalidArgumentException('La imagen es demasiado grande (máximo 16 megapíxeles)');
    }

    // Se comprueba la carpeta antes de gastar memoria procesando la imagen
    $dir  = ensureUploadsDir($region);
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $path = $dir . '/' . $name;

    // ── Sin GD: solo se acepta un JPEG y se guarda tal cual ──
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        if ($info[2] !== IMAGETYPE_JPEG) {
            throw new ImageServerException(
                'GD no está activa y la imagen no es JPEG',
                'al servidor le falta la extensión GD de PHP, así que solo puede recibir imágenes JPG'
            );
        }
        if (!@move_uploaded_file($file['tmp_name'], $path)) {
            throw new ImageServerException("No se pudo mover el archivo a $path", 'no se pudo escribir el archivo en uploads/');
        }
        @chmod($path, 0644);
        return $name;
    }

    // ── Con GD: se decodifica y se vuelve a guardar como JPEG limpio y de tamaño razonable ──
    $data = @file_get_contents($file['tmp_name']);
    $src  = ($data === false) ? false : @imagecreatefromstring($data);
    unset($data);
    if (!$src) {
        throw new InvalidArgumentException('No se pudo leer la imagen. Prueba con otra fotografía');
    }

    // Reducir si es más grande que IMG_MAX_SIDE; el fondo blanco cubre las transparencias de PNG/WebP
    $scale = min(1, IMG_MAX_SIDE / max($w, $h));
    $nw    = max(1, (int)round($w * $scale));
    $nh    = max(1, (int)round($h * $scale));
    $dst   = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $ok = @imagejpeg($dst, $path, IMG_JPEG_QUALITY);
    imagedestroy($dst);
    if (!$ok) {
        throw new ImageServerException("No se pudo escribir $path", 'no se pudo escribir el archivo en uploads/ (permisos o disco lleno)');
    }
    @chmod($path, 0644);
    return $name;
}

/** Ruta absoluta de la fotografía si el nombre es válido y el archivo existe; si no, null. */
function equipoImagePath(string $region, ?string $name): ?string
{
    if ($name === null || !preg_match('/^[a-f0-9]{32}\.jpg$/', $name)) return null;
    $path = uploadsDir($region) . '/' . $name;
    return is_file($path) ? $path : null;
}

/** Borra una fotografía (por ejemplo, si el INSERT de la solicitud falló después de guardarla). */
function deleteEquipoImage(string $region, ?string $name): void
{
    $path = equipoImagePath($region, $name);
    if ($path !== null) @unlink($path);
}
