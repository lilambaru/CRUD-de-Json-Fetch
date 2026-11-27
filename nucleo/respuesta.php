<?php
declare(strict_types=1);

/*
 * Único punto de salida del API. Todo endpoint termina aquí, así el
 * contrato JSON (success, message, accion, errors, data, timestamp)
 * no se desordena entre archivos.
 */

function responder_json(
    bool $exito,
    string $mensaje,
    string $accion,
    array $errores = [],
    mixed $carga_util = null,
    int $codigo_http = 200
): never {
    // si algún notice o espacio se coló antes, lo tiramos: el cliente solo debe ver JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($codigo_http);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Respuesta-Id: ' . bin2hex(random_bytes(6)));

    echo json_encode([
        'success'   => $exito,
        'message'   => $mensaje,
        'accion'    => $accion,
        'errors'    => (object) $errores,
        'data'      => $carga_util,
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

    exit;
}
