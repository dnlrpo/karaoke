# Especificación — Karaoke La Trilla

> Documento de referencia para desarrollo y mantenimiento.
>
> - **Última revisión:** 2026-11-14 (refactor de directorios)
> - **Commit de referencia:** `d31033a` + cambios de refactor sin commitear
> - **Estado:** 52 tests / 298 assertions OK · lint PHP OK
> - **Alcance:** comportamiento actual de la aplicación, contrato de API, modelo de datos, seguridad, operación y deuda técnica conocida.

Este documento describe **cómo funciona el sistema hoy**. Si el código y este documento entran en conflicto, el código es la fuente de verdad: hay que actualizar la especificación en el mismo cambio.

---

## 1. Resumen

Aplicación web para gestionar la cola de karaoke de **La Trilla Cultural**.

Los asistentes:

- validan un código de la noche con su nombre;
- piden canciones y ven la cola en tiempo real;
- envían reacciones durante las actuaciones.

El staff:

- entra con un PIN;
- reordena, optimiza, elimina o vacía la cola;
- cambia el código de la noche y el PIN;
- abre o cierra el registro.

### Stack

| Capa | Tecnología |
|---|---|
| Runtime | PHP 8.4 |
| Base de datos | MySQL / MariaDB 5.7+ |
| Backend | PHP procedural, PDO, sin framework |
| Frontend | HTML + JavaScript vanilla, polling cada 5 s |
| CSS | Tailwind 4.3.3 compilado |
| Iconos | Font Awesome 7.3.1 local |
| Drag & drop | SortableJS 1.15.7 local |
| Tests | PHPUnit 13.2 |
| CI | GitHub Actions, PHP 8.4 + MySQL 8.4 |

### Fuera de alcance

- Cuentas de usuario o login de asistentes.
- Catálogo de canciones o búsqueda musical.
- Pagos, reservas o aforo.
- Multi-local o multi-evento simultáneo.
- WebSockets; la actualización es por polling.
- Historial/reportes formales por noche.
- App móvil nativa.

---

## 2. Glosario

| Término | Significado |
|---|---|
| **Participante** | Persona que pide canciones. Tiene identidad anónima, sin cuenta. |
| **Identidad** | Fila de `participants` asociada a una cookie de dispositivo. |
| **Canción / turno** | Fila de `songs`; representa una petición de una persona. |
| **Cola activa** | Canciones con estado `waiting` o `singing`. |
| **Código de noche** | Código compartido que el staff cambia para permitir el registro. |
| **Staff** | Usuario administrador `staff`, autenticado con PIN. |
| **Novato** | Participante sin canciones terminadas en las últimas 12 h. |
| **Terminada** | Canción marcada como `finished` por el staff. |
| **Reacción** | Emoji efímero enviado durante la actuación. |
| **Noche / evento** | Concepto operativo; no existe una tabla de eventos. El “día” se representa con el código de la noche. |

---

## 3. Roles y permisos

### 3.1 Participante

Puede, sin cuenta:

- validar nombre + código de la noche;
- añadir canciones mientras el registro esté abierto;
- enviar reacciones;
- borrar su identidad del dispositivo y empezar como otra persona.

No puede:

- ver el código de la noche (solo el staff);
- acceder al panel de administración;
- reordenar, eliminar o vaciar la cola.

### 3.2 Staff

Puede:

- iniciar y cerrar sesión con PIN;
- ver la cola y el código actual;
- reordenar la cola;
- optimizar la cola automáticamente;
- marcar canciones como terminadas;
- vaciar la cola;
- cambiar el código de la noche;
- cambiar el PIN;
- abrir/cerrar el registro.

Todas las acciones de staff requieren sesión de admin **y** token CSRF.

### 3.3 Sistema

- Ejecuta migraciones al arrancar.
- Crea el admin inicial si no existe.
- Aplica rate limiting.
- Limpia reacciones antiguas de forma probabilística.
- Expone un endpoint de salud.

---

## 4. Flujos funcionales

### 4.1 Registro y validación del participante

