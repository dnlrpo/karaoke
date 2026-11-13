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

Incluye: `index.php`, `api.php`, `db.php`, `src/`, `assets/` (compilados), `.htaccess`, `.env.example`, docs.
Excluye: dev, tests y ficheros de build (no se necesitan en runtime). No hay scripts ejecutables en el paquete:
todo lo que toca credenciales se hace desde la base de datos.

## 2. Subir y configurar

```bash
# en el servidor, dentro del DocumentRoot
unzip karaoke-prod.zip
cp .env.example .env
# editar .env: DB_HOST, DB_NAME, DB_USER, DB_PASS
# si hay proxy/CDN delante: TRUSTED_PROXIES=IP_DEL_PROXY (para que el rate limit use la IP real)
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

> Si se migra desde la versión anterior: la estructura es compatible y los datos se conservan.
> La migración v2 añade la tabla `rate_limits` y la v3 los índices de cola y reacciones.

## 4. Primer arranque

1. Abrir la URL → se ejecuta la migración automáticamente.
2. El primer request crea el admin `staff` con un PIN aleatorio. Aparece en el log de PHP
   (`[karaoke] Admin 'staff' creado. PIN inicial: ...`) y una sola vez en la UI de esa sesión.
3. Entrar como staff con ese PIN y cambiarlo desde **Configuración de Hoy → Cambiar PIN Staff**.
4. Comprobar cola, registro (el código de la noche inicial es `TRILLA24`), reacciones y reorder.

> **Si se pierde el PIN**, no hay script web para regenerarlo a propósito (sería una puerta trasera).
> Se hace desde la base de datos:
>
> ```bash
> php -r 'echo password_hash("NUEVO_PIN", PASSWORD_DEFAULT), PHP_EOL;'
> ```
>
> ```sql
> UPDATE admins SET password_hash = '<hash>' WHERE username = 'staff';
> ```
>
> Al cambiarlo se invalidan todas las sesiones de staff abiertas. Si prefieres que el sistema
> genere un PIN aleatorio nuevo, borra la fila (`DELETE FROM admins WHERE username = 'staff';`)
> y el siguiente request creará otra con un PIN distinto.


## 5. Configuración del servidor web

### Apache
El `.htaccess` incluido ya:
- Bloquea `.env`, `.git` y el código que no debe servirse (`bin/`, `tests/`, `vendor/`, `node_modules/`, ficheros de build)
- Añade `X-Content-Type-Options`, `Referrer-Policy` y `X-Frame-Options`
- Desactiva el listado de directorios (`Options -Indexes`)

### nginx
Replicar las reglas en el `server` block:

```nginx
location ~ ^/(bin|src|tests|vendor|node_modules)/ { deny all; }
location ~ ^/(composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml\.dist|AGILE_REPORT\.md|DEPLOY\.md)$ { deny all; }
location ~ /\.(env|git)        { deny all; }
location /                     { try_files $uri $uri/ /index.php?$query_string; }
autoindex off;
```

> Las cabeceras de seguridad (incluida la CSP) las envía la aplicación desde PHP, así que no
> hace falta duplicarlas con `add_header`. La CSP permite el logo remoto de
> `latrillacultural.com`; si algún día se quita esa dependencia, ajusta la cabecera en `db.php`.
>
> Con proxy o CDN delante, define `TRUSTED_PROXIES` en el `.env` con la IP del proxy.
> Sin eso, todos los clientes comparten la IP del proxy y el rate limit por IP los agrupa.


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
- [ ] `schema_version` = 3 en `settings`
- [ ] PIN inicial cambiado (y las sesiones de staff anteriores invalidadas)
- [ ] `/bin/`, `/tests/` y `/vendor/` responden 403/404 (no 200)
- [ ] Login: 15 fallos desde la misma IP quedan bloqueados 15 minutos (tabla `rate_limits`)
- [ ] La respuesta HTML incluye `Content-Security-Policy` con `script-src 'self'`
- [ ] `GET /api.php?action=health` responde 200 con `{"status":"ok"}`
- [ ] Assets cargan sin CDN (cortar internet y recargar)
- [ ] Cola, reacciones y panel admin funcionan en móvil y desktop
- [ ] HTTPS activo (la sesión se marca `Secure` automáticamente)

## Rollback

Guardar una copia de seguridad de la BD antes de desplegar:

```bash
mysqldump -u karaoke -p karaoke > backup-$(date +%F).sql
```

Si algo falla: restaurar la copia y volver a subir el paquete anterior. La estructura es compatible en ambos sentidos.
