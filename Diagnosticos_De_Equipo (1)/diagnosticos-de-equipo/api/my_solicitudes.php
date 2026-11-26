<?php
// Solicitudes del usuario que inició sesión (solo las suyas, solo de su región),
// con el diagnóstico del administrador, las firmas y sus solicitudes de cambio.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/signature.php';
require_once __DIR__ . '/../includes/cambios.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isUserLoggedIn()) {
    jsonResponse(false, ['error' => 'No autorizado'], 401);
}

$user = getCurrentUser();
$conn = getConnection($user['region']);

$uid  = (int)$user['id'];
$stmt = $conn->prepare(
    "SELECT id, tipo_equipo, marca, modelo, centro_trabajo, numero_serie, numero_inventario,
            fecha, estado, created_at,
            diagnostico_admin, completada_por, completada_at,
            firma_admin, firma_admin_at, firma_usuario, firma_usuario_nombre, firma_usuario_at,
            imagen
     FROM solicitudes
     WHERE usuario_id = ?
     ORDER BY created_at DESC
     LIMIT 100"
);
$stmt->bind_param('i', $uid);

if (!$stmt->execute()) {
    error_log('[diagnosticos] my_solicitudes: ' . $conn->error);
    $stmt->close(); $conn->close();
    jsonResponse(false, ['error' => 'No se pudieron consultar tus solicitudes'], 500);
}

$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$cambios = loadCambios($conn, array_column($rows, 'id'));
$conn->close();

foreach ($rows as &$row) {
    $row = exposeSignatures($row);
    $row['tiene_imagen'] = !empty($row['imagen']);
    unset($row['imagen']);
    $row['cambios'] = $cambios[(int)$row['id']] ?? [];
}
unset($row);

jsonResponse(true, [
    'solicitudes' => $rows,
    'usuario'     => ['nombre' => trim($user['nombre'] . ' ' . $user['apellido'])],
]);
