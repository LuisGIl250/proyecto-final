<?php
require_once __DIR__ . '/../config/db.php';

// ── Seguridad: el instalador solo puede ejecutarse desde el propio servidor ──
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Instalador disponible únicamente desde localhost.');
}

// ── Seguridad: una vez instalado se bloquea. Para reinstalar, borra setup/.installed ──
$lockFile = __DIR__ . '/.installed';
if (is_file($lockFile)) {
    http_response_code(403);
    exit('El sistema ya fue instalado. Para ejecutar el instalador de nuevo elimina el archivo setup/.installed');
}

// Connect without selecting a database first
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($conn->connect_error) {
    error_log('[diagnosticos] install: ' . $conn->connect_error);
    die('<p style="color:red">❌ No se pudo conectar a MySQL. Verifica que XAMPP/MySQL esté corriendo y las credenciales en config/.</p>');
}
$conn->set_charset('utf8mb4');

$log = [];
$errors = [];
$newAdmins = []; // contraseñas generadas (se muestran UNA sola vez)

// ─────────────────────────────────────────────
// 1. Create central sistema database + admins
// ─────────────────────────────────────────────
if ($conn->query("CREATE DATABASE IF NOT EXISTS `db_sistema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    $log[] = "✓ Base de datos <b>db_sistema</b> lista";
} else {
    error_log("[diagnosticos] install db_sistema: " . $conn->error);
    $errors[] = "✗ No se pudo crear db_sistema (ver log de errores)";
}

$conn->select_db('db_sistema');

$conn->query("CREATE TABLE IF NOT EXISTS `admins` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `nombre`     VARCHAR(200) NOT NULL,
    `contrasena` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// Insert default admins only if table is empty (con contraseñas aleatorias)
$res = $conn->query("SELECT COUNT(*) AS cnt FROM `admins`");
$row = $res->fetch_assoc();
if ((int)$row['cnt'] === 0) {
    $nombres = [
        'Luis Ruben Gil Martinez',
        'Joel Retana Calderon',
        'Miguel Alexander Vargas Mendoza',
        'Jassiel Emiliano Pintor Chavez',
    ];
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $stmt = $conn->prepare("INSERT INTO `admins` (`nombre`, `contrasena`) VALUES (?, ?)");
    foreach ($nombres as $nombre) {
        $pass = '';
        for ($i = 0; $i < 12; $i++) {
            $pass .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt->bind_param('ss', $nombre, $hash);
        $stmt->execute();
        $newAdmins[$nombre] = $pass;
    }
    $stmt->close();
    $log[] = "✓ 4 cuentas de administrador creadas";
} else {
    $log[] = "ℹ️ Administradores ya existían, omitido";
}

// Tabla del limitador de intentos de inicio de sesión
$conn->query("CREATE TABLE IF NOT EXISTS `login_attempts` (
    `k`            CHAR(64) NOT NULL PRIMARY KEY,
    `attempts`     INT NOT NULL DEFAULT 0,
    `window_start` INT UNSIGNED NOT NULL,
    `locked_until` INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB");

// ─────────────────────────────────────────────
// 2. Create each regional database
// ─────────────────────────────────────────────
foreach (getRegions() as $key => $info) {
    $dbName = $info['db'];
    $label  = $info['label'];

    if (!$conn->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        error_log("[diagnosticos] install $dbName: " . $conn->error);
        $errors[] = "✗ No se pudo crear $dbName (ver log de errores)";
        continue;
    }

    $conn->select_db($dbName);

    // usuarios table
    $conn->query("CREATE TABLE IF NOT EXISTS `usuarios` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `nombre`      VARCHAR(100) NOT NULL,
        `apellido`    VARCHAR(100) NOT NULL,
        `correo`      VARCHAR(150) NOT NULL UNIQUE,
        `telefono`    VARCHAR(20)  DEFAULT NULL,
        `contrasena`  VARCHAR(255) NOT NULL,
        `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // solicitudes table
    $conn->query("CREATE TABLE IF NOT EXISTS `solicitudes` (
        `id`                 INT AUTO_INCREMENT PRIMARY KEY,
        `usuario_id`         INT          NOT NULL,
        `nombre_solicitante` VARCHAR(200) NOT NULL,
        `centro_trabajo`     VARCHAR(200) NOT NULL,
        `tipo_equipo`        VARCHAR(100) NOT NULL,
        `marca`              VARCHAR(100) NOT NULL,
        `modelo`             VARCHAR(100) NOT NULL,
        `numero_serie`       VARCHAR(100) DEFAULT NULL,
        `numero_inventario`  VARCHAR(100) DEFAULT NULL,
        `lugar`              VARCHAR(150) DEFAULT NULL,
        `fecha`              DATE         DEFAULT NULL,
        `diagnostico`        TEXT         DEFAULT NULL COMMENT 'Descripción del problema (capturada por el usuario; solo la ve el admin)',
        `diagnostico_admin`  TEXT         DEFAULT NULL COMMENT 'Diagnóstico del administrador (el que va en el PDF)',
        `completada_por`     VARCHAR(200) DEFAULT NULL,
        `completada_at`      DATETIME     DEFAULT NULL,
        `region`             VARCHAR(60)  NOT NULL,
        `estado`             ENUM('pendiente','activa','completada') DEFAULT 'pendiente',
        `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Columnas de firmas y tabla de solicitudes de cambio (misma migración que usa el sistema)
    ensureSolicitudesSchema($conn, $key);

    $log[] = "✓ BD <b>$dbName</b> ($label) — tablas listas";
}

$conn->close();

if (empty($errors)) {
    @file_put_contents($lockFile, date('c'));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Instalación — Diagnósticos de Equipo</title>
<style>
  body { font-family: monospace; background:#0a0a1a; color:#00e5ff; padding:40px; }
  h1   { color:#d500f9; }
  .ok  { color:#76ff03; }
  .err { color:#ff1744; }
  .btn { display:inline-block; margin-top:24px; padding:12px 28px; background:#00e5ff;
         color:#000; font-weight:700; text-decoration:none; border-radius:8px; }
</style>
</head>
<body>
<h1>🛠 Instalación del Sistema</h1>
<?php foreach ($log as $l): ?>
  <p class="ok"><?= $l ?></p>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
  <p class="err"><?= $e ?></p>
<?php endforeach; ?>
<?php if (!empty($newAdmins)): ?>
  <div style="border:1px solid #ffd600;padding:16px;margin:20px 0;border-radius:8px">
    <p style="color:#ffd600"><b>⚠️ Guarda estas contraseñas de administrador ahora. No se volverán a mostrar.</b></p>
    <?php foreach ($newAdmins as $n => $pw): ?>
      <p><?= htmlspecialchars($n) ?> &nbsp;→&nbsp; <b><?= htmlspecialchars($pw) ?></b></p>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if (empty($errors)): ?>
  <p class="ok"><b>✅ Instalación completada exitosamente.</b></p>
  <a class="btn" href="../index.php">Ir al Sistema →</a>
<?php else: ?>
  <p class="err"><b>⚠️ Hubo errores. Revisa el log de errores de PHP/MySQL y vuelve a intentarlo.</b></p>
<?php endif; ?>
</body>
</html>
