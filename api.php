<?php
// api.php - REST API for Karaoke Operations
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/db.php';

secureSessionStart();
sendSecurityHeaders();

/** Máximo de turnos activos (espera + cantando). */
const MAX_QUEUE = 80;

/** Ventanas de rate limiting (segundos). */
const RL_LOGIN_WINDOW = 300;          // bloqueo por sesión tras 5 fallos
const RL_LOGIN_IP_WINDOW = 900;       // red de seguridad por IP
const RL_LOGIN_IP_MAX = 15;
const RL_REGISTER_WINDOW = 600;       // anti-spam por sesión
const RL_REGISTER_SESSION_MAX = 12;
const RL_REGISTER_IP_WINDOW = 3600;   // holgado: en un bar todos comparten IP
const RL_REGISTER_IP_MAX = 150;

/** Límites de longitud de los campos del formulario. */
const MAX_NAME = 60;
const MAX_SONG = 120;
const MAX_ARTIST = 80;

/**
 * Acción => [método HTTP, requiere staff + CSRF].
 * Añadir una acción aquí es obligatorio: sin entrada no se ejecuta.
 */
const ROUTES = [
    'get_queue'           => ['GET', false],
    'get_reactions'       => ['GET', false],
    'add_to_queue'        => ['POST', false],
    'send_reaction'       => ['POST', false],
    'login'               => ['POST', false],
    'logout'              => ['POST', true],
    'reorder_queue'       => ['POST', true],
    'auto_reorder'        => ['POST', true],
    'remove_from_queue'   => ['POST', true],
    'clear_queue'         => ['POST', true],
    'update_night_code'   => ['POST', true],
    'update_staff_pin'    => ['POST', true],
    'toggle_registration' => ['POST', true],
];

/**
 * Envía una respuesta JSON y termina la ejecución.
 */
function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Respuesta de error uniforme.
 */
function fail(string $message, int $status = 400): never
{
    jsonResponse(['success' => false, 'message' => $message], $status);
}

/**
 * Read and decode the JSON request body.
 */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw !== false ? $raw : '', true);
    return is_array($data) ? $data : [];
}

/**
 * Scalar request field as a trimmed string (arrays/objects become '').
 */