1. El registro debe estar habilitado (`settings.registration_enabled = 1`).
2. El asistente envía `userName` y `accessCode` a `validate_code`.
3. El servidor:
   - normaliza el nombre (trim + colapso de espacios);
   - comprueba el código con `hash_equals`;
   - aplica el rate limit por IP;
   - busca la cookie `karaoke_pid`; si existe y el nombre normalizado coincide
     (sin distinguir mayúsculas), reutiliza esa identidad;
   - si no, crea una fila en `participants` y emite una cookie nueva;
   - guarda en sesión `participant_id`, `participant_name`, `access_code_time`;
   - reemite la cookie de sesión con 12 h de vida.
4. La UI pasa al paso de canción.

La cookie `karaoke_pid`:

- contiene un token aleatorio de 64 hex;
- en BD solo se guarda `sha256(token)`;
- es `HttpOnly`, `SameSite=Lax`, `Secure` bajo HTTPS;
- dura 12 h.

### 4.2 Alta de canción

1. El registro debe estar abierto.
2. Debe existir identidad de participante en sesión.
3. Se validan `songTitle` (máx. 120) y `artistName` (máx. 80).
4. Se aplican límites por sesión e IP.
5. Se comprueba que la cola activa no supere 80 turnos.
6. Se inserta al final con `sort_order = max + 1` dentro de una transacción
   con `SELECT ... FOR UPDATE`.
7. Se devuelve `id` y `position`.

El nombre visible de la canción es el nombre guardado en la identidad del
participante, no un nombre enviado en cada alta.

### 4.3 Reacciones

- Emojis permitidos: `👏`, `🔥`, `❤️`, `😂`, `🤩`, `😱`, `🙉`, `🍅`.
- Límite: 15 reacciones / 30 s por sesión.
- Se insertan en `reactions` y se devuelven en el polling de los últimos 30 s.
- Las reacciones de más de 1 h se purgan de forma probabilística (2 % de las
  peticiones que llaman a `cleanupReactions`).
- El frontend no muestra al usuario sus propias reacciones devueltas por el
  polling; las pinta de forma optimista al enviarlas.

### 4.4 Gestión de la cola

- **Reordenar:** el staff envía la lista completa de IDs activos en el orden
  deseado. Debe coincidir exactamente con la cola activa (sin IDs de más,
  de menos, duplicados o inválidos). Se reescribe `sort_order` de 1 a N.
- **Marcar terminada:** cualquier canción activa pasa a `finished`.
- **Vaciar:** todas las canciones activas pasan a `deleted`.
- **Optimizar:** ver sección 5.

La UI considera que **el primer elemento de la cola está cantando**. La API no
cambia el estado a `singing` en ningún flujo actual; el staff simplemente marca
“LISTO” al primero cuando termina.

### 4.5 Configuración del staff

- **Código de la noche:** se guarda en `settings.night_code`; por defecto
  `TRILLA24`. Solo se devuelve al staff autenticado.
- **PIN:** 6 dígitos numéricos. El login acepta PIN antiguos de 4 dígitos por
  compatibilidad.
- **Registro:** `settings.registration_enabled`.
- **PIN inicial:** si la tabla `admins` está vacía, el primer request crea
  `staff` con un PIN aleatorio de 6 dígitos, lo escribe en el log y lo deja en
  la sesión de esa petición.
- **Cambio de PIN:** actualiza el hash y sube `settings.auth_epoch`; el resto de
  sesiones de staff quedan invalidadas en la siguiente petición.

---

## 5. Reglas de la cola

### 5.1 Límites y orden

- Máximo de turnos activos: **80** (`MAX_QUEUE`).
- La cola activa se ordena por `sort_order ASC, id ASC`.
- La inserción es transaccional para no duplicar posiciones.
- El borrado no renumera; los huecos son válidos. El reordenado sí renumera.

### 5.2 Optimizador automático

Implementado en `app/src/QueueOptimizer.php` y probado sin BD.

Entrada:

- cola de canciones `waiting` en orden actual;
- contador de canciones `finished` de las últimas 12 h por identidad.

Reglas:

1. **Adelantar novatos**
   - Novato = identidad sin canciones terminadas en las últimas 12 h.
   - Si un novato está en la posición 7 o posterior (índice 0-based >= 6), se
     mueve a la posición 6.
   - Se conserva el orden relativo de los novatos entre sí.
