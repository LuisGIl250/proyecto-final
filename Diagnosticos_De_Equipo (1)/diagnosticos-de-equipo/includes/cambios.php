<?php
/**
 * Solicitudes de cambio: el usuario le pide algo al administrador sobre una de sus solicitudes
 * (corregir un dato, revisar el diagnóstico, etc.) y el administrador la atiende con una respuesta opcional.
 */
const CAMBIO_MSG_MAX  = 1000;   // caracteres del mensaje del usuario
const CAMBIO_RESP_MAX = 500;    // caracteres de la respuesta del administrador

/**
 * Cambios de varias solicitudes de una sola vez.
 * Devuelve [solicitud_id => [cambio, ...]] con lo más reciente primero.
 */
function loadCambios(mysqli $conn, array $solicitudIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $solicitudIds))));
    if (!$ids) return [];

    $res = $conn->query(
        "SELECT id, solicitud_id, mensaje, estado, respuesta, atendido_por, atendido_at, created_at
           FROM cambios_solicitud
          WHERE solicitud_id IN (" . implode(',', $ids) . ")
          ORDER BY id DESC"
    );
    if (!$res) {
        error_log('[diagnosticos] loadCambios: ' . $conn->error);
        return [];
    }
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['solicitud_id']][] = $row;
    }
    $res->free();
    return $out;
}
