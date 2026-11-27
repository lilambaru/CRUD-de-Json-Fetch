# CRUD Fetch + PHP OOP + MySQL — Ferretería El Clavo

Catálogo de productos con **guardar, editar y buscar** sin recargar la página.
Frontend en JavaScript vanilla (Fetch + FormData) con Bootstrap 5 y SweetAlert2; backend en PHP orientado a objetos con PDO.

## Integrantes

| Estudiante | Cédula |
|---|---|
| Javier Vallarino | 8-1040-1488 |

## Requisitos

- XAMPP o WampServer con **PHP 8.1 o superior** y MySQL/MariaDB
- Extensión `pdo_mysql` activa (viene por defecto)
- Navegador moderno y conexión a internet (Bootstrap y SweetAlert2 se cargan por CDN)

## Cómo levantarlo en XAMPP

1. Copia la carpeta `crud-ferreteria-elclavo` dentro de `C:\xampp\htdocs\`.
2. Abre el panel de XAMPP y arranca **Apache** y **MySQL**.
3. Crea la base, la tabla, el usuario y los datos de prueba (ver siguiente sección).
4. (Opcional) copia `.env.example` como `.env` si quieres cambiar credenciales.
5. Entra a `http://localhost/crud-ferreteria-elclavo/`.

Prueba rápida del backend: `POST` con `Accion=ping` a `api/registrar.php` debe responder `{"success":true,"accion":"pong",...}`.

## Base de datos y credenciales

| Dato | Valor |
|---|---|
| Base | `productosdb` |
| Usuario de la app | `tienda_app` (solo SELECT, INSERT, UPDATE, DELETE sobre `productosdb`) |
| Clave | `Cl4vo#Tienda2026` |
| Host | `127.0.0.1:3306` |

Comandos (desde la carpeta del proyecto, con el usuario administrador de MySQL; en XAMPP suele ser `root` sin clave):

```bash
mysql -u root -p < sql/01_esquema.sql
mysql -u root -p < sql/02_usuario_app.sql
mysql -u root -p < sql/04_datos_prueba.sql
```

Estructura exacta de la tabla pedida en la guía:

```sql
CREATE TABLE productos (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  codigo   VARCHAR(20)   NOT NULL,
  producto VARCHAR(100)  NOT NULL,
  precio   DECIMAL(10,2) NOT NULL,
  cantidad INT           NOT NULL
);
-- además, el script agrega un índice UNIQUE sobre `codigo`
```

Para ver los privilegios del usuario de la aplicación: `sql/03_ver_privilegios.sql`
(`SHOW GRANTS FOR 'tienda_app'@'localhost';`).

> Si prefieres usar `root` en el laboratorio, crea `.env` con `DB_USER=root` y `DB_PASS=` (vacío).

## Estructura

```
crud-ferreteria-elclavo/
├── index.php              redirige a la vista principal
├── nucleo/                conexión y piezas transversales
│   ├── config.php         lee .env / getenv
│   ├── conexion.php       clase ConexionDB (singleton + transaccion + insertSeguro/updateSeguro/query)
│   └── respuesta.php      salida JSON única
├── dominio/
│   └── Producto.php       entidad: hidratar, validar, aArreglo, aJson, guardar, editar, buscar
├── api/
│   └── registrar.php      endpoint con switch de acciones
├── vistas/
│   └── index.html         formulario + tabla + buscador
├── public/
│   ├── css/estilo.css
│   └── js/script.js       objeto Cliente + switch sobre respuesta.accion
└── sql/                   esquema, usuario con privilegios mínimos, grants, datos de prueba
```

## Contrato del API

`POST api/registrar.php` con `FormData`. Campo `Accion`: `ping`, `crear` (alias `guardar`), `actualizar` (alias `modificar`), `listar`, `buscar`.

Toda respuesta tiene la forma:

```json
{ "success": true, "message": "...", "accion": "creado", "errors": {}, "data": {}, "timestamp": "2026-11-25T10:00:00-05:00" }
```

y un header `X-Respuesta-Id` para rastrear la petición en el Inspector de Red.
Acciones devueltas: `creado`, `actualizado`, `listado`, `buscado`, `pong`, `error_validacion`, `no_encontrado`, `desconocida`, `error_servidor`.

## Reglas de negocio

- Al **registrar**, la cantidad mínima es **1** (no se da de alta mercancía inexistente).
- Al **editar**, la cantidad mínima es **0** (un producto agotado igual debe poder corregirse). El formulario cambia el `min` del input según el modo.
- El código se guarda en mayúsculas y no puede repetirse.

## Decisiones de diseño

- **Carpetas por responsabilidad, no por patrón de moda.** `nucleo` agrupa lo que sirve a todo el proyecto (conexión, config, salida JSON); `dominio` guarda las entidades; `api` contiene solo endpoints delgados. Es el mismo reparto de responsabilidades que pide la guía, con nombres que describen lo que hay dentro.
- **Un solo punto de salida JSON.** `responder_json()` garantiza el mismo contrato en todas las rutas, descarta cualquier salida accidental con `ob_end_clean()` y agrega `timestamp` y `X-Respuesta-Id`. Así el `.json()` del navegador nunca recibe basura.
- **`validar()` no toca la base de datos.** Devuelve un arreglo de errores por campo; la comprobación de código duplicado vive aparte (`codigoEnUso()`) y la restricción `UNIQUE` en MySQL cubre el caso de dos usuarios guardando a la vez (error 23000 → respuesta 409).
- **Todo el tráfico del navegador pasa por el objeto `Cliente`.** Hay un único `fetch`, un único manejo de errores de red/formato y un único lugar donde se usa SweetAlert; el resto del código solo pide `exito()`, `fallo()` o `confirmar()`.
- **Interfaz que responde mientras espera.** Skeleton en la tabla durante la carga, botón con spinner al guardar, buscador con debounce de 350 ms y descarte de respuestas atrasadas para que no se pisen resultados.

## Entrega en repositorio

La rúbrica pide un repositorio del laboratorio. Desde la carpeta del proyecto:

```bash
git init
git add .
git commit -m "CRUD Fetch + PHP OOP + MySQL"
git branch -M main
git remote add origin https://github.com/<tu-usuario>/<tu-repo>.git
git push -u origin main
```

## Ampliaciones posibles

- Eliminar productos (`Accion=eliminar`)
- Paginación del listado
