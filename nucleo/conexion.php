<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/*
 * ConexionDB: una sola conexión PDO por petición (singleton) más tres
 * atajos que el resto del proyecto usa para no repetir prepare/execute.
 * Nombres de los atajos tomados de la guía: insertSeguro, updateSeguro, query.
 */
final class ConexionDB
{
    private static ?PDO $enlace_unico = null;

    private function __construct()
    {
    }

    public static function obtenerConexion(): PDO
    {
        if (self::$enlace_unico === null) {
            $cfg = cargar_config();
            $dsn_mysql = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'],
                $cfg['puerto'],
                $cfg['base']
            );

            self::$enlace_unico = new PDO($dsn_mysql, $cfg['usuario'], $cfg['clave'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // prepares reales: el motor recibe consulta y valores por separado
                PDO::ATTR_EMULATE_PREPARES   => false,
                // rowCount() cuenta filas encontradas, no solo las que cambiaron
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            ]);
        }

        return self::$enlace_unico;
    }

    /**
     * Envuelve begin/commit/rollback. Si el callable lanza algo, se revierte todo
     * y la excepción sigue su camino hacia arriba.
     */
    public static function transaccion(callable $fn): mixed
    {
        $enlace = self::obtenerConexion();
        $enlace->beginTransaction();

        try {
            $resultado_tx = $fn($enlace);
            $enlace->commit();
            return $resultado_tx;
        } catch (Throwable $falla_tx) {
            if ($enlace->inTransaction()) {
                $enlace->rollBack();
            }
            throw $falla_tx;
        }
    }

    public static function insertSeguro(string $tabla, array $campos_valores): int
    {
        self::exigirIdentificador($tabla);
        $nombres_col = array_keys($campos_valores);
        array_map([self::class, 'exigirIdentificador'], $nombres_col);

        $sql_insert = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $tabla,
            implode(', ', $nombres_col),
            implode(', ', array_fill(0, count($nombres_col), '?'))
        );

        $sentencia = self::obtenerConexion()->prepare($sql_insert);
        $sentencia->execute(array_values($campos_valores));

        return (int) self::obtenerConexion()->lastInsertId();
    }

    /**
     * Devuelve filas encontradas por el WHERE (0 = el registro no existe).
     */
    public static function updateSeguro(string $tabla, array $campos_valores, array $criterio): int
    {
        self::exigirIdentificador($tabla);
        if ($campos_valores === [] || $criterio === []) {
            throw new InvalidArgumentException('updateSeguro necesita campos y criterio.');
        }

        $piezas_set = [];
        $piezas_where = [];
        $valores_ordenados = [];

        foreach ($campos_valores as $col_set => $valor_set) {
            self::exigirIdentificador($col_set);
            $piezas_set[] = $col_set . ' = ?';
            $valores_ordenados[] = $valor_set;
        }
        foreach ($criterio as $col_where => $valor_where) {
            self::exigirIdentificador($col_where);
            $piezas_where[] = $col_where . ' = ?';
            $valores_ordenados[] = $valor_where;
        }

        $sql_update = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $tabla,
            implode(', ', $piezas_set),
            implode(' AND ', $piezas_where)
        );

        $sentencia = self::obtenerConexion()->prepare($sql_update);
        $sentencia->execute($valores_ordenados);

        return $sentencia->rowCount();
    }

    public static function query(string $sql_crudo, array $parametros = []): array
    {
        $sentencia = self::obtenerConexion()->prepare($sql_crudo);
        $sentencia->execute($parametros);

        return $sentencia->fetchAll();
    }

    // los nombres de tabla/columna no se pueden parametrizar, por eso la lista blanca
    private static function exigirIdentificador(string $nombre_sql): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $nombre_sql)) {
            throw new InvalidArgumentException('Identificador SQL no permitido.');
        }
    }
}
