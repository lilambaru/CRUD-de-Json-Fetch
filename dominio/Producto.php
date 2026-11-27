<?php
declare(strict_types=1);

require_once __DIR__ . '/../nucleo/conexion.php';

/*
 * Producto: entidad del catálogo. Cada método hace una sola cosa:
 *   hidratar()  -> carga y normaliza datos
 *   validar()   -> solo reglas, devuelve errores, no toca la BD
 *   aArreglo()/aJson() -> salida
 *   guardar()/editar()/buscar()/listar() -> persistencia
 */
final class Producto
{
    private const PRECIO_MAXIMO = 99999999.99;

    private ?int $id_registro = null;
    private string $codigo = '';
    private string $descripcion_producto = '';
    private ?float $precio_unitario = null;
    private ?int $existencia = null;

    public function hidratar(array $fila): static
    {
        $id_crudo = $fila['id'] ?? null;
        $this->id_registro = (is_numeric($id_crudo) && (int) $id_crudo > 0) ? (int) $id_crudo : null;

        $this->codigo = strtoupper(self::limpiarTexto($fila['codigo'] ?? ''));
        $this->descripcion_producto = self::limpiarTexto($fila['producto'] ?? '');

        $precio_crudo = $fila['precio'] ?? null;
        $this->precio_unitario = is_numeric($precio_crudo) ? round((float) $precio_crudo, 2) : null;

        $cantidad_cruda = $fila['cantidad'] ?? null;
        $this->existencia = (is_scalar($cantidad_cruda) && filter_var($cantidad_cruda, FILTER_VALIDATE_INT) !== false)
            ? (int) $cantidad_cruda
            : null;

        return $this;
    }

    /**
     * Reglas de negocio. Al crear exigimos al menos 1 unidad (no se registra
     * mercancía que no existe); al editar se permite 0 porque el producto
     * puede estar agotado y aun así necesitar un cambio de precio o nombre.
     */
    public function validar(string $modo = 'crear'): array
    {
        $errores_campo = [];

        if ($modo === 'editar' && $this->id_registro === null) {
            $errores_campo['Id'] = 'No se indicó qué producto se quiere editar.';
        }

        if ($this->codigo === '') {
            $errores_campo['Codigo'] = 'El código es obligatorio.';
        } elseif (!preg_match('/^[A-Z0-9-]{2,20}$/', $this->codigo)) {
            $errores_campo['Codigo'] = 'Usa de 2 a 20 caracteres: letras, números o guion.';
        }

        $largo_nombre = mb_strlen($this->descripcion_producto);
        if ($largo_nombre === 0) {
            $errores_campo['Producto'] = 'El nombre del producto es obligatorio.';
        } elseif ($largo_nombre < 2 || $largo_nombre > 100) {
            $errores_campo['Producto'] = 'El nombre debe tener entre 2 y 100 caracteres.';
        }

        if ($this->precio_unitario === null || $this->precio_unitario <= 0 || $this->precio_unitario > self::PRECIO_MAXIMO) {
            $errores_campo['Precio'] = 'El precio debe ser un número mayor que 0.';
        }

        $piso_existencia = ($modo === 'editar') ? 0 : 1;
        if ($this->existencia === null) {
            $errores_campo['Cantidad'] = 'La cantidad debe ser un número entero.';
        } elseif ($this->existencia < $piso_existencia) {
            $errores_campo['Cantidad'] = $modo === 'editar'
                ? 'La cantidad no puede ser negativa.'
                : 'Un producto nuevo debe registrarse con al menos 1 unidad.';
        }

        return $errores_campo;
    }

    public function aArreglo(): array
    {
        return [
            'id'       => $this->id_registro,
            'codigo'   => $this->codigo,
            'producto' => $this->descripcion_producto,
            'precio'   => $this->precio_unitario,
            'cantidad' => $this->existencia,
        ];
    }

    public function aJson(): string
    {
        return json_encode($this->aArreglo(), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function obtenerId(): ?int
    {
        return $this->id_registro;
    }

    // consulta de apoyo para el controlador: el código no puede repetirse entre productos distintos
    public function codigoEnUso(): bool
    {
        $filas_previas = ConexionDB::query(
            'SELECT id FROM productos WHERE codigo = ? AND id <> ? LIMIT 1',
            [$this->codigo, $this->id_registro ?? 0]
        );

        return $filas_previas !== [];
    }

    public function guardar(): int
    {
        $this->id_registro = ConexionDB::insertSeguro('productos', [
            'codigo'   => $this->codigo,
            'producto' => $this->descripcion_producto,
            'precio'   => $this->precio_unitario,
            'cantidad' => $this->existencia,
        ]);

        return $this->id_registro;
    }

    // false = el id no existe en la tabla
    public function editar(): bool
    {
        $filas_tocadas = ConexionDB::updateSeguro(
            'productos',
            [
                'codigo'   => $this->codigo,
                'producto' => $this->descripcion_producto,
                'precio'   => $this->precio_unitario,
                'cantidad' => $this->existencia,
            ],
            ['id' => $this->id_registro]
        );

        return $filas_tocadas > 0;
    }

    /**
     * Busca por código o nombre. Los comodines % y _ que escriba el usuario
     * se escapan para que se traten como texto literal.
     */
    public static function buscar(string $termino_busqueda): array
    {
        $termino_escapado = addcslashes(trim($termino_busqueda), '%_\\');
        $patron_like = '%' . $termino_escapado . '%';

        $filas_sql = ConexionDB::query(
            "SELECT id, codigo, producto, precio, cantidad
               FROM productos
              WHERE codigo LIKE ? ESCAPE '\\\\' OR producto LIKE ? ESCAPE '\\\\'
              ORDER BY id DESC
              LIMIT 200",
            [$patron_like, $patron_like]
        );

        return self::tipar($filas_sql);
    }

    // ampliación opcional: paginación
    public static function listar(): array
    {
        return self::tipar(ConexionDB::query(
            'SELECT id, codigo, producto, precio, cantidad FROM productos ORDER BY id DESC LIMIT 200'
        ));
    }

    public static function buscarPorId(int $id_buscado): ?array
    {
        $fila_unica = ConexionDB::query(
            'SELECT id, codigo, producto, precio, cantidad FROM productos WHERE id = ?',
            [$id_buscado]
        );

        return $fila_unica[0] ?? null;
    }

    // PDO devuelve DECIMAL como texto; pasamos cada fila por la entidad para que el JSON salga con números reales
    private static function tipar(array $filas_sql): array
    {
        return array_map(fn(array $fila_sql): array => (new self())->hidratar($fila_sql)->aArreglo(), $filas_sql);
    }

    // aquí blindamos el texto contra XSS antes de que toque la BD
    private static function limpiarTexto(mixed $valor_sucio): string
    {
        if (!is_scalar($valor_sucio)) {
            return '';
        }
        $sin_etiquetas = strip_tags((string) $valor_sucio);
        $sin_ctrl = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $sin_etiquetas) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $sin_ctrl) ?? '');
    }
}
