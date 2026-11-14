# 🚀 Despliegue a Producción

Guía paso a paso para publicar la aplicación en `https://latrillacultural.com/karaoke`.
No hay build en runtime: los assets ya están compilados y versionados.

## Arquitectura de despliegue

La aplicación vive en un directorio **fuera** del `DocumentRoot`. Solo su carpeta
`public/` se expone mediante un enlace simbólico:

```text
$HOME/
├── public_html/                              # DocumentRoot de latrillacultural.com
│   ├── index.php                             # web principal
│   ├── ...
│   └── karaoke -> ../karaoke-files/public    # enlace simbólico
│
└── karaoke-files/                            # fuera del DocumentRoot
    ├── public/                               # única carpeta accesible por HTTP
    │   ├── index.php
    │   ├── api.php
    │   ├── .htaccess
    │   └── assets/
    ├── app/                                  # código PHP no público
    │   ├── db.php
    │   └── src/
    ├── config/                               # .env con credenciales
    ├── storage/                              # datos mutables
    └── ...
```

El enlace apunta a **`karaoke-files/public`**, nunca a `karaoke-files` completa.
`app/`, `config/`, `storage/`, `vendor/` y `tests/` quedan físicamente fuera del
alcance del servidor web.

## Requisitos del servidor

| Software | Versión | Notas |
|---|---|---|
| PHP | 8.4+ | Extensiones `pdo_mysql`, `mbstring`, `json` |
| MySQL / MariaDB | 5.7+ | BD y usuario con permisos sobre esa BD |
| Servidor web | Apache o nginx | `DocumentRoot` → `public_html`; `/karaoke` → symlink |
| Sistema de archivos | — | El usuario web necesita leer `karaoke-files/` |

## 1. Generar el paquete

Desde un clon del repo:

```bash
zip -r karaoke-prod.zip public app config storage \
  -x "config/.env" "storage/*" "*.log"
```

El paquete incluye:

- `public/` (entrypoints, `.htaccess` y assets compilados);
- `app/` (código de la aplicación);
- `config/.env.example`;
- `storage/.gitkeep`.

No incluye `.env`, tests, `vendor/`, `composer.json`, `node_modules/`, la fuente
de Tailwind (`css/`), la documentación interna (`SPEC.md`) ni ficheros de
desarrollo. No hay scripts ejecutables en el paquete: todo lo que toca
credenciales se hace desde la base de datos.

> En el servidor no hace falta `composer install`: la aplicación no usa
> autoload ni dependencias de runtime. `vendor/` solo existe para PHPUnit en
> desarrollo.

## 2. Subir y configurar

Subir el paquete a un directorio hermano del `DocumentRoot`, por ejemplo
`$HOME/karaoke-files`, y descomprimirlo allí:

```bash
cd $HOME
mkdir -p karaoke-files
cd karaoke-files
unzip ~/karaoke-prod.zip

cp config/.env.example config/.env
# editar config/.env: DB_HOST, DB_NAME, DB_USER, DB_PASS
# si hay proxy/CDN delante: TRUSTED_PROXIES=IP_DEL_PROXY
chmod 600 config/.env
```

El `.env` debe quedar en `karaoke-files/config/.env`, **nunca** dentro de
`public/`.

## 3. Crear el enlace simbólico

Con el `DocumentRoot` real del sitio (`$HOME/public_html` en este ejemplo):

```bash
ln -s $HOME/karaoke-files/public $HOME/public_html/karaoke
```

Comprobar que el enlace resuelve al `public/` correcto:

```bash
ls -l $HOME/public_html/karaoke
readlink -f $HOME/public_html/karaoke
# debe terminar en /karaoke-files/public
```

`https://latrillacultural.com/karaoke/` servirá entonces
`karaoke-files/public/index.php`.

## 4. Base de datos

El esquema **se crea solo** en el primer request (migraciones en `app/db.php`).
Solo hay que crear la BD y el usuario:

```sql
CREATE DATABASE IF NOT EXISTS karaoke CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'karaoke'@'localhost' IDENTIFIED BY '...';
GRANT ALL PRIVILEGES ON karaoke.* TO 'karaoke'@'localhost';
FLUSH PRIVILEGES;
```

> Si se migra desde la versión anterior: la estructura es compatible y los datos
> se conservan. La migración v2 añade la tabla `rate_limits`, la v3 los índices
> de cola y reacciones, y la v4 la tabla `participants` y la columna
> `songs.participant_id`.

## 5. Primer arranque

1. Abrir `https://latrillacultural.com/karaoke/` → se ejecuta la migración
   automáticamente.
2. El primer request crea el admin `staff` con un PIN aleatorio. Aparece en el
   log de PHP (`[karaoke] Admin 'staff' creado. PIN inicial: ...`) y una sola
   vez en la UI de esa sesión.
3. Entrar como staff con ese PIN y cambiarlo desde **Configuración de Hoy →
   Cambiar PIN Staff**.
4. Comprobar cola, registro (el código de la noche inicial es `TRILLA24`),
   reacciones y reorder.

> **Si se pierde el PIN**, no hay script web para regenerarlo a propósito (sería
> una puerta trasera). Se hace desde la base de datos. Sustituye `482913` por tu
> PIN de **6 dígitos**:
>
> ```bash
> php -r 'echo password_hash("482913", PASSWORD_DEFAULT), PHP_EOL;'
> ```
>
> ```sql
> UPDATE admins SET password_hash = '<hash>' WHERE username = 'staff';
> ```
>
> Al cambiarlo se invalidan todas las sesiones de staff abiertas. Si prefieres
> que el sistema genere un PIN aleatorio nuevo, borra la fila
> (`DELETE FROM admins WHERE username = 'staff';`) y el siguiente request creará
> otra con un PIN distinto.

