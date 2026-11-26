<?php
// El administrador marca como atendida una solicitud de cambio, con una respuesta opcional para el usuario.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cambios.php';
require_once __DIR__ . '/../includes/signature.php';   // nowLocal()
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
requirePost();
requireCsrf();

if (!isAdminLoggedIn()) {
    jsonResponse(false, ['error' => 'No autorizado'], 401);
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$cambioId  = (int)($body['id'] ?? 0);
$region    = trim((string)($body['region'] ?? ''));
$respuesta = trim((string)($body['respuesta'] ?? ''));

if (!$cambioId || !$region) {
    jsonResponse(false, ['error' => 'Datos inválidos']);
}
$regions = getRegions();
if (!isset($regions[$region])) {
    jsonResponse(false, ['error' => 'Región inválida']);
}
if (textLength($respuesta) > CAMBIO_RESP_MAX) {
    jsonResponse(false, ['error' => 'La respuesta no puede exceder ' . CAMBIO_RESP_MAX . ' caracteres']);
}

$conn  = getConnection($region);
$admin = getCurrentAdmin();
$now   = nowLocal();

$stmt = $conn->prepare(
    "UPDATE cambios_solicitud
        SET estado = 'atendido', respuesta = NULLIF(?, ''), atendido_por = ?, atendido_at = ?
      WHERE id = ? AND estado = 'pendiente'"
);
$stmt->bind_param('sssi', $respuesta, $admin['nombre'], $now, $cambioId);

if (!$stmt->execute()) {
    error_log('[diagnosticos] resolve_change: ' . $conn->error);
    $stmt->close(); $conn->close();
    jsonResponse(false, ['error' => 'No se pudo actualizar la solicitud de cambio'], 500);
}
$ok = $stmt->affected_rows > 0;
$stmt->close();
$conn->close();

if (!$ok) {
    jsonResponse(false, ['error' => 'Ese cambio ya fue atendido o no existe']);
}

jsonResponse(true, ['message' => 'Cambio marcado como atendido']);
