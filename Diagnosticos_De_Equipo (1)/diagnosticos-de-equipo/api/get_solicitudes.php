<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/signature.php';
require_once __DIR__ . '/../includes/cambios.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdminLoggedIn()) {
    jsonResponse(false, ['error' => 'No autorizado'], 401);
}

$region = trim((string)($_GET['region'] ?? ''));

if (!$region) {
    jsonResponse(false, ['error' => 'Región requerida']);
}

$regions = getRegions();
if (!isset($regions[$region])) {
    jsonResponse(false, ['error' => 'Región inválida']);
}

$conn = getConnection($region);

$result = $conn->query(
    "SELECT id, usuario_id, nombre_solicitante, centro_trabajo, tipo_equipo, marca, modelo,
            numero_serie, numero_inventario, lugar, fecha, diagnostico, diagnostico_admin,
            completada_por, completada_at, estado, created_at,
            firma_admin, firma_admin_at, firma_usuario, firma_usuario_nombre, firma_usuario_at,
            imagen
     FROM solicitudes
     ORDER BY created_at DESC"
);

if (!$result) {
    error_log('[diagnosticos] get_solicitudes: ' . $conn->error);
    $conn->close();
    jsonResponse(false, ['error' => 'No se pudieron consultar las solicitudes'], 500);
}

$solicitudes = [];
$stats = ['pendiente' => 0, 'activa' => 0, 'completada' => 0];

while ($row = $result->fetch_assoc()) {
    $solicitudes[] = $row;
    if (isset($stats[$row['estado']])) $stats[$row['estado']]++;
}
$result->free();

$cambios = loadCambios($conn, array_column($solicitudes, 'id'));
$conn->close();

foreach ($solicitudes as &$sol) {
    $sol = exposeSignatures($sol);
    $sol['tiene_imagen'] = !empty($sol['imagen']);
    unset($sol['imagen']);
    $sol['cambios'] = $cambios[(int)$sol['id']] ?? [];
}
unset($sol);

jsonResponse(true, [
    'solicitudes' => $solicitudes,
    'stats'       => $stats,
    'region'      => $region,
    'label'       => $regions[$region]['label'],
]);
