<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,       // la cookie no es legible desde JavaScript
        'samesite' => 'Lax',      // no se envía en peticiones cruzadas (mitiga CSRF)
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// ── Helpers ──────────────────────────────────────────────

function isUserLoggedIn(): bool {
    return isset($_SESSION['user']['id']);
}

function isAdminLoggedIn(): bool {
    return isset($_SESSION['admin']['id']);
}

function requireUser(): void {
    if (!isUserLoggedIn()) {
        header('Location: /diagnosticos-de-equipo/index.php?error=session');
        exit;
    }
}

function requireAdmin(): void {
    if (!isAdminLoggedIn()) {
        header('Location: /diagnosticos-de-equipo/index.php?error=session');
        exit;
    }
}

function getCurrentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function getCurrentAdmin(): ?array {
    return $_SESSION['admin'] ?? null;
}

function loginUser(array $user, string $region, string $regionLabel): void {
    session_regenerate_id(true);          // evita fijación de sesión
    unset($_SESSION['admin']);            // una sesión no puede ser usuario y admin a la vez
    $_SESSION['user'] = [
        'id'           => $user['id'],
        'nombre'       => $user['nombre'],
        'apellido'     => $user['apellido'],
        'correo'       => $user['correo'],
        'region'       => $region,
        'region_label' => $regionLabel,
    ];
}

function loginAdmin(array $admin): void {
    session_regenerate_id(true);
    unset($_SESSION['user']);
    $_SESSION['admin'] = [
        'id'     => $admin['id'],
        'nombre' => $admin['nombre'],
    ];
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: /diagnosticos-de-equipo/index.php');
    exit;
}

// ── CSRF ─────────────────────────────────────────────────

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Exige que la petición traiga el token CSRF en la cabecera X-CSRF-Token. */
function requireCsrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $real = $_SESSION['csrf_token'] ?? '';
    if ($real === '' || !is_string($sent) || !hash_equals($real, $sent)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.']);
        exit;
    }
}
