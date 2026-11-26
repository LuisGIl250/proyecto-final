<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
requirePost();
requireCsrf();

if (!isUserLoggedIn()) {
    jsonResponse(false, ['error' => 'No autorizado'], 401);
}

// El formulario se envía como multipart/form-data (lleva la fotografía): los campos llegan en $_POST.
// Si el envío superó post_max_size, PHP descarta todo y $_POST/$_FILES llegan vacíos.
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
    if ($contentLength > iniToBytes(ini_get('post_max_size'))) {
        jsonResponse(false, ['error' => imageTooHeavyMessage()]);
    }
    // El envío cabía, pero no llegó como formulario: casi siempre es un main.js viejo guardado en caché
    jsonResponse(false, ['error' => 'No se recibieron los datos del formulario. Recarga la página con Ctrl + F5 e inténtalo de nuevo.']);
}
$body = $_POST;
$user = getCurrentUser();

// La región SIEMPRE sale de la sesión: un usuario solo puede crear solicitudes en su propia región.
$region = $user['region'];

$nombre_sol  = trim((string)($body['nombre_solicitante'] ?? ''));
$centro      = trim((string)($body['centro_trabajo']     ?? ''));
$tipo        = trim((string)($body['tipo_equipo']        ?? ''));
$marca       = trim((string)($body['marca']              ?? ''));
$modelo      = trim((string)($body['modelo']             ?? ''));
$serie       = trim((string)($body['numero_serie']       ?? ''));
$inventario  = trim((string)($body['numero_inventario']  ?? ''));
$lugar       = trim((string)($body['lugar']              ?? ''));
$fecha       = trim((string)($body['fecha']              ?? ''));
$diagnostico = trim((string)($body['diagnostico']        ?? ''));

if (!$nombre_sol || !$centro || !$tipo || !$marca || !$modelo) {
    jsonResponse(false, ['error' => 'Los campos marcados con * son obligatorios']);
}

// Longitudes máximas según las columnas de la tabla
$limits = [
    [$nombre_sol, 200], [$centro, 200], [$tipo, 100], [$marca, 100], [$modelo, 100],
    [$serie, 100], [$inventario, 100], [$lugar, 150], [$diagnostico, 5000],
];
foreach ($limits as [$val, $max]) {
    if (textLength($val) > $max) {
        jsonResponse(false, ['error' => 'Alguno de los campos excede la longitud permitida']);
    }
}

// Fecha válida (YYYY-MM-DD); si viene vacía o inválida se usa la de hoy
$d = DateTime::createFromFormat('Y-m-d', $fecha);
$fecha = ($d && $d->format('Y-m-d') === $fecha) ? $fecha : date('Y-m-d');

$regions = getRegions();
if (!isset($regions[$region])) {
    jsonResponse(false, ['error' => 'Región inválida']);
}

// La fotografía del equipo es obligatoria: se valida y se guarda ANTES de insertar la solicitud
try {
    $imagen = saveEquipoImage($_FILES['imagen'] ?? null, $region);
} catch (InvalidArgumentException $e) {
    jsonResponse(false, ['error' => $e->getMessage()]);
} catch (ImageServerException $e) {
    // Problema del servidor: el detalle (con rutas) va al log y al usuario solo se le da la pista
    error_log('[diagnosticos] save_solicitud imagen: ' . $e->getMessage());
    jsonResponse(false, ['error' => 'No se pudo guardar la fotografía: ' . $e->getHint() . '. Avisa al administrador del sistema.'], 500);
} catch (Throwable $e) {
    error_log('[diagnosticos] save_solicitud imagen: ' . get_class($e) . ': ' . $e->getMessage());
    jsonResponse(false, ['error' => 'No se pudo guardar la fotografía por un error inesperado del servidor. Avisa al administrador del sistema.'], 500);
}

$conn = getConnection($region);

$stmt = $conn->prepare(
    "INSERT INTO solicitudes
     (usuario_id, nombre_solicitante, centro_trabajo, tipo_equipo, marca, modelo,
      numero_serie, numero_inventario, lugar, fecha, diagnostico, region, imagen, estado)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')"
);

$uid = (int)$user['id'];
$stmt->bind_param(
    'i' . str_repeat('s', 12),
    $uid,
    $nombre_sol, $centro, $tipo, $marca, $modelo,
    $serie, $inventario, $lugar, $fecha, $diagnostico, $region, $imagen
);

if (!$stmt->execute()) {
    error_log('[diagnosticos] save_solicitud: ' . $conn->error);
    $stmt->close();
    $conn->close();
    deleteEquipoImage($region, $imagen);   // no dejar archivos huérfanos si la solicitud no se guardó
    jsonResponse(false, ['error' => 'No se pudo guardar la solicitud. Inténtalo más tarde.'], 500);
}

$insertId = $conn->insert_id;
$stmt->close();
$conn->close();

jsonResponse(true, ['id' => $insertId, 'message' => 'Solicitud enviada correctamente']);
