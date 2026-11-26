<?php
/**
 * Limitador de intentos de inicio de sesión (anti fuerza bruta).
 * Guarda los contadores en db_sistema.login_attempts (se crea sola si no existe).
 * Si db_sistema no está disponible, el límite se desactiva sin romper el login.
 */
require_once __DIR__ . '/../config/db.php';

const RL_WINDOW  = 900;   // 15 min: ventana de conteo y duración del bloqueo
const RL_LIMIT_ID = 5;    // intentos fallidos por cuenta (correo / nombre admin)
const RL_LIMIT_IP = 20;   // intentos fallidos por IP (mayor: varias personas pueden compartir IP)

function rlConn(): ?mysqli {
    static $conn = false;
    if ($conn === false) {
        $c = @new mysqli(DB_HOST, DB_USER, DB_PASS, 'db_sistema');
        if ($c->connect_error) {
            error_log('[diagnosticos] Limitador de intentos desactivado: ' . $c->connect_error);
            $conn = null;
        } else {
            $c->set_charset('utf8mb4');
            $c->query("CREATE TABLE IF NOT EXISTS `login_attempts` (
                `k`            CHAR(64) NOT NULL PRIMARY KEY,
                `attempts`     INT NOT NULL DEFAULT 0,
                `window_start` INT UNSIGNED NOT NULL,
                `locked_until` INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB");
            $conn = $c;
        }
    }
    return $conn;
}

function rlLower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function rlKeys(string $scope, string $identifier): array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return [
        hash('sha256', "$scope|ip|$ip")                        => RL_LIMIT_IP,
        hash('sha256', "$scope|id|" . rlLower($identifier)) => RL_LIMIT_ID,
    ];
}

/** Segundos que faltan para poder reintentar (0 = no está bloqueado). */
function rateLimitBlocked(string $scope, string $identifier): int {
    $c = rlConn();
    if (!$c) return 0;
    $now = time();
    $left = 0;
    $stmt = $c->prepare("SELECT locked_until FROM login_attempts WHERE k = ?");
    foreach (array_keys(rlKeys($scope, $identifier)) as $k) {
        $stmt->bind_param('s', $k);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && (int)$row['locked_until'] > $now) {
            $left = max($left, (int)$row['locked_until'] - $now);
        }
    }
    $stmt->close();
    return $left;
}

/** Registra un intento fallido. */
function rateLimitFail(string $scope, string $identifier): void {
    $c = rlConn();
    if (!$c) return;
    $now = time();
    $sel = $c->prepare("SELECT attempts, window_start FROM login_attempts WHERE k = ?");
    $up  = $c->prepare("INSERT INTO login_attempts (k, attempts, window_start, locked_until) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), window_start = VALUES(window_start), locked_until = VALUES(locked_until)");
    foreach (rlKeys($scope, $identifier) as $k => $limit) {
        $sel->bind_param('s', $k);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();

        if (!$row || $now - (int)$row['window_start'] > RL_WINDOW) {
            $attempts = 1;
            $start    = $now;
        } else {
            $attempts = (int)$row['attempts'] + 1;
            $start    = (int)$row['window_start'];
        }
        $locked = ($attempts >= $limit) ? $now + RL_WINDOW : 0;
        $up->bind_param('siii', $k, $attempts, $start, $locked);
        $up->execute();
    }
    $sel->close();
    $up->close();
}

/** Tras un login correcto se limpia el contador de esa cuenta. */
function rateLimitReset(string $scope, string $identifier): void {
    $c = rlConn();
    if (!$c) return;
    $k = hash('sha256', "$scope|id|" . rlLower($identifier));
    $stmt = $c->prepare("DELETE FROM login_attempts WHERE k = ?");
    $stmt->bind_param('s', $k);
    $stmt->execute();
    $stmt->close();
}

function rateLimitGuard(string $scope, string $identifier): void {
    $wait = rateLimitBlocked($scope, $identifier);
    if ($wait > 0) {
        $min = (int)ceil($wait / 60);
        jsonResponse(false, ['error' => "Demasiados intentos fallidos. Intenta de nuevo en $min minuto(s)."], 429);
    }
}