2. **Evitar repeticiones**
   - Después se reparte la cola evitando que la misma identidad vuelva a cantar
     dentro de los siguientes 4 turnos.
   - Es un best effort: si no hay alternativa, se permite la repetición.

Comportamiento particular fijado por los tests: si **todos** son novatos, el
sexto participante pasa al final y el resto avanza una posición.

### 5.3 Identidad para el reordenamiento

Cada canción usa:

- `p:<participant_id>` si tiene identidad;
- `n:<user_name>` para filas antiguas sin `participant_id`.

Dos personas con el mismo nombre pero distinto `participant_id` son identidades
distintas y no se separan por nombre.

### 5.4 Limitaciones actuales

- `songs.status = 'singing'` existe en el esquema, pero ningún endpoint lo
  asigna.
- Las estadísticas de “terminadas en 12 h” usan `created_at`, no una fecha de
  finalización.
- No hay tabla de eventos/noches; el histórico vive en `songs`.

---

## 6. Arquitectura

### 6.1 Mapa de archivos

```text
public/                    Única raíz web (expuesta como /karaoke)
public/index.php           UI principal (shell HTML)
public/api.php             Router/controlador REST por ?action=
public/.htaccess           Reglas de acceso de la raíz pública
public/assets/css/         Tailwind compilado + CSS propio
public/assets/js/app.js    Lógica del frontend
public/assets/js/          SortableJS local
public/assets/vendor/      Font Awesome local
public/assets/fonts/       Fuentes locales
public/assets/img/         Logo local (SVG)
app/db.php                 PDO, migraciones, sesión, CSRF, rate limits, helpers
app/src/Participant.php    Identidad anónima de participante
app/src/QueueOptimizer.php Lógica pura de optimización de cola
config/.env                Credenciales (no versionado, fuera de public/)
config/.env.example        Plantilla de configuración
storage/                   Datos mutables; fuera de public/
css/input.css              Fuente de Tailwind (solo desarrollo)
tests/                     PHPUnit: integración HTTP + unitarios + seguridad
vendor/                    Dependencias de desarrollo (PHPUnit)
.github/workflows/ci.yml   CI
README.md                  Instalación y uso
DEPLOY.md                  Despliegue a producción
SPEC.md                    Este documento
AGILE_REPORT.md            Informe antiguo; no usar como referencia técnica actual
```

En producción el repositorio se instala como `karaoke-files/`, hermano del
`DocumentRoot` (`public_html/`). `/karaoke` es un enlace simbólico a
`karaoke-files/public`, no a la raíz de la aplicación.

### 6.2 Backend

`app/db.php` se incluye primero y:

- carga `config/.env`;
- conecta por PDO;
- define `SCHEMA_VERSION` y `PARTICIPANT_TTL`;
- ejecuta migraciones;
- crea el admin inicial si hace falta;
- ofrece helpers de sesión, CSRF, auth, IP y rate limiting.

`public/api.php`:

- envía cabeceras JSON y de seguridad;
- arranca sesión;
- resuelve la acción contra `ROUTES`;
- valida método HTTP y permisos;
- ejecuta el `switch`;
- responde siempre en JSON.

Las rutas de entrada usan `dirname(__DIR__)` para salir de `public/` y cargar
`app/`.

### 6.3 Frontend

- `public/index.php` sirve el HTML; no hay SSR de la cola.
- `public/assets/js/app.js` hace polling a `get_queue` cada 5 s.
- La cola solo se repinta si cambia su firma (`queueSignature`), para no romper
  el drag & drop ni parpadear.
- El modo staff activa SortableJS y los controles de administración.
- Los eventos se delegan con `data-action`; no hay handlers inline.
- Los textos de usuario se insertan con `escapeHTML` o `textContent`.
- No hay bundler: los assets se sirven tal cual.

### 6.4 Persistencia

- PDO con `ERRMODE_EXCEPTION`, fetch asociativo y preparaciones reales.
- Migraciones automáticas al incluir `app/db.php`, controladas por
  `settings.schema_version`.
- No hay framework de migraciones; las migraciones son closures numeradas.

---

## 7. Modelo de datos

