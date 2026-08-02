<?php
// api.php - REST API for Karaoke Operations
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

secureSessionStart();

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

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
 * Admin check + CSRF validation for mutating staff actions.
 */
function requireCsrfAdmin(): bool
{
    return isAdmin() && csrfValidate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
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

        $response = [
            'success' => true,
            'queue' => $stmt->fetchAll(),
            'night_code' => isAdmin() ? getNightCode($pdo) : null,
            'is_admin' => isAdmin(),
            'registration_enabled' => $registrationEnabled,
            'access_code_validated' => isset($_SESSION['access_code_validated']) &&
                                       isset($_SESSION['access_code_time']) &&
                                       (time() - $_SESSION['access_code_time']) < 28800, // 8 hours
            'reactions' => $stmtReactions->fetchAll(),
            'csrf_token' => csrfToken(),
            'initial_admin_pin' => $_SESSION['initial_admin_pin'] ?? null,
        ];
        break;

    case 'add_to_queue':
        $data = readJsonBody();
        $user_name = trim((string)($data['userName'] ?? ''));
        $song_title = trim((string)($data['songTitle'] ?? ''));
        $artist_name = trim((string)($data['artistName'] ?? ''));
        $access_code = strtoupper(trim((string)($data['accessCode'] ?? '')));

        // Check if registration is enabled
        $stmtReg = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'registration_enabled'");
        $stmtReg->execute();
        $regResult = $stmtReg->fetch();
        $registrationEnabled = $regResult !== false && (bool)$regResult['value'];

        if (!$registrationEnabled) {
            $response['message'] = 'El registro está cerrado en este momento';
            break;
        }

        if ($user_name === '' || $song_title === '' || $artist_name === '') {
            $response['message'] = 'Faltan campos obligatorios';
            break;
        }

        // Check session for access code validation
        $codeValidated = isset($_SESSION['access_code_validated']) &&
                         isset($_SESSION['access_code_time']) &&
                         (time() - $_SESSION['access_code_time']) < 28800; // 8 hours

        if (!$codeValidated) {
            $nightCode = getNightCode($pdo);
            if ($nightCode === '' || !hash_equals($nightCode, $access_code)) {
                $response['message'] = 'Código de local incorrecto';
                break;
            }
            // Save to session
            $_SESSION['access_code_validated'] = true;
            $_SESSION['access_code_time'] = time();
        }

        // Calculate new sort_order
        $stmtMax = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM songs WHERE status IN ('waiting', 'singing')");
        $stmtMax->execute();
        $maxOrder = $stmtMax->fetchColumn();
        $newSortOrder = ($maxOrder !== false && $maxOrder !== null) ? (int)$maxOrder + 1 : 1;

        $stmt = $pdo->prepare("INSERT INTO songs (user_name, song_title, artist_name, sort_order) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$user_name, $song_title, $artist_name, $newSortOrder])) {
            $response = ['success' => true, 'message' => 'Registrado con éxito'];
        }
        break;

    case 'login':
        $data = readJsonBody();
        $pin = (string)($data['pin'] ?? '');

        // Rate limiting for login (session + IP)
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = 0;
            $_SESSION['last_attempt_time'] = 0;
        }

        $currentTime = time();
        $lockoutTime = 300; // 5 minutes lockout
        $maxAttempts = 5;

        if ($_SESSION['login_attempts'] >= $maxAttempts && ($currentTime - $_SESSION['last_attempt_time']) < $lockoutTime) {
            $remaining = $lockoutTime - ($currentTime - $_SESSION['last_attempt_time']);
            $response['message'] = "Demasiados intentos. Intente en " . ceil($remaining / 60) . " minutos.";
            break;
        }

        $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE username = 'staff'");
        $stmt->execute();
        $admin = $stmt->fetch();

        if ($admin !== false && password_verify($pin, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_auth'] = true;
            $_SESSION['login_attempts'] = 0; // Reset attempts on success
            unset($_SESSION['initial_admin_pin']); // El PIN inicial ya no se muestra
            $response = ['success' => true, 'message' => 'Acceso Staff Concedido'];
        } else {
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = $currentTime;
            $remainingAttempts = $maxAttempts - $_SESSION['login_attempts'];

            if ($remainingAttempts <= 0) {
                $response['message'] = 'PIN Incorrecto. Acceso bloqueado por 5 minutos.';
            } else {
                $response['message'] = "PIN Staff Incorrecto. Intentos restantes: $remainingAttempts";
            }
        }
        break;

    case 'logout':
        if (!requireCsrfAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $_SESSION['admin_auth'] = false;
        session_destroy();
        $response = ['success' => true, 'message' => 'Sesión Staff Cerrada'];
        break;

    case 'reorder_queue':
        if (!requireCsrfAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $data = readJsonBody();
        $orderedIds = $data['orderedIds'] ?? [];

        if (!is_array($orderedIds) || empty($orderedIds)) {
            $response['message'] = 'Lista vacía';
            break;
        }

        $validIds = array_map('intval', $orderedIds);
        foreach ($validIds as $id) {
            if ($id <= 0) {
                $response['message'] = 'IDs inválidos';
                break 2;
            }
        }

        // The submitted list must match the active queue exactly
        $stmt = $pdo->query("SELECT id FROM songs WHERE status IN ('waiting', 'singing')");
        $activeIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        if (array_diff($validIds, $activeIds) || array_diff($activeIds, $validIds)) {
            $response['message'] = 'La lista no coincide con la cola activa';
            break;
        }

        $pdo->beginTransaction();
        try {
            $sql = "UPDATE songs SET sort_order = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            foreach ($validIds as $index => $id) {
                $stmt->execute([$index + 1, $id]);
            }
            $pdo->commit();
            $response = ['success' => true, 'message' => 'Cola reordenada'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $response['message'] = 'Error al reordenar';
            error_log("reorder_queue: " . $e->getMessage());
        }
        break;

    case 'auto_reorder':
        if (!requireCsrfAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }

        // 1. Get current waiting queue
        $stmt = $pdo->prepare("SELECT id, user_name FROM songs WHERE status = 'waiting' ORDER BY sort_order ASC, id ASC");
        $stmt->execute();
        $queue = $stmt->fetchAll();

        if (empty($queue)) {
            $response['message'] = 'No hay canciones en espera';
            break;
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
            $response = ['success' => true, 'message' => 'Cola optimizada con éxito'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $response['message'] = 'Error al actualizar';
            error_log("auto_reorder: " . $e->getMessage());
        }
        break;

    case 'remove_from_queue':
        if (!requireCsrfAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $data = readJsonBody();
        $id = $data['id'] ?? null;

        if (!is_numeric($id) || (int)$id <= 0) {
            $response['message'] = 'ID inválido';
            break;
        }

        $stmt = $pdo->prepare("UPDATE songs SET status = 'finished' WHERE id = ? AND status IN ('waiting', 'singing')");
        $stmt->execute([(int)$id]);
        if ($stmt->rowCount() > 0) {
            $response = ['success' => true, 'message' => 'Cola actualizada'];
        } else {
            $response['message'] = 'Participante no encontrado en la cola';
        }
        break;

    case 'clear_queue':
        if (!requireCsrfAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $stmt = $pdo->prepare("UPDATE songs SET status = 'deleted' WHERE status IN ('waiting', 'singing')");
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Lista vaciada'];
        }
        break;

    case 'update_night_code':
        if (!requireCsrfAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $data = readJsonBody();
        $new_code = strtoupper(trim((string)($data['newCode'] ?? '')));

        if ($new_code === '') {
            $response['message'] = 'El código no puede estar vacío';
            break;
        }

        $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('night_code', ?)");
        if ($stmt->execute([$new_code])) {
            $response = ['success' => true, 'message' => 'Código de la noche actualizado'];
        }
        break;

    case 'update_staff_pin':
        if (!requireCsrfAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $data = readJsonBody();
        $new_pin = trim((string)($data['newPin'] ?? ''));

        if (strlen($new_pin) !== 4 || !is_numeric($new_pin)) {
            $response['message'] = 'El PIN debe ser de 4 dígitos numéricos';
            break;
        }

        $hash = password_hash($new_pin, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE username = 'staff'");
        if ($stmt->execute([$hash])) {
            $response = ['success' => true, 'message' => 'PIN de Staff actualizado con éxito'];
        }
        break;

    case 'toggle_registration':
        if (!requireCsrfAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $data = readJsonBody();
        $enabled = isset($data['enabled']) ? ($data['enabled'] ? '1' : '0') : '1';

        $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('registration_enabled', ?)");
        if ($stmt->execute([$enabled])) {
            $message = $enabled === '1' ? 'Registro habilitado' : 'Registro deshabilitado';
            $response = ['success' => true, 'message' => $message];
        }
        break;

    case 'send_reaction':
        $data = readJsonBody();
        $emoji = trim((string)($data['emoji'] ?? ''));

        $allowedEmojis = ['👏', '🔥', '❤️', '😂', '🤩', '😱', '🙉', '🍅'];
        if (!in_array($emoji, $allowedEmojis, true)) {
            $response['message'] = 'Emoji no permitido';
            break;
        }

        // Rate limit: max 15 reactions per 30 seconds per session
        $now = time();
        if (($_SESSION['reaction_window_start'] ?? 0) < $now - 30) {
            $_SESSION['reaction_window_start'] = $now;
            $_SESSION['reaction_count'] = 0;
        }
        if (($_SESSION['reaction_count'] ?? 0) >= 15) {
            $response['message'] = 'Demasiadas reacciones. Espera un momento.';
            break;
        }

        $stmt = $pdo->prepare("INSERT INTO reactions (emoji) VALUES (?)");
        if ($stmt->execute([$emoji])) {
            $_SESSION['reaction_count'] = ($_SESSION['reaction_count'] ?? 0) + 1;
            $response = ['success' => true, 'id' => (int)$pdo->lastInsertId()];
        }
        cleanupReactions($pdo);
        break;

    case 'get_reactions':
        $since = max(0, (int)($_GET['since'] ?? 0));
        $stmt = $pdo->prepare("SELECT id, emoji FROM reactions WHERE id > ? AND created_at >= NOW() - INTERVAL 30 SECOND ORDER BY id ASC");
        $stmt->execute([$since]);
        $response = [
            'success' => true,
            'reactions' => $stmt->fetchAll()
        ];
        break;
}

echo json_encode($response);
