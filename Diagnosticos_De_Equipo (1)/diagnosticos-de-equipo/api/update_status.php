<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/signature.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
requirePost();
requireCsrf();

if (!isAdminLoggedIn()) {
    jsonResponse(false, ['error' => 'No autorizado'], 401);
}

const DIAG_ADMIN_MAX = 800;   // caracteres; coincide con el límite de la interfaz y cabe en el PDF

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$id     = (int)($body['id'] ?? 0);
$estado = trim((string)($body['estado'] ?? ''));
$region = trim((string)($body['region'] ?? ''));
$diag   = trim((string)($body['diagnostico_admin'] ?? ''));

$allowed = ['pendiente', 'activa', 'completada'];
if (!$id || !in_array($estado, $allowed, true) || !$region) {
    jsonResponse(false, ['error' => 'Datos inválidos']);
}

$regions = getRegions();
if (!isset($regions[$region])) {
    jsonResponse(false, ['error' => 'Región inválida']);
}

// Para completar (o editar una ya completada) el diagnóstico del administrador es obligatorio
if ($estado === 'completada') {
    if ($diag === '') {
        jsonResponse(false, ['error' => 'Debes capturar el diagnóstico del equipo para completar la solicitud']);
    }
    if (textLength($diag) > DIAG_ADMIN_MAX) {
        jsonResponse(false, ['error' => 'El diagnóstico no puede exceder ' . DIAG_ADMIN_MAX . ' caracteres']);
    }
}

$conn = getConnection($region);

// Estado actual de la solicitud
$stmt = $conn->prepare("SELECT estado, diagnostico_admin, firma_usuario FROM solicitudes WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current) {
    $conn->close();
    jsonResponse(false, ['error' => 'Solicitud no encontrada']);
}

// No se puede completar una solicitud que ni siquiera se activó
if ($estado === 'completada' && $current['estado'] === 'pendiente') {
    $conn->close();
    jsonResponse(false, ['error' => 'Primero debes activar la solicitud']);
}

$firmaUsuarioBorrada = false;

if ($estado === 'completada') {
    $admin = getCurrentAdmin();
    $now   = nowLocal();

    // La firma del administrador se genera sola al completar (y se renueva al editar el diagnóstico)
    try {
        $firma = makeSignature('admin', [
            (int)$admin['id'], $admin['nombre'], $id, $region, $now, hash('sha256', $diag),
        ]);
    } catch (Throwable $e) {
        error_log('[diagnosticos] firma admin: ' . $e->getMessage());
        $conn->close();
        jsonResponse(false, ['error' => 'No se pudo generar la firma del administrador'], 500);
    }

    // Si el diagnóstico cambió, la firma que el usuario ya había puesto firmaba otro texto: se borra
    // y tendrá que firmar de nuevo.
    $diagCambio          = trim((string)$current['diagnostico_admin']) !== $diag;
    $firmaUsuarioBorrada = $diagCambio && !empty($current['firma_usuario']);

    $sql = "UPDATE solicitudes
               SET estado = 'completada',
                   diagnostico_admin = ?,
                   completada_por = ?,
                   completada_at = COALESCE(completada_at, ?),
                   firma_admin = ?,
                   firma_admin_at = ?"
         . ($firmaUsuarioBorrada ? ", firma_usuario = NULL, firma_usuario_nombre = NULL, firma_usuario_at = NULL" : "")
         . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssssi', $diag, $admin['nombre'], $now, $firma, $now, $id);
} elseif ($current['estado'] === 'completada') {
    // Sacar una solicitud de "completada" la reabre: las firmas dejan de valer
    $stmt = $conn->prepare(
        "UPDATE solicitudes
            SET estado = ?,
                firma_admin = NULL, firma_admin_at = NULL,
                firma_usuario = NULL, firma_usuario_nombre = NULL, firma_usuario_at = NULL
          WHERE id = ?"
    );
    $stmt->bind_param('si', $estado, $id);
} else {
    $stmt = $conn->prepare("UPDATE solicitudes SET estado = ? WHERE id = ?");
    $stmt->bind_param('si', $estado, $id);
}

if (!$stmt->execute()) {
    error_log('[diagnosticos] update_status: ' . $conn->error);
    $stmt->close(); $conn->close();
    jsonResponse(false, ['error' => 'No se pudo actualizar la solicitud'], 500);
}

$stmt->close();
$conn->close();

jsonResponse(true, [
    'message'              => 'Estado actualizado correctamente',
    'firma_usuario_borrada' => $firmaUsuarioBorrada,
]);