### 7.1 `songs`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | INT PK AI | |
| `user_name` | VARCHAR(255) | Nombre visible |
| `song_title` | VARCHAR(255) | |
| `artist_name` | VARCHAR(255) | |
| `status` | ENUM | `waiting`, `singing`, `finished`, `deleted` |
| `sort_order` | INT | Orden de la cola |
| `created_at` | TIMESTAMP | |
| `participant_id` | INT NULL | Identidad; sin FK formal |

Índices:

- `idx_songs_status_sort (status, sort_order)`
- `idx_songs_participant (participant_id)`

### 7.2 `settings`

| Columna | Tipo | Notas |
|---|---|---|
| `key` | VARCHAR(50) PK | |
| `value` | TEXT | |

Claves usadas:

- `night_code`
- `registration_enabled`
- `schema_version`
- `auth_epoch`

### 7.3 `admins`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | INT PK AI | |
| `username` | VARCHAR(50) UNIQUE | Hoy solo `staff` |
| `password_hash` | VARCHAR(255) | `password_hash`, bcrypt por defecto |

### 7.4 `reactions`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | INT PK AI | |
| `emoji` | VARCHAR(10) | Whitelist en API |
| `created_at` | TIMESTAMP | Índice `idx_reactions_created` |

### 7.5 `rate_limits`

| Columna | Tipo | Notas |
|---|---|---|
| `bucket` | VARCHAR(32) | Ej. `login_ip`, `validate_ip` |
| `subject` | VARBINARY(64) | IP empaquetada o hash de sesión |
| `window_start` | INT UNSIGNED | Unix timestamp |
| `attempts` | SMALLINT UNSIGNED | |

PK compuesta `(bucket, subject)` + índice por `window_start`.

### 7.6 `participants`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | INT PK AI | |
| `token_hash` | CHAR(64) UNIQUE | SHA-256 del token de cookie |
| `name` | VARCHAR(60) | Nombre normalizado |
| `created_at` | TIMESTAMP | |
| `last_seen_at` | TIMESTAMP | |

### 7.7 Relaciones y decisiones

- `songs.participant_id` referencia lógicamente a `participants.id`; no hay FK.
- No se borran participantes ni canciones históricas de forma automática.
- El estado `singing` no se usa actualmente (ver 5.4).

---

## 8. API

### 8.1 Convenciones

- Base: `/karaoke/api.php?action=<accion>`.
- GET para lecturas; POST para mutaciones.
- Body JSON en POST.
- Respuesta de éxito:

```json
{ "success": true, "...": "..." }
```

- Respuesta de error:

```json
{ "success": false, "message": "..." }
```

- Las acciones de staff requieren:
  - sesión de admin válida;
  - header `X-CSRF-Token` con el token devuelto por `get_queue`.
- Cada acción acepta un único método; si no, `405` con `Allow`.
- Si la acción no existe, `400`.

### 8.2 Endpoints públicos

| Acción | Método | Body / Query | Respuesta |
|---|---|---|---|
| `health` | GET | — | `success`, `status`, `db`, `schema_version`, `time` |
| `get_queue` | GET | `since` opcional | cola, estado, reacciones, CSRF, datos del participante |
| `get_reactions` | GET | `since` opcional | reacciones de los últimos 30 s |
| `validate_code` | POST | `userName`, `accessCode` | `message`, `participant_name`, `expires_in` |
| `reset_participant` | POST | — | borra identidad de sesión y cookie |
| `add_to_queue` | POST | `songTitle`, `artistName` | `id`, `position` |
| `send_reaction` | POST | `emoji` | `id` |
| `login` | POST | `pin` | éxito + mensaje |

Notas:

- `get_queue` devuelve `night_code` solo si `is_admin`.
- `get_queue` devuelve `initial_admin_pin` si está en sesión. **Es un riesgo
  conocido** (ver 14).
- `get_reactions` es legado: el frontend usa el polling unificado de
  `get_queue`.
- `add_to_queue` exige participante validado y registro abierto.
- `send_reaction` no exige participante validado.

### 8.3 Endpoints de staff

Todos requieren admin + CSRF.

| Acción | Método | Body | Efecto |
|---|---|---|---|
| `logout` | POST | — | destruye la sesión |
| `reorder_queue` | POST | `orderedIds: int[]` | reordena la cola activa |
| `auto_reorder` | POST | — | aplica el optimizador |
| `remove_from_queue` | POST | `id` | marca `finished` |
| `clear_queue` | POST | — | marca todo lo activo como `deleted` |
| `update_night_code` | POST | `newCode` | cambia el código |
| `update_staff_pin` | POST | `newPin` | cambia el PIN e invalida sesiones |
| `toggle_registration` | POST | `enabled` | abre/cierra registro |

