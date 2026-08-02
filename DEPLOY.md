# 🚀 Despliegue a Producción

Guía paso a paso para poner la aplicación en un servidor real. No hay build en runtime: los assets ya están compilados y versionados.

## Requisitos del servidor

| Software | Versión | Notas |
|---|---|---|
| PHP | 8.4+ | Con extensiones `pdo_mysql`, `json`, `curl` (solo tests) |
| MySQL / MariaDB | 5.7+ | BD y usuario con permisos sobre esa BD |
| Servidor web | Apache o nginx | `DocumentRoot` → raíz del proyecto |

## 1. Generar el paquete

Desde un clon del repo:

```bash
zip -r karaoke-prod.zip . \
  -x ".git/*" ".env" "node_modules/*" "vendor/*" "tests/*" \
     ".phpunit.cache/*" "composer.json" "composer.lock" \
     "package.json" "package-lock.json" "css/input.css" "*.log"
```

Incluye: `index.php`, `api.php`, `db.php`, `bin/`, `assets/` (compilados), `.htaccess`, `.env.example`, docs.
Excluye: dev, tests y ficheros de build (no se necesitan en runtime).

## 2. Subir y configurar

```bash
# en el servidor, dentro del DocumentRoot
unzip karaoke-prod.zip
cp .env.example .env
# editar .env: DB_HOST, DB_NAME, DB_USER, DB_PASS
chmod 600 .env        # (con Apache, no es necesario por .htaccess)
```

## 3. Base de datos

El esquema **se crea solo** en el primer request (migraciones en `db.php`). Solo hay que crear la BD y el usuario:

```sql
CREATE DATABASE IF NOT EXISTS karaoke CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'karaoke'@'localhost' IDENTIFIED BY '...';
GRANT ALL PRIVILEGES ON karaoke.* TO 'karaoke'@'localhost';
FLUSH PRIVILEGES;
```

> Si se migra desde la versión anterior: la estructura es compatible y los datos se conservan. Se añade solo la fila `schema_version` en `settings`.

## 4. Primer arranque

1. Abrir la URL → se ejecuta la migración automáticamente.
2. Generar un PIN de staff **nuevo** (nunca usar uno anterior ni `1234`):
   ```bash
   php bin/reset-admin-pin.php
   ```
3. Entrar como staff con ese PIN y cambiarlo desde **Configuración de Hoy → Cambiar PIN Staff**.
4. Comprobar cola, registro (el código de la noche inicial es `TRILLA24`), reacciones y reorder.

## 5. Configuración del servidor web

### Apache
El `.htaccess` incluido ya:
- Bloquea `.env` y `.git` (`RewriteRule`)
- Desactiva el listado de directorios (`Options -Indexes`)

### nginx
Replicar las reglas en el `server` block:

```nginx
location ~ /\.env$         { deny all; }
location ~ /\.git          { deny all; }
location ~ /\.(git|env)$   { deny all; }
location /                 { try_files $uri $uri/ /index.php?$query_string; }
autoindex off;
```

## 6. Limpieza inicial (si venía de la versión antigua)

Reacciones acumuladas de antes (el nuevo sistema las purga >1h, pero conviene vaciar):

```sql
DELETE FROM reactions WHERE created_at < NOW() - INTERVAL 1 HOUR;
```

## 7. Cron de purga (recomendado)

```cron
*/10 * * * * mysql -u karaoke -p'...' karaoke -e "DELETE FROM reactions WHERE created_at < NOW() - INTERVAL 1 HOUR"
```

## Checklist de verificación

- [ ] `php -v` ≥ 8.4 y `pdo_mysql` cargado
- [ ] `.env` con credenciales correctas y `chmod 600`
- [ ] Primer request sin errores en el log de PHP
- [ ] `schema_version` = 1 en `settings`
- [ ] PIN de staff regenerado y cambiado
- [ ] Assets cargan sin CDN (cortar internet y recargar)
- [ ] Cola, reacciones y panel admin funcionan en móvil y desktop
- [ ] HTTPS activo (la sesión se marca `Secure` automáticamente)

## Rollback

Guardar una copia de seguridad de la BD antes de desplegar:

```bash
mysqldump -u karaoke -p karaoke > backup-$(date +%F).sql
```

Si algo falla: restaurar la copia y volver a subir el paquete anterior. La estructura es compatible en ambos sentidos.
