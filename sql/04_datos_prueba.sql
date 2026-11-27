-- Productos de ejemplo para probar listado y búsqueda.
SET NAMES utf8mb4;
USE productosdb;

INSERT INTO productos (codigo, producto, precio, cantidad) VALUES
  ('TAL-650',  'Taladro 650W',                  58.90, 14),
  ('CIN-5M',   'Cinta métrica 5m',               4.75, 60),
  ('MAR-16',   'Martillo de uña 16 oz',          9.40, 32),
  ('DES-PH2',  'Destornillador Phillips PH2',    3.20, 85),
  ('ALI-8',    'Alicate universal 8"',           7.85, 41),
  ('LLA-AJ12', 'Llave ajustable 12"',           11.30, 0),
  ('PIN-BL1G', 'Pintura látex blanca 1 galón',  17.95, 23),
  ('TOR-8X1',  'Tornillo para madera 8x1 (caja 100)', 2.60, 120)
ON DUPLICATE KEY UPDATE producto = VALUES(producto);