Validaciones destacadas:

- `newCode`: `^[A-Z0-9_-]{4,32}$` tras pasar a mayúsculas.
- `newPin`: `^\d{6}$`.
- `orderedIds`: enteros positivos, sin duplicados y exactamente iguales al
  conjunto activo.
- `id` de `remove_from_queue`: numérico positivo.

### 8.4 Códigos de estado

| Código | Uso |
|---|---|
| `200` | Éxito |
| `400` | Acción inválida |
| `401` | PIN incorrecto |
| `403` | Sin permiso, CSRF inválido, registro cerrado, participante no validado |
| `404` | Canción no encontrada en la cola |
| `405` | Método HTTP incorrecto |
| `409` | Cola llena, lista que no coincide, cola vacía para optimizar |
| `422` | Validación de campos/IDs |
| `429` | Rate limit |
| `500` | Error interno |
| `503` | Health degradado |

---

## 9. Seguridad

### 9.1 Sesión y cookies

- `session.use_strict_mode = 1`.
- Cookie de sesión `HttpOnly`, `SameSite=Lax`, `Secure` bajo HTTPS.
- `session.gc_maxlifetime` = 12 h.
- Al validar el código se reemite la cookie de sesión a 12 h.
- El login de staff vuelve a cookie de navegador (no persistente).
- El login regenera el ID de sesión.
- La cookie `karaoke_pid` es independiente de la sesión y también es
  `HttpOnly`/`SameSite=Lax`/`Secure` bajo HTTPS.

### 9.2 CSRF

- Token de 32 bytes en sesión.
- Se entrega en `get_queue`.
- Se valida con `hash_equals` contra `X-CSRF-Token`.
- Obligatorio en todas las acciones de staff, incluido `logout`.

### 9.3 Rate limiting

Persistido en `rate_limits` (no en memoria), por lo que sobrevive reinicios y
funciona con varios workers.

| Bucket | Límite | Ventana | Clave |
|---|---|---|---|
| `validate_ip` | 10 fallos | 15 min | IP |
| `login_ip` | 15 fallos | 15 min | IP |
| sesión de login | 5 fallos | 5 min | sesión |
| `register_session` | 12 altas | 10 min | sesión |
| `register_ip` | 150 altas | 1 h | IP |
| reacciones | 15 envíos | 30 s | sesión |

El contador de validación se resetea al acertar el código. El de login se
resetea al iniciar sesión correctamente.

### 9.4 Identidad de participante

- Token aleatorio de 32 bytes (64 hex) en cookie.
- En BD solo el SHA-256.
- Reutilización solo si el nombre normalizado coincide.
- Un nombre distinto en el mismo dispositivo crea una identidad nueva.
- `reset_participant` borra sesión y cookie, pero conserva el historial de
  canciones.

### 9.5 PIN de staff

- 6 dígitos; hash con `password_hash` (bcrypt por defecto).
- Login acepta PIN legados de 4 dígitos.
- `auth_epoch` en `settings` invalida el resto de sesiones al cambiar el PIN.
- La sesión actual se refresca con el nuevo epoch.
- El PIN inicial aleatorio se muestra en el log y, actualmente, en la sesión
  que crea el admin.

### 9.6 Cabeceras y CSP

Cabeceras enviadas desde PHP:

- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `X-Frame-Options: DENY`
- `X-Request-Id`
- `Content-Security-Policy`

CSP actual:

```text
default-src 'self';
img-src 'self' data:;
style-src 'self';
script-src 'self';
font-src 'self';
connect-src 'self';
frame-ancestors 'none';
base-uri 'none';
form-action 'self'
```

No se permite `unsafe-inline` ni scripts inline. Los eventos se delegan desde
`app.js` y los estilos inline están prohibidos por test estático.

### 9.7 Validación y salida

