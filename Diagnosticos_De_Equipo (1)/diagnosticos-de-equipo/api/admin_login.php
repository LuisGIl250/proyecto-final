<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ratelimit.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
requirePost();
requireCsrf();

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$nombre = trim((string)($body['nombre'] ?? ''));
$pass   = (string)($body['contrasena'] ?? '');

if (!$nombre || !$pass) {
    jsonResponse(false, ['error' => 'Nombre y contraseña son requeridos']);
}

rateLimitGuard('admin', $nombre);

$conn = getConnection(null); // db_sistema

$stmt = $conn->prepare("SELECT id, nombre, contrasena FROM admins WHERE nombre = ?");
$stmt->bind_param('s', $nombre);
$stmt->execute();
$result = $stmt->get_result();
$admin  = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$admin || !password_verify($pass, $admin['contrasena'])) {
    rateLimitFail('admin', $nombre);
    jsonResponse(false, ['error' => 'Credenciales de administrador incorrectas']);
}

rateLimitReset('admin', $nombre);
loginAdmin($admin);
jsonResponse(true, ['redirect' => '/diagnosticos-de-equipo/admin.php']);
