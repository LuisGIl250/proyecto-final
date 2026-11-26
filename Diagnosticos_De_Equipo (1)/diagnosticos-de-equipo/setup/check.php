<?php
/**
 * Diagnóstico del entorno para la subida de fotografías, el PDF y las firmas.
 * Solo se abre desde localhost (http://localhost/diagnosticos-de-equipo/setup/check.php).
 */
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Esta página solo está disponible desde localhost.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/uploads.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$checks = [];   // [titulo, estado (true|false|null = aviso), detalle]
$add = function (string $title, ?bool $ok, string $detail) use (&$checks) { $checks[] = [$title, $ok, $detail]; };

// ── PHP y usuario del servidor web ──
$add('Versión de PHP', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION . ' (se necesita 8.0 o superior)');
$who = '(desconocido)';
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $pw  = posix_getpwuid(posix_geteuid());
    $who = $pw['name'] ?? $who;
}
$add('Usuario con el que corre PHP', null, $who . ' — es quien necesita permiso de escritura en las carpetas de abajo');

// ── GD ──
$gd = function_exists('imagecreatefromstring') && function_exists('imagejpeg');
if ($gd) {
    $i = function_exists('gd_info') ? gd_info() : [];
    $add('Extensión GD', true, 'activa · JPEG: ' . (!empty($i['JPEG Support']) ? 'sí' : 'no')
        . ' · PNG: ' . (!empty($i['PNG Support']) ? 'sí' : 'no')
        . ' · WebP: ' . (!empty($i['WebP Support']) ? 'sí' : 'no'));
} else {
    $add('Extensión GD', null, 'NO está activa. Las fotos JPG se guardan igual, pero PNG/WebP no se pueden procesar. Activa extension=gd en php.ini.');
}

// ── Límites de subida ──
$add('file_uploads', (bool)ini_get('file_uploads'), ini_get('file_uploads') ? 'activado' : 'desactivado en php.ini');
$add('Límites de php.ini', null, 'upload_max_filesize = ' . ini_get('upload_max_filesize') . ' · post_max_size = ' . ini_get('post_max_size')
    . ' → peso máximo efectivo de la foto: ' . round(imageUploadLimitBytes() / 1048576, 1) . ' MB');
$tmp = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
$add('Carpeta temporal de subidas', is_dir($tmp) && is_writable($tmp), $tmp);

// ── Carpeta de fotografías ──
$base = uploadsBaseDir();
$add('Carpeta de fotografías', is_dir($base) && is_writable($base), $base . (is_dir($base) ? '' : ' (no existe)'));
$fails = [];
foreach (getRegions() as $key => $info) {
    try {
        $dir  = ensureUploadsDir($key);
        $test = $dir . '/.check_' . bin2hex(random_bytes(4));
        if (@file_put_contents($test, 'ok') === false) throw new ImageServerException("No se pudo escribir en $dir", 'sin permiso de escritura');
        @unlink($test);
    } catch (ImageServerException $e) {
        $fails[] = $key;
    }
}
$add('Escritura por región', !$fails, $fails ? 'Falla en: ' . implode(', ', $fails) : 'las ' . count(getRegions()) . ' carpetas se pueden crear y escribir');

// ── Llave de las firmas ──
$cfg = dirname(__DIR__) . '/config';
$key = $cfg . '/signature.key';
$keyOk = is_file($key) ? is_readable($key) : is_writable($cfg);
$add('Llave de firmas (config/signature.key)', $keyOk, is_file($key) ? 'existe y se puede leer' : ($keyOk ? 'aún no existe; se creará sola al firmar' : 'no existe y config/ no es escribible: no se podrá firmar'));

$bad = array_filter($checks, fn($c) => $c[1] === false);
?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Diagnóstico del entorno</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0a0a14;color:#e0e0f0;max-width:880px;margin:40px auto;padding:0 20px}
  h1{color:#00e5ff;font-size:1.4rem} table{width:100%;border-collapse:collapse}
  td{padding:10px 12px;border-bottom:1px solid #222244;vertical-align:top} td:first-child{white-space:nowrap;font-weight:600}
  .ok{color:#76ff03}.no{color:#ff1744}.warn{color:#ffea00} code{background:#161630;padding:2px 6px;border-radius:4px}
  .box{margin-top:24px;padding:14px 18px;border-radius:8px;background:#161630}
</style></head><body>
<h1>Diagnóstico del entorno</h1>
<table>
<?php foreach ($checks as [$title, $ok, $detail]): ?>
  <tr>
    <td class="<?= $ok === true ? 'ok' : ($ok === false ? 'no' : 'warn') ?>"><?= $ok === true ? '✔' : ($ok === false ? '✖' : '⚠') ?> <?= h($title) ?></td>
    <td><?= h($detail) ?></td>
  </tr>
<?php endforeach; ?>
</table>

<?php if ($bad): ?>
<div class="box">
  <b>Cómo corregir los permisos</b> (macOS / Linux): en una terminal ejecuta<br><br>
  <code>sudo chown -R <?= h($who) ?> "<?= h($base) ?>"</code><br><br>
  o, más simple para una máquina de pruebas:<br><br>
  <code>sudo chmod -R 777 "<?= h($base) ?>"</code><br><br>
  En Windows con XAMPP normalmente no hace falta nada; si falla, revisa que la carpeta no sea de solo lectura.
  También puedes apuntar las fotografías a otra carpeta con <code>define('UPLOADS_DIR', '...')</code> en <code>config/db.local.php</code>.
</div>
<?php else: ?>
<div class="box ok">Todo en orden: la subida de fotografías debería funcionar.</div>
<?php endif; ?>
</body></html>