function inputString(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

/**
 * Required, trimmed and length-limited text field.
 */
function requireText(array $data, string $key, string $label, int $max): string
{
    $value = preg_replace('/\s+/u', ' ', inputString($data, $key));
    $value = $value ?? '';

    if ($value === '') {
        fail('Faltan campos obligatorios', 422);
    }
    if (mb_strlen($value) > $max) {
        fail("El campo {$label} no puede superar {$max} caracteres", 422);
    }
    return $value;
}

/**
 * Admin check + CSRF validation for mutating staff actions.
 */
function requireCsrfAdmin(PDO $pdo): bool
{
    return isAdmin($pdo) && csrfValidate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
}

/**
 * Número de turnos activos en la cola.
 */
function activeQueueSize(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM songs WHERE status IN ('waiting', 'singing')")
        ->fetchColumn();
}

/**
 * Inserta un turno al final de la cola de forma atómica.
 * El FOR UPDATE serializa el cálculo de sort_order entre registros concurrentes.
 */
function insertSongInQueue(PDO $pdo, string $userName, string $songTitle, string $artistName): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT sort_order FROM songs
             WHERE status IN ('waiting', 'singing')
             ORDER BY sort_order DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute();
        $maxOrder = $stmt->fetchColumn();
        $nextOrder = $maxOrder === false ? 1 : (int)$maxOrder + 1;

        $stmt = $pdo->prepare(
            "INSERT INTO songs (user_name, song_title, artist_name, sort_order) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$userName, $songTitle, $artistName, $nextOrder]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Opportunistic cleanup of old reactions (2% per request).
 */
function cleanupReactions(PDO $pdo): void
{
    if (random_int(1, 100) <= 2) {
        $pdo->exec("DELETE FROM reactions WHERE created_at < NOW() - INTERVAL 1 HOUR");
    }
}

// ---------------------------------------------------------------------------
// Routing: action -> method + auth. Everything else is rejected before running.
// ---------------------------------------------------------------------------
$action = (string)($_GET['action'] ?? '');
$route = ROUTES[$action] ?? null;

if ($route === null) {
    fail('Invalid action', 400);
}

[$method, $adminOnly] = $route;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
    header('Allow: ' . $method);
    fail('Método no permitido', 405);
}

if ($adminOnly && !requireCsrfAdmin($pdo)) {
    fail('Acceso denegado', 403);
}

try {
    switch ($action) {
        case 'get_queue':
            $stmt = $pdo->prepare("SELECT id, user_name, song_title, artist_name, status FROM songs WHERE status IN ('waiting', 'singing') ORDER BY sort_order ASC, id ASC");
            $stmt->execute();

            // Get registration status
            $stmtReg = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'registration_enabled'");
            $stmtReg->execute();
            $regResult = $stmtReg->fetch();
            $registrationEnabled = $regResult !== false && (bool)$regResult['value'];

            // Unified polling: new reactions since the client's last id
            $since = max(0, (int)($_GET['since'] ?? 0));
            $stmtReactions = $pdo->prepare("SELECT id, emoji FROM reactions WHERE id > ? AND created_at >= NOW() - INTERVAL 30 SECOND ORDER BY id ASC");
            $stmtReactions->execute([$since]);

            cleanupReactions($pdo);

            $admin = isAdmin($pdo);

            jsonResponse([
                'success' => true,
                'queue' => $stmt->fetchAll(),
                'night_code' => $admin ? getNightCode($pdo) : null,
                'is_admin' => $admin,
                'registration_enabled' => $registrationEnabled,
                'access_code_validated' => isset($_SESSION['access_code_validated']) &&
                                           isset($_SESSION['access_code_time']) &&
                                           (time() - $_SESSION['access_code_time']) < 28800, // 8 hours
                'reactions' => $stmtReactions->fetchAll(),
                'csrf_token' => csrfToken(),
                'initial_admin_pin' => $_SESSION['initial_admin_pin'] ?? null,
            ]);

        case 'add_to_queue':
            $data = readJsonBody();

            // Check if registration is enabled
            $stmtReg = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'registration_enabled'");
            $stmtReg->execute();
            $regResult = $stmtReg->fetch();
            $registrationEnabled = $regResult !== false && (bool)$regResult['value'];

            if (!$registrationEnabled) {
                fail('El registro está cerrado en este momento', 403);
            }

            $user_name = requireText($data, 'userName', 'nombre', MAX_NAME);
            $song_title = requireText($data, 'songTitle', 'canción', MAX_SONG);
            $artist_name = requireText($data, 'artistName', 'artista', MAX_ARTIST);
            $access_code = strtoupper(inputString($data, 'accessCode'));

            // Rate limit: por sesión (anti-spam) y por IP (red de seguridad para NAT)
            $sessionKey = 'sess:' . session_id();
            $ip = clientIp();

            if (rateLimitCount($pdo, 'register_session', $sessionKey, RL_REGISTER_WINDOW) >= RL_REGISTER_SESSION_MAX) {
                fail('Demasiados registros seguidos. Espera unos minutos.', 429);
            }
            if (rateLimitCount($pdo, 'register_ip', $ip, RL_REGISTER_IP_WINDOW) >= RL_REGISTER_IP_MAX) {
                fail('Demasiados registros desde esta conexión. Intenta más tarde.', 429);
            }

            if (activeQueueSize($pdo) >= MAX_QUEUE) {
                fail('La lista está completa. Pide al staff que la vacíe.', 409);
            }

            // Check session for access code validation
            $codeValidated = isset($_SESSION['access_code_validated']) &&
                             isset($_SESSION['access_code_time']) &&
                             (time() - $_SESSION['access_code_time']) < 28800; // 8 hours

            if (!$codeValidated) {
                $nightCode = getNightCode($pdo);
                if ($nightCode === '' || !hash_equals($nightCode, $access_code)) {
                    fail('Código de local incorrecto', 403);
                }
                // Save to session
                $_SESSION['access_code_validated'] = true;
                $_SESSION['access_code_time'] = time();
            }

            // Insert at the end of the active queue (atomic sort_order)
            insertSongInQueue($pdo, $user_name, $song_title, $artist_name);

            rateLimitIncrement($pdo, 'register_session', $sessionKey, RL_REGISTER_WINDOW);
            rateLimitIncrement($pdo, 'register_ip', $ip, RL_REGISTER_IP_WINDOW);

            jsonResponse(['success' => true, 'message' => 'Registrado con éxito']);

        case 'login':
            $data = readJsonBody();
            $pin = inputString($data, 'pin');
            $ip = clientIp();
            $now = time();
            $maxAttempts = 5;

            // Rate limiting by session (fast feedback) and by IP (can't be reset
            // by dropping the cookie), both persisted in the database.
            $_SESSION['login_attempts'] ??= 0;
            $_SESSION['last_attempt_time'] ??= 0;

            if ($_SESSION['login_attempts'] >= $maxAttempts && ($now - $_SESSION['last_attempt_time']) < RL_LOGIN_WINDOW) {
                $remaining = RL_LOGIN_WINDOW - ($now - $_SESSION['last_attempt_time']);
                fail('Demasiados intentos. Intente en ' . ceil($remaining / 60) . ' minutos.', 429);
            }

            if (rateLimitCount($pdo, 'login_ip', $ip, RL_LOGIN_IP_WINDOW) >= RL_LOGIN_IP_MAX) {
                fail('Demasiados intentos desde esta conexión. Intente más tarde.', 429);
            }

            $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE username = 'staff'");
            $stmt->execute();
            $admin = $stmt->fetch();

            if ($admin !== false && password_verify($pin, $admin['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_auth'] = true;
                $_SESSION['auth_epoch'] = authEpoch($pdo, true);
                $_SESSION['login_attempts'] = 0;
                unset($_SESSION['initial_admin_pin']); // El PIN inicial ya no se muestra
                rateLimitReset($pdo, 'login_ip', $ip);

                jsonResponse(['success' => true, 'message' => 'Acceso Staff Concedido']);
            }

            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = $now;
            rateLimitIncrement($pdo, 'login_ip', $ip, RL_LOGIN_IP_WINDOW);
            error_log('[karaoke] Login fallido desde ' . $ip);

            $remainingAttempts = $maxAttempts - $_SESSION['login_attempts'];
            if ($remainingAttempts <= 0) {
                fail('PIN Incorrecto. Acceso bloqueado por 5 minutos.', 429);
            }
            fail("PIN Staff Incorrecto. Intentos restantes: $remainingAttempts", 401);

        case 'logout':
            $_SESSION['admin_auth'] = false;
            session_destroy();
            jsonResponse(['success' => true, 'message' => 'Sesión Staff Cerrada']);

        case 'reorder_queue':
            $data = readJsonBody();
            $orderedIds = $data['orderedIds'] ?? [];

            if (!is_array($orderedIds) || $orderedIds === []) {
                fail('Lista vacía', 422);
            }

            $validIds = [];
            foreach ($orderedIds as $rawId) {
                if (!is_int($rawId) && !(is_string($rawId) && ctype_digit($rawId))) {
                    fail('IDs inválidos', 422);
                }
                $id = (int)$rawId;
                if ($id <= 0) {
                    fail('IDs inválidos', 422);
                }
                $validIds[] = $id;
            }

            if (count($validIds) !== count(array_unique($validIds))) {
                fail('La lista contiene IDs duplicados', 422);
            }

            // The submitted list must match the active queue exactly
            $stmt = $pdo->query("SELECT id FROM songs WHERE status IN ('waiting', 'singing')");
            $activeIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

            $submitted = $validIds;
            $active = $activeIds;
            sort($submitted);
            sort($active);

            if ($submitted !== $active) {
                fail('La lista no coincide con la cola activa', 409);
            }

            $pdo->beginTransaction();
            try {
                $sql = "UPDATE songs SET sort_order = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                foreach ($validIds as $index => $id) {
                    $stmt->execute([$index + 1, $id]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            jsonResponse(['success' => true, 'message' => 'Cola reordenada']);

        case 'auto_reorder':
            // 1. Get current waiting queue
            $stmt = $pdo->prepare("SELECT id, user_name FROM songs WHERE status = 'waiting' ORDER BY sort_order ASC, id ASC");
            $stmt->execute();
            $queue = $stmt->fetchAll();

            if (empty($queue)) {
                fail('No hay canciones en espera', 409);
            }

            // 2. Identify new singers (0 finished songs)
            $usersInQueue = array_unique(array_column($queue, 'user_name'));
            $stats = [];
            if (!empty($usersInQueue)) {
                $placeholders = implode(',', array_fill(0, count($usersInQueue), '?'));
                $stmtStats = $pdo->prepare("SELECT user_name, COUNT(*) as finished_count FROM songs WHERE status = 'finished' AND created_at >= NOW() - INTERVAL 12 HOUR AND user_name IN ($placeholders) GROUP BY user_name");
                $stmtStats->execute(array_values($usersInQueue));
                $statsResult = $stmtStats->fetchAll();
                foreach ($statsResult as $row) {
                    $stats[$row['user_name']] = (int)$row['finished_count'];
                }
            }

            // Fill missing users with 0
            foreach ($usersInQueue as $user) {
                $stats[$user] ??= 0;
            }

            // 3. Move newbies forward if they are far (index >= 6)
            $newbies = [];
            $others = [];
            foreach ($queue as $index => $song) {
                if ($stats[$song['user_name']] === 0 && $index >= 6) {
                    $newbies[] = $song;
                } else {
                    $others[] = $song;
                }
            }

            // Insert newbies at position 5 (6th place), preserving their order
            array_splice($others, 5, 0, $newbies);

            $reordered = $others;

            // 4. Redistribute to avoid consecutive singers and ensure 4-turn gap
            $finalQueue = [];
            $pool = $reordered;
            $history = [];
            $maxGap = 4;

            while (!empty($pool)) {
                $found = false;
                foreach ($pool as $index => $song) {
                    if (!in_array($song['user_name'], $history, true)) {
                        $finalQueue[] = $song;

                        array_unshift($history, $song['user_name']);
                        if (count($history) > $maxGap) {
                            array_pop($history);
                        }

                        array_splice($pool, $index, 1);
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    foreach ($pool as $index => $song) {
                        if (empty($history) || $song['user_name'] !== $history[0]) {
                            $finalQueue[] = $song;
                            array_unshift($history, $song['user_name']);
                            if (count($history) > $maxGap) {
                                array_pop($history);
                            }
                            array_splice($pool, $index, 1);
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        $song = array_shift($pool);
                        $finalQueue[] = $song;
                        array_unshift($history, $song['user_name']);
                        if (count($history) > $maxGap) {
                            array_pop($history);
                        }
                    }
                }
            }

            // 5. Renumber all active songs sequentially, then place optimized queue after singing
            $pdo->beginTransaction();
            try {
                $stmtAll = $pdo->query("SELECT id, status FROM songs WHERE status IN ('waiting', 'singing') ORDER BY sort_order ASC, id ASC");
                $allActive = $stmtAll->fetchAll();

                $stmtUpdate = $pdo->prepare("UPDATE songs SET sort_order = ? WHERE id = ?");
                $order = 0;
                $baseOrder = 0;
                foreach ($allActive as $row) {
                    $order++;
                    $stmtUpdate->execute([$order, $row['id']]);
                    if ($row['status'] === 'singing') {
                        $baseOrder = $order;
                    }
                }

                foreach ($finalQueue as $index => $song) {
                    $stmtUpdate->execute([$baseOrder + $index + 1, $song['id']]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            jsonResponse(['success' => true, 'message' => 'Cola optimizada con éxito']);

        case 'remove_from_queue':
            $data = readJsonBody();
            $id = $data['id'] ?? null;

            if (!is_numeric($id) || (int)$id <= 0) {
                fail('ID inválido', 422);
            }

            $stmt = $pdo->prepare("UPDATE songs SET status = 'finished' WHERE id = ? AND status IN ('waiting', 'singing')");
            $stmt->execute([(int)$id]);

            if ($stmt->rowCount() === 0) {
                fail('Participante no encontrado en la cola', 404);
            }

            jsonResponse(['success' => true, 'message' => 'Cola actualizada']);

        case 'clear_queue':
            $stmt = $pdo->prepare("UPDATE songs SET status = 'deleted' WHERE status IN ('waiting', 'singing')");
            $stmt->execute();
            jsonResponse(['success' => true, 'message' => 'Lista vaciada']);

        case 'update_night_code':
            $data = readJsonBody();
            $new_code = strtoupper(inputString($data, 'newCode'));

            if ($new_code === '') {
                fail('El código no puede estar vacío', 422);
            }
            if (preg_match('/^[A-Z0-9_-]{4,32}$/', $new_code) !== 1) {
                fail('El código debe tener entre 4 y 32 caracteres (letras, números, guion o guion bajo)', 422);
            }

            $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('night_code', ?)");
            $stmt->execute([$new_code]);
            jsonResponse(['success' => true, 'message' => 'Código de la noche actualizado']);

        case 'update_staff_pin':
            $data = readJsonBody();
            $new_pin = inputString($data, 'newPin');

            if (preg_match('/^\d{4}$/', $new_pin) !== 1) {
                fail('El PIN debe ser de 4 dígitos numéricos', 422);
            }

            $hash = password_hash($new_pin, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE username = 'staff'");
            $stmt->execute([$hash]);

            // Invalida el resto de sesiones de staff; la actual se refresca abajo
            $epoch = time();
            $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('auth_epoch', ?)");
            $stmt->execute([(string)$epoch]);
            $_SESSION['auth_epoch'] = authEpoch($pdo, true);

            jsonResponse(['success' => true, 'message' => 'PIN de Staff actualizado con éxito']);

        case 'toggle_registration':
            $data = readJsonBody();
            $enabled = filter_var($data['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';

            $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('registration_enabled', ?)");
            $stmt->execute([$enabled]);

            $message = $enabled === '1' ? 'Registro habilitado' : 'Registro deshabilitado';
            jsonResponse(['success' => true, 'message' => $message]);

        case 'send_reaction':
            $data = readJsonBody();
            $emoji = inputString($data, 'emoji');

            $allowedEmojis = ['👏', '🔥', '❤️', '😂', '🤩', '😱', '🙉', '🍅'];
            if (!in_array($emoji, $allowedEmojis, true)) {
                fail('Emoji no permitido', 422);
            }

            // Rate limit: max 15 reactions per 30 seconds per session
            $now = time();
            if (($_SESSION['reaction_window_start'] ?? 0) < $now - 30) {
                $_SESSION['reaction_window_start'] = $now;
                $_SESSION['reaction_count'] = 0;
            }
            if (($_SESSION['reaction_count'] ?? 0) >= 15) {
                fail('Demasiadas reacciones. Espera un momento.', 429);
            }

            $stmt = $pdo->prepare("INSERT INTO reactions (emoji) VALUES (?)");
            $stmt->execute([$emoji]);
            $_SESSION['reaction_count'] = ($_SESSION['reaction_count'] ?? 0) + 1;

            cleanupReactions($pdo);

            jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId()]);

        case 'get_reactions':
            $since = max(0, (int)($_GET['since'] ?? 0));
            $stmt = $pdo->prepare("SELECT id, emoji FROM reactions WHERE id > ? AND created_at >= NOW() - INTERVAL 30 SECOND ORDER BY id ASC");
            $stmt->execute([$since]);

            jsonResponse([
                'success' => true,
                'reactions' => $stmt->fetchAll()
            ]);
    }
} catch (Throwable $e) {
    error_log('[karaoke] ' . $action . ': ' . $e->getMessage());
    fail('Error interno del servidor', 500);
}

// El switch siempre termina en jsonResponse()/fail(); esto es inalcanzable.
fail('Invalid action', 400);
