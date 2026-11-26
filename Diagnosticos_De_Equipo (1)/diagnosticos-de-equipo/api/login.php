<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ratelimit.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
requirePost();
requireCsrf();

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$region = trim((string)($body['region'] ?? ''));
$correo = trim((string)($body['correo'] ?? ''));
$pass   = (string)($body['contrasena'] ?? '');

if (!$region || !$correo || !$pass) {
    jsonResponse(false, ['error' => 'Todos los campos son requeridos']);
}

$regions = getRegions();
if (!isset($regions[$region])) {
    jsonResponse(false, ['error' => 'Región inválida']);
}

// La cuenta se identifica por región + correo (el mismo correo puede existir en varias regiones)
$rlId = $region . '|' . $correo;
rateLimitGuard('user', $rlId);

$conn = getConnection($region);

$stmt = $conn->prepare("SELECT id, nombre, apellido, correo, contrasena FROM usuarios WHERE correo = ?");
$stmt->bind_param('s', $correo);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$user || !password_verify($pass, $user['contrasena'])) {
    rateLimitFail('user', $rlId);
    jsonResponse(false, ['error' => 'Correo o contraseña incorrectos']);
}

rateLimitReset('user', $rlId);
loginUser($user, $region, $regions[$region]['label']);
jsonResponse(true, ['redirect' => '/diagnosticos-de-equipo/solicitud.php']);
