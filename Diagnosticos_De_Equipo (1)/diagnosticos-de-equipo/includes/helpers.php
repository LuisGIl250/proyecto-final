<?php
function jsonResponse(bool $success, array $data = [], int $httpCode = 200): void {
    if ($httpCode !== 200) http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success], $data));
    exit;
}

/** Los endpoints que modifican datos solo aceptan POST. */
function requirePost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        jsonResponse(false, ['error' => 'Método no permitido'], 405);
    }
}

function sanitize(string $value): string {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Longitud en caracteres (UTF-8), con respaldo si mbstring no está activo. */
function textLength(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function getStatusLabel(string $estado): string {
    return match($estado) {
        'pendiente'  => 'Pendiente',
        'activa'     => 'Activa',
        'completada' => 'Completada',
        default      => ucfirst($estado),
    };
}

function getStatusClass(string $estado): string {
    return match($estado) {
        'pendiente'  => 'status-pending',
        'activa'     => 'status-active',
        'completada' => 'status-done',
        default      => '',
    };
}