- Límites de longitud en API: nombre 60, canción 120, artista 80, código 4–32.
- IDs numéricos positivos.
- Reacciones con whitelist.
- SQL preparado en todas las consultas.
- Salida HTML escapada en el frontend.
- `json_encode` con sustitución de UTF-8 inválido.

### 9.8 Proxy e IP real

- `REMOTE_ADDR` es la fuente por defecto.
- `X-Forwarded-For` solo se acepta si `REMOTE_ADDR` está en
  `TRUSTED_PROXIES`.
- `X-Forwarded-Proto` se usa para detectar HTTPS, pero actualmente se acepta de
  cualquier cliente. Es una inconsistencia conocida (ver 14).

### 9.9 Principios para código nuevo

- Toda acción nueva debe entrar en `ROUTES` con método y nivel de permisos.
- Toda mutación de staff debe exigir CSRF.
- Todo endpoint público nuevo debe tener validación de entrada y, si puede
  abusarse, rate limit.
- No interpolar datos en SQL.
- No añadir handlers inline ni estilos inline.
- No confiar en cabeceras de proxy sin comprobar `TRUSTED_PROXIES`.
- No exponer secretos en respuestas, logs de aplicación ni HTML.

---

## 10. Configuración y operación

### 10.1 Variables de entorno

`config/.env` (no versionado, fuera de `public/`):

```dotenv
DB_HOST=localhost
DB_NAME=karaoke
DB_USER=karaoke
DB_PASS=
DB_CHARSET=utf8mb4
TRUSTED_PROXIES=
```

`TRUSTED_PROXIES`: lista de IPs separadas por comas. Solo esas IPs pueden
aportar `X-Forwarded-For`.

### 10.2 Base de datos

- Crear `karaoke` y `karaoke_test`.
- El esquema se crea solo en el primer request.
- Migraciones actuales: v1 base, v2 rate limits, v3 índices, v4 participants.
- `SCHEMA_VERSION` actual: **4**.
- Para un cambio de esquema: añadir una migración numerada, subir
  `SCHEMA_VERSION`, hacerla idempotente y actualizar este documento.

### 10.3 Primer arranque

1. Abrir la URL.
2. Se ejecutan migraciones y se crea `staff` si no existe.
3. El PIN inicial aparece en el log (`[karaoke] Admin 'staff' creado...`) y en
   la sesión que provocó la creación.
4. Entrar como staff y cambiar el PIN.
5. Revisar/cambiar el código de la noche (`TRILLA24` por defecto).

### 10.4 Servidor web

El despliegue previsto es `karaoke-files/` fuera del `DocumentRoot` y
`public_html/karaoke` como enlace simbólico a `karaoke-files/public`:

- **Apache:** `public/.htaccess` bloquea dotfiles, `app/`, `config/`,
  `storage/`, `tests/`, `vendor/`, `node_modules/`, ficheros de build y
  documentación interna. El vhost necesita `FollowSymLinks` (o
  `SymLinksIfOwnerMatch`) y `AllowOverride` para aplicar el `.htaccess`.
- **nginx:** replicar las reglas de `DEPLOY.md`; `.htaccess` no se usa. nginx
  sigue enlaces simbólicos salvo que `disable_symlinks` esté activo.
- **CMS de la web principal:** su rewrite no debe capturar `/karaoke`; el
  enlace debe resolverse como directorio físico.
- **PHP built-in server:** **no aplica `.htaccess`**. Usar siempre
  `php -S localhost:8000 -t public`; arrancarlo desde la raíz del proyecto
  dejaría `app/`, `config/` o `storage/` al alcance del servidor. No exponerlo
  en `0.0.0.0`.

La seguridad no depende solo de reglas del servidor: `app/`, `config/`,
`storage/`, `vendor/` y `tests/` están físicamente fuera de `public/`.

### 10.5 Mantenimiento

- Purga recomendada de reacciones > 1 h por cron.
- No hay purga automática de `participants`, canciones `deleted` o históricos.
- `health` sirve para uptime checks, pero si la conexión a BD falla antes de
  enrutar, la respuesta actual no es JSON (ver 14).
- Backup antes de desplegar; el rollback es restaurar BD + paquete anterior.

### 10.6 Assets

- Tailwind se compila con `npm run build` y se commitea.
- En producción no se ejecuta npm.
- Si se añaden clases nuevas en `public/index.php`,
  `public/assets/js/app.js` o `public/assets/css/style.css`, hay que regenerar
  `public/assets/css/tailwind.min.css`.
