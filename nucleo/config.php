<?php
declare(strict_types=1);

/*
 * Configuración central. XAMPP no carga archivos .env por su cuenta,
 * así que leemos uno propio si existe y si no, caemos a valores por defecto
 * pensados para un entorno local de laboratorio.
 */

function cargar_config(): array
{
    static $config_cacheada = null;
    if ($config_cacheada !== null) {
        return $config_cacheada;
    }

    $ruta_env = dirname(__DIR__) . '/.env';
    if (is_readable($ruta_env)) {
        foreach (file($ruta_env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea_env) {
            $linea_env = trim($linea_env);
            if ($linea_env === '' || $linea_env[0] === '#' || !str_contains($linea_env, '=')) {
                continue;
            }
            [$llave_env, $valor_env] = array_map('trim', explode('=', $linea_env, 2));
            // lo que ya venga del sistema operativo tiene prioridad
            if (getenv($llave_env) === false) {
                putenv($llave_env . '=' . $valor_env);
            }
        }
    }

    $config_cacheada = [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'puerto'  => (int) (getenv('DB_PORT') ?: 3306),
        'base'    => getenv('DB_NAME') ?: 'productosdb',
        'usuario' => getenv('DB_USER') ?: 'tienda_app',
        'clave'   => getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'Cl4vo#Tienda2026',
    ];

    return $config_cacheada;
}
