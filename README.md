# 🎤 Karaoke La Trilla Cultural

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

Los tests (`tests/`) levantan un servidor PHP real y una base de datos `karaoke_test`:

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
index.php                # UI
tests/                   # PHPUnit
```

## API

Endpoints principales (todas las respuestas son JSON):

| Acción | Método | Descripción |
|---|---|---|
| `get_queue` | GET | Cola activa + estado global + reacciones nuevas (`&since={id}`) + CSRF token |
| `add_to_queue` | POST | Registrar participante (valida código diario) |
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

Las acciones de admin requieren el header `X-CSRF-Token` con el token devuelto por `get_queue`.

## Seguridad

- Sesión con cookie `HttpOnly` + `SameSite=Lax` (+ `Secure` bajo HTTPS)
- CSRF en todas las acciones administrativas
- PIN hasheado con `password_hash` (bcrypt); PIN inicial aleatorio, nunca `1234`
- Rate limiting en login (IP+sesión) y reacciones
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