- Todos los assets son locales, incluido el logo del estado vacío
  (`public/assets/img/logo-circular.svg`).

---

## 11. Testing y CI

### 11.1 Comandos

```bash
composer test   # PHPUnit
composer lint   # php -l de todo el proyecto
npm run build   # regenerar Tailwind
```

### 11.2 Arquitectura de tests

- `tests/bootstrap.php` crea `karaoke_test`, arranca un servidor PHP real en un
  puerto aleatorio con `public/` como document root y carga `app/db.php`.
- `KaraokeTestCase` reinicia la BD antes de cada test.
- `TestClient` mantiene cookies de sesión como un navegador.
- `QueueOptimizerTest` prueba la lógica pura sin BD.
- `SecurityTest` cubre métodos, CSRF, límites, PIN y sesiones.
- `StaticTest` cubre CSP, ausencia de inline handlers/styles e índices.

### 11.3 Cobertura actual

52 tests / 297 assertions. Áreas cubiertas:

- cola y optimizador;
- validación de código y rate limits;
- identidad de participante;
- login/logout y cambio de PIN;
- reordenado, borrado y límites de cola;
- reacciones y polling;
- cabeceras de seguridad.

### 11.4 Huecos conocidos

- Primer arranque y exposición del PIN inicial.
- Comportamiento con `X-Forwarded-For`/`X-Forwarded-Proto`.
- Caída de BD y respuesta de `health`.
- Concurrencia real (varios requests simultáneos).
- Frescura del CSS compilado.
- Pruebas de navegador/E2E y accesibilidad.

---

## 12. Convenciones de desarrollo

### 12.1 PHP

- `declare(strict_types=1)` en archivos PHP.
- PHP 8.4; evitar features que rompan 8.4.
- Sin framework ni autoload para `app/src/`; se usa `require_once`.
- Funciones helper en `app/db.php` si son transversales.
- Lógica pura en `app/src/` cuando se pueda testear sin BD.
- Respuestas API con `jsonResponse()`/`fail()`.
- Errores internos: log con `requestId()` y mensaje genérico al cliente.

### 12.2 Base de datos

- Consultas preparadas siempre.
- Migraciones idempotentes y numeradas.
- No asumir transacciones en DDL: MySQL hace commit implícito.
- Para operaciones de cola, usar transacción y locks cuando corresponda.
- Añadir índices si se consulta por columnas nuevas.

### 12.3 API

Checklist al añadir una acción:

1. Añadir la entrada a `ROUTES` con método y `adminOnly`.
2. Implementar el `case` en `public/api.php`.
3. Validar tipo, longitud y rango.
4. Aplicar CSRF si es de staff.
5. Aplicar rate limit si es abusable.
6. Devolver JSON consistente.
7. Añadir test de integración.
8. Documentarla en este archivo y en `README.md` si es pública.

### 12.4 Frontend

- Sin handlers inline ni `style="..."` (CSP).
- Delegación de eventos con `data-action`.
- Escapar datos de usuario.
- No añadir dependencias CDN.
- Mantener el polling y la firma de cola si no hay una razón fuerte para
  cambiarlos.
- Al añadir clases Tailwind, ejecutar `npm run build`.

### 12.5 Tests

- Todo cambio de comportamiento debe llevar test.
- Los tests de API usan servidor HTTP real y BD `karaoke_test`.
- No usar la BD de producción en tests.
- Mantener `failOnWarning`/`failOnNotice` limpios.

### 12.6 Documentación

- `README.md`: instalación, uso y API pública.
- `DEPLOY.md`: despliegue y operación.
- `SPEC.md`: esta especificación; actualizarla con cada cambio de contrato.
- `AGILE_REPORT.md`: histórico/obsoleto; no usarlo como fuente técnica.

---

## 13. Checklist de cambio

Antes de dar un cambio por terminado:

