# 🎤 Karaoke La Trilla Cultural

![CI](https://github.com/dnlrpo/karaoke/actions/workflows/ci.yml/badge.svg)

Gestión de turnos para eventos de karaoke en tiempo real. Los asistentes se registran con un código diario, ven su posición en la cola y envían reacciones; el staff administra la cola desde un panel con PIN.

## Requisitos

| Dependencia | Versión mínima | Uso |
|---|---|---|
| PHP | 8.4 | Runtime (requerido por PHPUnit 13) |
| MySQL / MariaDB | 5.7+ | Base de datos |
| Node.js + npm | 20+ | Solo para **regenerar** el CSS de Tailwind |

La aplicación **no depende de ningún CDN**: todos los assets (Tailwind compilado, Font Awesome, SortableJS, fuentes y logo) están versionados en `assets/`. Solo se necesita internet para instalar dependencias la primera vez.

## Instalación

1. Clonar el proyecto y crear la base de datos:

```sql
CREATE DATABASE IF NOT EXISTS karaoke CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS karaoke_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON karaoke.* TO 'karaoke'@'localhost';
GRANT ALL PRIVILEGES ON karaoke_test.* TO 'karaoke'@'localhost';
FLUSH PRIVILEGES;
```

2. Copiar la configuración:

```bash
cp .env.example .env
# editar .env con tus credenciales (DB_USER, DB_PASS, ...)
```

> Si hay un proxy o CDN delante, rellena `TRUSTED_PROXIES` con la IP del proxy para que el
> rate limiting use la IP real del cliente y no la del proxy.

3. Instalar dependencias (solo dev/tests y build de CSS):

```bash
composer install
npm install
```

> En producción no hace falta `npm install`: `assets/css/tailwind.min.css` ya está compilado y commiteado.

4. Arrancar el servidor:

```bash
php -S localhost:8000
# o usar Apache/Nginx con DocumentRoot en la raíz del proyecto
```

5. **Primer arranque:** el esquema se crea solo (migraciones en `db.php`). El PIN inicial del staff se genera aleatoriamente y se escribe en el log de PHP/error_log (busca `[karaoke] Admin 'staff' creado`). Cámbialo desde el panel en la primera sesión. El código de la noche inicial es `TRILLA24`.

## Comandos

| Comando | Descripción |
|---|---|
| `php -S localhost:8000` | Servidor de desarrollo |
| `composer test` | Ejecutar la suite PHPUnit |
| `composer lint` | Lint de sintaxis PHP de todo el proyecto |
| `npm run build` | Regenerar `assets/css/tailwind.min.css` (tras añadir clases) |

## Tests

Los tests (`tests/`) levantan un servidor PHP real y una base de datos `karaoke_test`.
`QueueOptimizerTest` prueba la lógica de reordenamiento sin BD (casos límite incluidos):

```bash
composer test
```

La DB `karaoke_test` debe existir y ser accesible por el usuario del `.env` (crearla con los GRANT del paso 1). Cada test parte de una base limpia.

## Estructura

```
assets/
  css/tailwind.min.css   # Tailwind 4.3.3 compilado (no editar a mano)
  css/style.css          # CSS custom
  js/app.js              # Lógica de la app
  js/sortable.min.js     # SortableJS 1.15.7
  vendor/fontawesome/    # Font Awesome 7.3.1 (css + webfonts)
  fonts/                 # Google Fonts (Courgette, Montserrat) locales
  img/                   # Logo
css/input.css            # Fuente de Tailwind (@source directives)
api.php                  # REST API (acciones separadas por ?action=)
db.php                   # Conexión, sesión segura, migraciones
src/QueueOptimizer.php   # Lógica pura de optimización de la cola
src/Participant.php      # Identidad anónima (cookie + sesión)
index.php                # UI
.github/workflows/ci.yml # CI (PHP 8.4 + MySQL 8.4)
tests/                   # PHPUnit (integración + unitarios)
```

## API

Endpoints principales (todas las respuestas son JSON):

| Acción | Método | Descripción |
|---|---|---|
| `get_queue` | GET | Cola activa + estado global + reacciones nuevas (`&since={id}`) + CSRF token |
| `validate_code` | POST | Valida nombre + código y recuerda al participante 12 h |
| `reset_participant` | POST | Borra la identidad del dispositivo (cambio de persona) |
| `add_to_queue` | POST | Añadir canción (requiere código ya validado; usa el nombre de la sesión) |
| `send_reaction` | POST | Enviar emoji (whitelist + rate limit 15/30s) |
| `login` | POST | Login staff (rate limit 5 intentos / 5 min) |
| `logout` | POST | Cerrar sesión (requiere CSRF) |
| `reorder_queue` | POST | Reordenar cola (admin, requiere CSRF) |
| `auto_reorder` | POST | Optimizar cola: adelanta novatos, separa repetidos (admin) |
| `remove_from_queue` | POST | Marcar participante como terminado (admin) |
| `clear_queue` | POST | Vaciar cola (admin) |
| `update_night_code` | POST | Cambiar código diario (admin) |
| `update_staff_pin` | POST | Cambiar PIN staff (admin) |
| `toggle_registration` | POST | Abrir/cerrar registro (admin) |
| `health` | GET | Estado del servicio y de la BD (para uptime checks) |

Las acciones de admin requieren el header `X-CSRF-Token` con el token devuelto por `get_queue`.
Cada acción acepta un único método HTTP (`GET` para las lecturas, `POST` para todo lo demás):
si se usa otro, la API responde `405`. Los errores usan códigos HTTP reales
(`403` acceso/CSRF, `409` conflicto de cola, `422` validación, `429` rate limit, `500` error interno)
junto con `{"success": false, "message": "..."}`.

## Seguridad

- Sesión con cookie `HttpOnly` + `SameSite=Lax` (+ `Secure` bajo HTTPS)
- CSRF en todas las acciones administrativas
- PIN hasheado con `password_hash` (bcrypt); PIN inicial aleatorio, nunca `1234`
- Cambiar el PIN invalida el resto de sesiones de staff (`auth_epoch` en `settings`)
- Identidad anónima de participante: cookie `karaoke_pid` `HttpOnly` (12 h) con token aleatorio;
  en BD solo se guarda su hash. El código de la noche nunca se guarda en el cliente
- Al validar el código, la cookie de sesión también se reemite a 12 h; el login de staff
  vuelve a cookie de navegador (no queda una sesión de admin persistente)
- Rate limit en la validación del código: 10 fallos/15 min por IP
- Rate limiting persistido en BD: login (5 fallos/5 min por sesión, 15/15 min por IP),
  registro (12/10 min por sesión, 150/h por IP) y reacciones (15/30 s por sesión)
- La IP del cliente se toma de `REMOTE_ADDR`; `X-Forwarded-For` solo se acepta desde `TRUSTED_PROXIES`
- Límites de longitud en todos los campos del formulario y tope de 80 turnos en la cola
- CSP estricta (`script-src 'self'`, sin `unsafe-inline`) con delegación de eventos en el frontend;
  permite el logo remoto de `latrillacultural.com`
- Cabeceras `X-Content-Type-Options`, `Referrer-Policy` y `X-Frame-Options` enviadas desde PHP
- Inserción de turnos transaccional (`SELECT ... FOR UPDATE`) para no duplicar posiciones
- Índices en `songs(status, sort_order)` y `reactions(created_at)` (migración v3)
- Reordenamiento por identidad (`participants.id`), no por nombre: dos personas con el
  mismo nombre no se mezclan (migración v4)
- SQL preparado en todas las consultas; escape de salida en el frontend
- Reacciones antiguas se purgan automáticamente (> 1 hora, probabilístico); para purga inmediata programar un cron:

```cron
*/10 * * * * mysql -u karaoke -p'...' karaoke -e "DELETE FROM reactions WHERE created_at < NOW() - INTERVAL 1 HOUR"
```

## Mantenimiento del CSS

Si se añaden/eliminan clases de Tailwind en `index.php`, `app.js` o `style.css`:

```bash
npm run build
```

El resultado (`assets/css/tailwind.min.css`) se commitea; en producción nunca se compila.
