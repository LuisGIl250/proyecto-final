<?php
// Que mysqli devuelva false en caso de error (en lugar de lanzar excepciones),
// para que el manejo de errores del sistema funcione igual en cualquier versión de PHP.
mysqli_report(MYSQLI_REPORT_OFF);

// Credenciales: se pueden sobrescribir creando config/db.local.php
// (ver db.local.php.example) o con variables de entorno DB_HOST / DB_USER / DB_PASS.
if (is_file(__DIR__ . '/db.local.php')) {
    require __DIR__ . '/db.local.php';
}
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

$REGIONS = [
    'armeria'          => ['db' => 'db_armeria',          'label' => 'Armería'],
    'colima'           => ['db' => 'db_colima',           'label' => 'Colima'],
    'comala'           => ['db' => 'db_comala',           'label' => 'Comala'],
    'coquimatlan'      => ['db' => 'db_coquimatlan',      'label' => 'Coquimatlán'],
    'cuauhtemoc'       => ['db' => 'db_cuauhtemoc',       'label' => 'Cuauhtémoc'],
    'ixtlahuacan'      => ['db' => 'db_ixtlahuacan',      'label' => 'Ixtlahuacán'],
    'manzanillo'       => ['db' => 'db_manzanillo',       'label' => 'Manzanillo'],
    'minatitlan'       => ['db' => 'db_minatitlan',       'label' => 'Minatitlán'],
    'tecoman'          => ['db' => 'db_tecoman',          'label' => 'Tecomán'],
    'villa_de_alvarez' => ['db' => 'db_villa_de_alvarez', 'label' => 'Villa de Álvarez'],
    'madrid'           => ['db' => 'db_madrid',           'label' => 'Madrid'],
    'suchitlan'        => ['db' => 'db_suchitlan',        'label' => 'Suchitlán'],
    'queseria'         => ['db' => 'db_queseria',         'label' => 'Quesería'],
];

function dbFail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['success' => false, 'error' => $msg]));
}

/**
 * Migración automática: deja `solicitudes` con todas las columnas que usa el sistema
 * (diagnóstico del administrador y firmas) y crea la tabla `cambios_solicitud`
 * si la base fue instalada con una versión anterior.
 * Es idempotente y barata (un SHOW COLUMNS y un SHOW TABLES por petición).
 */
function ensureSolicitudesSchema(mysqli $conn, string $region): void {
    static $done = [];
    if (isset($done[$region])) return;
    $done[$region] = true;

    $res = $conn->query("SHOW COLUMNS FROM `solicitudes`");
    if ($res === false) return;            // la tabla no existe (instalador sin correr)
    $have = [];
    while ($r = $res->fetch_assoc()) $have[$r['Field']] = true;
    $res->free();

    $wanted = [
        'diagnostico_admin'    => "TEXT NULL",
        'completada_por'       => "VARCHAR(200) NULL",
        'completada_at'        => "DATETIME NULL",
        // Firmas (HMAC en hexadecimal) con el momento y, en el caso del usuario, el nombre de su cuenta
        'firma_admin'          => "VARCHAR(64) NULL",
        'firma_admin_at'       => "DATETIME NULL",
        'firma_usuario'        => "VARCHAR(64) NULL",
        'firma_usuario_nombre' => "VARCHAR(201) NULL",
        'firma_usuario_at'     => "DATETIME NULL",
        // Nombre del archivo de la fotografía del equipo (se guarda en uploads/<región>/)
        'imagen'               => "VARCHAR(64) NULL",
    ];
    $add = [];
    foreach ($wanted as $col => $def) {
        if (!isset($have[$col])) $add[] = "ADD COLUMN `$col` $def";
    }
    if ($add && !$conn->query("ALTER TABLE `solicitudes` " . implode(', ', $add))) {
        error_log("[diagnosticos] No se pudo migrar solicitudes en $region: " . $conn->error);
    }

    // Solicitudes de cambio que el usuario envía al administrador
    $t = $conn->query("SHOW TABLES LIKE 'cambios_solicitud'");
    if ($t !== false) {
        $exists = $t->num_rows > 0;
        $t->free();
        if (!$exists && !$conn->query("CREATE TABLE `cambios_solicitud` (
                `id`           INT AUTO_INCREMENT PRIMARY KEY,
                `solicitud_id` INT          NOT NULL,
                `usuario_id`   INT          NOT NULL,
                `mensaje`      TEXT         NOT NULL,
                `estado`       ENUM('pendiente','atendido') NOT NULL DEFAULT 'pendiente',
                `respuesta`    TEXT         NULL,
                `atendido_por` VARCHAR(200) NULL,
                `atendido_at`  DATETIME     NULL,
                `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_solicitud` (`solicitud_id`)
            ) ENGINE=InnoDB")) {
            error_log("[diagnosticos] No se pudo crear cambios_solicitud en $region: " . $conn->error);
        }
    }
}

function getConnection(?string $region = null): mysqli {
    global $REGIONS;

    if ($region !== null && !isset($REGIONS[$region])) {
        dbFail(400, 'Región inválida');
    }

    $dbName = ($region !== null) ? $REGIONS[$region]['db'] : 'db_sistema';
    $conn   = @new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);

    if ($conn->connect_error) {
        // El detalle técnico va al log del servidor, no al cliente.
        error_log("[diagnosticos] Error de conexión a $dbName: " . $conn->connect_error);
        dbFail(500, 'No se pudo conectar a la base de datos');
    }

    $conn->set_charset('utf8mb4');
    if ($region !== null) {
        ensureSolicitudesSchema($conn, $region);
    }
    return $conn;
}

function getRegions(): array {
    global $REGIONS;
    return $REGIONS;
}
