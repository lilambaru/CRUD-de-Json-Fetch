-- Usuario de la aplicación con privilegios mínimos: solo CRUD sobre productosdb.
-- No tiene acceso a otras bases ni permisos de estructura (CREATE, DROP, ALTER...).
--   mysql -u root -p < sql/02_usuario_app.sql
CREATE USER IF NOT EXISTS 'tienda_app'@'localhost' IDENTIFIED BY 'Cl4vo#Tienda2026';

GRANT SELECT, INSERT, UPDATE, DELETE ON productosdb.* TO 'tienda_app'@'localhost';

FLUSH PRIVILEGES;