- [ ] ¿Respeta las reglas de cola e identidad?
- [ ] ¿La nueva acción está en `ROUTES` con método y permisos?
- [ ] ¿Valida entrada y limita longitudes?
- [ ] ¿Usa consultas preparadas?
- [ ] ¿Requiere CSRF si es de staff?
- [ ] ¿Necesita rate limit?
- [ ] ¿Usa transacción si toca la cola?
- [ ] ¿Escapa la salida en frontend?
- [ ] ¿Respeta la CSP (sin inline)?
- [ ] ¿Tiene tests?
- [ ] ¿`composer test` y `composer lint` pasan?
- [ ] ¿Hay que regenerar Tailwind?
- [ ] ¿Hay que actualizar `README.md`, `DEPLOY.md` o `SPEC.md`?
- [ ] ¿El cambio introduce secretos, CDNs o dependencias no declaradas?

---

## 14. Deuda técnica conocida

Revisión del **2026-11-14** sobre `d31033a`. No son fallos de la suite: son
riesgos de operación, hardening o mantenimiento.

### Alta prioridad

| Tema | Detalle | Recomendación |
|---|---|---|
| PIN inicial visible | `checkDefaultAdmin()` guarda el PIN en la sesión y `get_queue` lo devuelve como `initial_admin_pin`; el frontend lo muestra. La primera petición que crea el admin puede ser de un asistente. | No devolverlo por API; log/CLI, o mostrarlo solo desde loopback y forzar cambio en el primer login. |

### Prioridad media

| Tema | Detalle | Recomendación |
|---|---|---|
| Rate limit de validación por IP | 10 fallos/15 min por IP; detrás de NAT puede bloquear a todo el local y es un DoS fácil. | Límite por sesión más bajo + umbral de IP más alto, o backoff. |
| Reacciones solo por sesión | Se puede saltar el límite rotando cookies. | Añadir límite por IP o por participante. |
| `health` con BD caída | `app/db.php` hace `die()` antes de enrutar; el `try/catch` de `health` no cubre el fallo de conexión y la respuesta no es JSON. | Bootstrap que no muera o respuesta JSON 503. |

### Prioridad baja / mantenimiento

- `X-Forwarded-Proto` se acepta de cualquier cliente; alinear con
  `TRUSTED_PROXIES`.
- `get_reactions` es legado y no está documentado en el README; `currentQueue`
  no se usa; `AGILE_REPORT.md` está desactualizado; el docblock de migraciones
  habla de transacciones que no existen.
- `MAX_QUEUE` se comprueba fuera de la transacción de inserción.
- `auto_reorder` lee la cola antes de abrir la transacción.
- `auth_epoch` usa `time()`; dos cambios de PIN en el mismo segundo no
  invalidan sesiones.
- `update_staff_pin` actualiza hash y epoch sin transacción.
- No hay redirección HTTPS/HSTS ni instrucciones de `display_errors=Off` /
  `expose_php=Off`.
- No hay purga de `participants` ni de canciones `deleted`.
- El CI no compila Tailwind; se puede desplegar CSS desactualizado con CI verde.
- Los botones de validar/añadir no se deshabilitan durante el fetch; doble clic
  puede duplicar altas.
- El modal de login no tiene focus trap ni `aria-modal`.

### Deudas de diseño (no necesariamente errores)

- `songs.status = 'singing'` no se asigna; la UI asume que el primero canta.
- Las estadísticas usan `created_at`, no una fecha de finalización.
- No hay tabla de eventos/noches; el histórico es global.
- El código de noche por defecto (`TRILLA24`) es público; hay que cambiarlo en
  producción.

### Decisiones que no conviene romper sin evaluar

- Identidad por `participant_id`, no por nombre.
- Inserción de cola transaccional con `FOR UPDATE`.
- CSRF + whitelist de métodos en todas las acciones de staff.
- CSP sin `unsafe-inline`; eventos delegados.
- Rate limits persistidos en BD.
- Migraciones idempotentes y versionadas.
- Assets locales y CSS compilado en el repo.
- Tests contra servidor HTTP real y BD de test.

---

## 15. Historial de este documento

| Fecha | Commit | Cambio |
|---|---|---|
| 2026-11-14 | `d31033a` | Creación de la especificación a partir de la revisión completa del proyecto. |
| 2026-11-14 | sin commit | Refactor a `public/` + `app/` + `config/` + `storage/`; despliegue por symlink `/karaoke` → `karaoke-files/public`; CSP sin dominio remoto y logo local en SVG. |
