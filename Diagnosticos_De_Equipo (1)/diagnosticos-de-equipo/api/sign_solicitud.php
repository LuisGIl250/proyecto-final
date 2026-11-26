<?php
// El usuario firma de conformidad el diagnóstico y la entrega del equipo de una de sus solicitudes.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/signature.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
requirePost();
requireCsrf();

if (!isUserLoggedIn()) {
    jsonResponse(false, ['error' => 'No autorizado'], 401);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = (int)($body['id'] ?? 0);
if (!$id) {
    jsonResponse(false, ['error' => 'Datos inválidos']);
}

$user   = getCurrentUser();
$region = $user['region'];          // la región siempre sale de la sesión
$uid    = (int)$user['id'];
$conn   = getConnection($region);

// Solo se puede firmar una solicitud propia
$stmt = $conn->prepare("SELECT estado, diagnostico_admin, firma_usuario FROM solicitudes WHERE id = ? AND usuario_id = ?");
$stmt->bind_param('ii', $id, $uid);
$stmt->execute();
$sol = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sol) {
    $conn->close();
    jsonResponse(false, ['error' => 'Solicitud no encontrada'], 404);
}
$diag = trim((string)($sol['diagnostico_admin'] ?? ''));
if ($sol['estado'] !== 'completada' || $diag === '') {
    $conn->close();
    jsonResponse(false, ['error' => 'Solo puedes firmar cuando el especialista complete tu solicitud']);
}
if (!empty($sol['firma_usuario'])) {
    $conn->close();
    jsonResponse(false, ['error' => 'Esta solicitud ya está firmada']);
}

// Datos de la cuenta con los que se genera la firma
$stmt = $conn->prepare("SELECT nombre, apellido, telefono FROM usuarios WHERE id = ?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$acc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$acc) {
    $conn->close();
    jsonResponse(false, ['error' => 'No se encontró tu cuenta'], 404);
}

$nombre = trim($acc['nombre'] . ' ' . $acc['apellido']);
$now    = nowLocal();

try {
    $firma = makeSignature('usuario', [
        $uid, $nombre, normalizePhone($acc['telefono']), $id, $region, $now, hash('sha256', $diag),
    ]);
} catch (Throwable $e) {
    error_log('[diagnosticos] firma usuario: ' . $e->getMessage());
    $conn->close();
    jsonResponse(false, ['error' => 'No se pudo generar tu firma'], 500);
}

// "firma_usuario IS NULL" evita que un doble clic o dos pestañas firmen dos veces
$stmt = $conn->prepare(
    "UPDATE solicitudes
        SET firma_usuario = ?, firma_usuario_nombre = ?, firma_usuario_at = ?
      WHERE id = ? AND usuario_id = ? AND estado = 'completada' AND firma_usuario IS NULL"
);
$stmt->bind_param('sssii', $firma, $nombre, $now, $id, $uid);

if (!$stmt->execute()) {
    error_log('[diagnosticos] sign_solicitud: ' . $conn->error);
    $stmt->close(); $conn->close();
    jsonResponse(false, ['error' => 'No se pudo guardar tu firma. Inténtalo más tarde.'], 500);
}
$firmada = $stmt->affected_rows > 0;
$stmt->close();
$conn->close();

if (!$firmada) {
    jsonResponse(false, ['error' => 'Esta solicitud ya está firmada']);
}

jsonResponse(true, ['message' => 'Solicitud firmada correctamente', 'firma' => formatSignature($firma)]);
