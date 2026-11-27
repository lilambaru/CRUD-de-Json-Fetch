<?php
declare(strict_types=1);

// la cabecera va primero: si algo explota más abajo, el cliente igual recibe JSON
header('Content-Type: application/json; charset=utf-8');
ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/../nucleo/respuesta.php';
require_once __DIR__ . '/../nucleo/conexion.php';
require_once __DIR__ . '/../dominio/Producto.php';

set_exception_handler(function (Throwable $falla_global): void {
    error_log('[registrar.php] ' . $falla_global->getMessage());
    responder_json(false, 'Ocurrió un error interno. Revisa el log del servidor.', 'error_servidor', [], null, 500);
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responder_json(false, 'Este endpoint solo acepta POST.', 'metodo_no_permitido', [], null, 405);
}

// mapa de definiciones: cada campo entra ya filtrado, nada llega "crudo" a las clases
$mapa_filtros = [
    'Accion'   => ['filter' => FILTER_UNSAFE_RAW, 'flags' => FILTER_FLAG_STRIP_LOW],
    'Id'       => ['filter' => FILTER_VALIDATE_INT, 'options' => ['min_range' => 1]],
    'Codigo'   => ['filter' => FILTER_UNSAFE_RAW, 'flags' => FILTER_FLAG_STRIP_LOW],
    'Producto' => ['filter' => FILTER_UNSAFE_RAW, 'flags' => FILTER_FLAG_STRIP_LOW],
    'Precio'   => ['filter' => FILTER_VALIDATE_FLOAT],
    'Cantidad' => ['filter' => FILTER_VALIDATE_INT],
    'Termino'  => ['filter' => FILTER_UNSAFE_RAW, 'flags' => FILTER_FLAG_STRIP_LOW],
];
$payload_entrante = filter_input_array(INPUT_POST, $mapa_filtros) ?: [];

function armar_item_catalogo(array $payload_entrante): Producto
{
    return (new Producto())->hidratar([
        'id'       => $payload_entrante['Id'] ?? null,
        'codigo'   => $payload_entrante['Codigo'] ?? '',
        'producto' => $payload_entrante['Producto'] ?? '',
        'precio'   => $payload_entrante['Precio'] ?? null,
        'cantidad' => $payload_entrante['Cantidad'] ?? null,
    ]);
}

$accion_pedida = strtolower(trim((string) ($payload_entrante['Accion'] ?? '')));

switch ($accion_pedida) {

    case 'ping':
        // health-check: confirma que PHP responde y que la BD contesta
        ConexionDB::query('SELECT 1');
        responder_json(true, 'Servicio activo.', 'pong', [], ['php' => PHP_VERSION, 'bd' => 'ok']);

    case 'crear':
    case 'guardar':
        $item_catalogo = armar_item_catalogo($payload_entrante);
        $errores_item = $item_catalogo->validar('crear');

        if ($errores_item === [] && $item_catalogo->codigoEnUso()) {
            $errores_item['Codigo'] = 'Ya existe un producto con ese código.';
        }
        if ($errores_item !== []) {
            responder_json(false, 'Revisa los campos marcados.', 'error_validacion', $errores_item, null, 422);
        }

        try {
            $item_catalogo->guardar();
        } catch (PDOException $falla_sql) {
            // dos personas guardando el mismo código a la vez: gana la primera, la segunda cae aquí
            if ($falla_sql->getCode() === '23000') {
                responder_json(false, 'Ese código ya fue registrado.', 'error_validacion',
                    ['Codigo' => 'Ya existe un producto con ese código.'], null, 409);
            }
            throw $falla_sql;
        }

        responder_json(true, 'Producto registrado correctamente.', 'creado', [],
            ['item' => $item_catalogo->aArreglo()], 201);

    case 'actualizar':
    case 'modificar':
        $item_catalogo = armar_item_catalogo($payload_entrante);
        $errores_item = $item_catalogo->validar('editar');

        if ($errores_item === [] && $item_catalogo->codigoEnUso()) {
            $errores_item['Codigo'] = 'Otro producto ya usa ese código.';
        }
        if ($errores_item !== []) {
            responder_json(false, 'Revisa los campos marcados.', 'error_validacion', $errores_item, null, 422);
        }

        try {
            $existia_el_item = $item_catalogo->editar();
        } catch (PDOException $falla_sql) {
            if ($falla_sql->getCode() === '23000') {
                responder_json(false, 'Ese código ya pertenece a otro producto.', 'error_validacion',
                    ['Codigo' => 'Otro producto ya usa ese código.'], null, 409);
            }
            throw $falla_sql;
        }

        if (!$existia_el_item) {
            responder_json(false, 'El producto que intentas editar ya no existe.', 'no_encontrado', [], null, 404);
        }

        responder_json(true, 'Producto actualizado correctamente.', 'actualizado', [],
            ['item' => $item_catalogo->aArreglo()]);

    case 'listar':
        $filas_catalogo = Producto::listar();
        responder_json(true, 'Catálogo cargado.', 'listado', [],
            ['items' => $filas_catalogo, 'total' => count($filas_catalogo)]);

    case 'buscar':
        $termino_pedido = (string) ($payload_entrante['Termino'] ?? '');
        if (mb_strlen($termino_pedido) > 100) {
            responder_json(false, 'El término de búsqueda es demasiado largo.', 'error_validacion',
                ['Termino' => 'Máximo 100 caracteres.'], null, 422);
        }
        $filas_encontradas = $termino_pedido === '' ? Producto::listar() : Producto::buscar($termino_pedido);
        responder_json(true, 'Búsqueda completada.', 'buscado', [],
            ['items' => $filas_encontradas, 'total' => count($filas_encontradas), 'termino' => $termino_pedido]);

    default:
        responder_json(false, 'Acción no reconocida.', 'desconocida',
            ['Accion' => 'Valores válidos: ping, crear, actualizar, listar, buscar.'], null, 400);
}
