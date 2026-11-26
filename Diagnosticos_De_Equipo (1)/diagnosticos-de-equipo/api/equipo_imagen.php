<?php
// Entrega la fotografía de un equipo. Solo la puede ver su dueño (usuario) o un administrador.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../config/db.php';

$id = (int)($_GET['id'] ?? 0);

if (isAdminLoggedIn()) {
    $region = trim((string)($_GET['region'] ?? ''));
    $uid    = null;                       // el administrador ve las de toda la región
} elseif (isUserLoggedIn()) {
    $u      = getCurrentUser();
    $region = $u['region'];               // el usuario solo ve las suyas, de su región
    $uid    = (int)$u['id'];
} else {
    http_response_code(401);
    exit;
}

// Ya no se necesita la sesión: liberarla evita que las miniaturas (varias peticiones a la vez) se encolen
session_write_close();

$regions = getRegions();
if (!$id || !isset($regions[$region])) {
    http_response_code(400);
    exit;
}

$conn = getConnection($region);
if ($uid === null) {
    $stmt = $conn->prepare("SELECT imagen FROM solicitudes WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
} else {
    $stmt = $conn->prepare("SELECT imagen FROM solicitudes WHERE id = ? AND usuario_id = ? LIMIT 1");
    $stmt->bind_param('ii', $id, $uid);
}
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

$path = $row ? equipoImagePath($region, $row['imagen']) : null;
if ($path === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="equipo_' . $id . '.jpg"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
