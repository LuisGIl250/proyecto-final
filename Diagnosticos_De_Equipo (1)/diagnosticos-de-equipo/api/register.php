<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
requirePost();
requireCsrf();

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$nombre   = trim((string)($body['nombre']   ?? ''));
$apellido = trim((string)($body['apellido'] ?? ''));
$correo   = trim((string)($body['correo']   ?? ''));
$telefono = trim((string)($body['telefono'] ?? ''));
$region   = trim((string)($body['region']   ?? ''));
$pass     = (string)($body['contrasena']    ?? '');

if (!$nombre || !$apellido || !$correo || !$region || !$pass) {
    jsonResponse(false, ['error' => 'Todos los campos obligatorios deben completarse']);
}

if (!validateEmail($correo)) {
    jsonResponse(false, ['error' => 'Correo electrónico inválido']);
}

if (textLength($nombre) > 100 || textLength($apellido) > 100 || textLength($correo) > 150 || textLength($telefono) > 20) {
    jsonResponse(false, ['error' => 'Alguno de los campos excede la longitud permitida']);
}

if (strlen($pass) < 6) {
    jsonResponse(false, ['error' => 'La contraseña debe tener al menos 6 caracteres']);
}
if (strlen($pass) > 72) { // límite real de bcrypt
    jsonResponse(false, ['error' => 'La contraseña no puede exceder 72 caracteres']);
}

$regions = getRegions();
if (!isset($regions[$region])) {
    jsonResponse(false, ['error' => 'Región inválida']);
}

$conn = getConnection($region);

// Check duplicate email
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE correo = ?");
$stmt->bind_param('s', $correo);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close(); $conn->close();
    jsonResponse(false, ['error' => 'El correo ya está registrado en esta región']);
}
$stmt->close();

// Insert user
$hash = password_hash($pass, PASSWORD_BCRYPT);
$stmt = $conn->prepare("INSERT INTO usuarios (nombre, apellido, correo, telefono, contrasena) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param('sssss', $nombre, $apellido, $correo, $telefono, $hash);

if (!$stmt->execute()) {
    error_log('[diagnosticos] register: ' . $conn->error);
    $stmt->close(); $conn->close();
    jsonResponse(false, ['error' => 'No se pudo crear la cuenta. Inténtalo más tarde.'], 500);
}

$userId = $conn->insert_id;
$stmt->close();
$conn->close();

// Auto-login
$user = ['id' => $userId, 'nombre' => $nombre, 'apellido' => $apellido, 'correo' => $correo];
loginUser($user, $region, $regions[$region]['label']);
jsonResponse(true, ['redirect' => '/diagnosticos-de-equipo/solicitud.php']);
