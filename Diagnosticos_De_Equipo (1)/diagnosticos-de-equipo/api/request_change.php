<?php
// El usuario solicita un cambio sobre una de sus solicitudes; el administrador lo verá en su panel.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cambios.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
requirePost();
requireCsrf();

if (!isUserLoggedIn()) {
    jsonResponse(false, ['error' => 'No autorizado'], 401);
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$id      = (int)($body['id'] ?? 0);
$mensaje = trim((string)($body['mensaje'] ?? ''));

if (!$id || $mensaje === '') {
    jsonResponse(false, ['error' => 'Describe el cambio que necesitas']);
}
if (textLength($mensaje) > CAMBIO_MSG_MAX) {
    jsonResponse(false, ['error' => 'El mensaje no puede exceder ' . CAMBIO_MSG_MAX . ' caracteres']);
}

$user   = getCurrentUser();
$region = $user['region'];
$uid    = (int)$user['id'];
$conn   = getConnection($region);

// Solo sobre solicitudes propias
$stmt = $conn->prepare("SELECT id FROM solicitudes WHERE id = ? AND usuario_id = ?");
$stmt->bind_param('ii', $id, $uid);
$stmt->execute();
$stmt->store_result();
$existe = $stmt->num_rows > 0;
$stmt->close();

if (!$existe) {
    $conn->close();
    jsonResponse(false, ['error' => 'Solicitud no encontrada'], 404);
}

// Una solicitud de cambio abierta a la vez
$stmt = $conn->prepare("SELECT id FROM cambios_solicitud WHERE solicitud_id = ? AND estado = 'pendiente' LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->store_result();
$abierta = $stmt->num_rows > 0;
$stmt->close();

if ($abierta) {
    $conn->close();
    jsonResponse(false, ['error' => 'Ya tienes un cambio en revisión para esta solicitud. Espera la respuesta del administrador.']);
}

$stmt = $conn->prepare("INSERT INTO cambios_solicitud (solicitud_id, usuario_id, mensaje) VALUES (?, ?, ?)");
$stmt->bind_param('iis', $id, $uid, $mensaje);

if (!$stmt->execute()) {
    error_log('[diagnosticos] request_change: ' . $conn->error);
    $stmt->close(); $conn->close();
    jsonResponse(false, ['error' => 'No se pudo enviar tu solicitud de cambio. Inténtalo más tarde.'], 500);
}

$stmt->close();
$conn->close();

jsonResponse(true, ['message' => 'Solicitud de cambio enviada']);