## 6. Configuración del servidor web

### Apache

El `public/.htaccess` incluido ya:

- bloquea dotfiles (`.env`, `.git`) y directorios internos;
- impide ejecutar PHP dentro de `assets/`;
- añade `X-Content-Type-Options`, `Referrer-Policy` y `X-Frame-Options`;
- desactiva el listado de directorios (`Options -Indexes`).

Además, el `DocumentRoot` debe permitir seguir el enlace:

- `Options +FollowSymLinks` (o `SymLinksIfOwnerMatch` con el propietario
  correcto) en el directorio del sitio;
- `AllowOverride All` (o al menos `FileInfo` y `Options`) para que se aplique
  `public/.htaccess`;
- el usuario web necesita permiso de lectura/ejecución sobre
  `karaoke-files/` y lectura sobre `karaoke-files/public/`.

Si la web principal usa WordPress u otro CMS con `mod_rewrite`, su regla
genérica **no debe capturar `/karaoke`**. Añadir antes del rewrite del CMS:

```apache
RewriteCond %{REQUEST_URI} ^/karaoke(/|$)
RewriteRule ^ - [L]
```

### nginx

`/karaoke` es un enlace simbólico. nginx los sigue por defecto; comprobar que no
haya `disable_symlinks on`. Configuración de referencia:

```nginx
location /karaoke/ {
    alias /home/USUARIO/karaoke-files/public/;
    index index.php;
    try_files $uri $uri/ /karaoke/index.php?$query_string;
}

location ~ ^/karaoke/(app|config|storage|tests|vendor|node_modules)/ {
    deny all;
}

location ~ ^/karaoke/\. {
    deny all;
}

location ~ ^/karaoke/assets/.*\.php$ {
    deny all;
}

location ~ ^/karaoke/.+\.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /home/USUARIO/karaoke-files/public$fastcgi_script_name;
    fastcgi_param SCRIPT_NAME     /karaoke$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}
```

> Ajustar la ruta del socket de PHP-FPM y el usuario. Si se usa `root` en lugar
> de `alias`, el `location` debe apuntar al directorio que contiene el enlace
> (`public_html`) para que `/karaoke` resuelva.

> Las cabeceras de seguridad (incluida la CSP) las envía la aplicación desde
> PHP, así que no hace falta duplicarlas con `add_header`. La CSP es
> `img-src 'self' data:`: no hay ningún asset remoto.

> Con proxy o CDN delante, define `TRUSTED_PROXIES` en `config/.env` con la IP
> del proxy. Sin eso, todos los clientes comparten la IP del proxy y el rate
> limit por IP los agrupa.

## 7. Limpieza inicial (si venía de la versión antigua)

Reacciones acumuladas de antes (el nuevo sistema las purga >1h, pero conviene
vaciar):

```sql
DELETE FROM reactions WHERE created_at < NOW() - INTERVAL 1 HOUR;
```

## 8. Cron de purga (recomendado)

```cron
*/10 * * * * mysql -u karaoke -p'...' karaoke -e "DELETE FROM reactions WHERE created_at < NOW() - INTERVAL 1 HOUR"
```

## Checklist de verificación

- [ ] `php -v` ≥ 8.4 y `pdo_mysql` cargado
- [ ] `config/.env` con credenciales correctas y `chmod 600`
- [ ] `readlink -f public_html/karaoke` termina en `karaoke-files/public`
- [ ] `/karaoke/` responde 200 y carga la UI
- [ ] `/karaoke/app/db.php`, `/karaoke/config/.env` y `/karaoke/storage/` responden 403/404 (no 200)
- [ ] `public_html/karaoke/../config/.env` no es accesible
- [ ] El rewrite del CMS de la web principal no captura `/karaoke`
- [ ] Primer request sin errores en el log de PHP
- [ ] `schema_version` = 4 en `settings`
- [ ] PIN inicial cambiado (y las sesiones de staff anteriores invalidadas)
- [ ] Login: 15 fallos desde la misma IP quedan bloqueados 15 minutos (tabla `rate_limits`)
- [ ] La respuesta HTML incluye `Content-Security-Policy` con `script-src 'self'`
- [ ] `GET /karaoke/api.php?action=health` responde 200 con `{"status":"ok"}`
- [ ] El logo y el resto de assets cargan desde `/karaoke/assets/`
- [ ] El participante valida nombre + código una vez y puede anotar varias canciones
- [ ] La cookie `karaoke_pid` se emite `HttpOnly` y dura 12 h
- [ ] Assets cargan sin CDN (cortar internet y recargar)
- [ ] Cola, reacciones y panel admin funcionan en móvil y desktop
- [ ] HTTPS activo (la sesión se marca `Secure` automáticamente)

## Rollback

Guardar una copia de seguridad de la BD antes de desplegar:

```bash
mysqldump -u karaoke -p karaoke > backup-$(date +%F).sql
```

Si algo falla: restaurar la copia y volver a subir el paquete anterior. La
estructura es compatible en ambos sentidos. Para volver al esquema antiguo,
sustituir el enlace por el despliegue anterior y restaurar el `.env` en la raíz
de ese paquete.
