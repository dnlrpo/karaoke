<?php
// api.php - REST API for Karaoke Operations
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

session_start();

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

switch ($action) {
    case 'get_queue':
        $stmt = $pdo->prepare("SELECT id, user_name, song_title, artist_name, status FROM songs WHERE status IN ('waiting', 'singing') ORDER BY sort_order ASC, id ASC");
        $stmt->execute();
        
        // Get registration status
        $stmtReg = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'registration_enabled'");
        $stmtReg->execute();
        $regResult = $stmtReg->fetch();
        $registrationEnabled = $regResult ? (bool)$regResult['value'] : true;
        
        $response = [
            'success' => true,
            'queue' => $stmt->fetchAll(),
            'night_code' => isAdmin() ? getNightCode($pdo) : null,
            'is_admin' => isAdmin(),
            'registration_enabled' => $registrationEnabled,
            'access_code_validated' => isset($_SESSION['access_code_validated']) && 
                                       isset($_SESSION['access_code_time']) && 
                                       (time() - $_SESSION['access_code_time']) < 28800 // 8 hours
        ];
        break;

    case 'add_to_queue':
        $data = json_decode(file_get_contents('php://input'), true);
        $user_name = trim($data['userName'] ?? '');
        $song_title = trim($data['songTitle'] ?? '');
        $artist_name = trim($data['artistName'] ?? '');
        $access_code = strtoupper(trim($data['accessCode'] ?? ''));

        // Check if registration is enabled
        $stmtReg = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'registration_enabled'");
        $stmtReg->execute();
        $regResult = $stmtReg->fetch();
        $registrationEnabled = $regResult ? (bool)$regResult['value'] : true;
        
        if (!$registrationEnabled) {
            $response['message'] = 'El registro está cerrado en este momento';
            break;
        }

        if (!$user_name || !$song_title || !$artist_name) {
            $response['message'] = 'Faltan campos obligatorios';
            break;
        }

        // Check session for access code validation
        $codeValidated = isset($_SESSION['access_code_validated']) && 
                        isset($_SESSION['access_code_time']) && 
                        (time() - $_SESSION['access_code_time']) < 28800; // 8 hours

        if (!$codeValidated) {
            // Need to validate access code
            if ($access_code !== getNightCode($pdo)) {
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
        $newSortOrder = ($maxOrder !== false) ? $maxOrder + 1 : 1;

        $stmt = $pdo->prepare("INSERT INTO songs (user_name, song_title, artist_name, sort_order) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$user_name, $song_title, $artist_name, $newSortOrder])) {
            $response = ['success' => true, 'message' => 'Registrado con éxito'];
        }
        break;

    case 'login':
        $data = json_decode(file_get_contents('php://input'), true);
        $pin = $data['pin'] ?? '';

        // Rate limiting for login
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

        if ($admin && password_verify($pin, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_auth'] = true;
            $_SESSION['login_attempts'] = 0; // Reset attempts on success
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
        $_SESSION['admin_auth'] = false;
        session_destroy();
        $response = ['success' => true, 'message' => 'Sesión Staff Cerrada'];
        break;

    case 'reorder_queue':
        if (!isAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $orderedIds = $data['orderedIds'] ?? [];

        if (empty($orderedIds)) {
            $response['message'] = 'Lista vacía';
            break;
        }

        $pdo->beginTransaction();
        try {
            $sql = "UPDATE songs SET sort_order = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            foreach ($orderedIds as $index => $id) {
                $stmt->execute([$index + 1, $id]);
            }
            $pdo->commit();
            $response = ['success' => true, 'message' => 'Cola reordenada'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $response['message'] = 'Error al reordenar: ' . $e->getMessage();
        }
        break;

    case 'auto_reorder':
        if (!isAdmin()) {
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
        // First get all unique users in queue
        $usersInQueue = array_unique(array_column($queue, 'user_name'));
        $stats = [];
        if (!empty($usersInQueue)) {
            $placeholders = implode(',', array_fill(0, count($usersInQueue), '?'));
            $stmtStats = $pdo->prepare("SELECT user_name, COUNT(*) as finished_count FROM songs WHERE status = 'finished' AND created_at >= NOW() - INTERVAL 12 HOUR AND user_name IN ($placeholders) GROUP BY user_name");
            $stmtStats->execute(array_values($usersInQueue));
            $statsResult = $stmtStats->fetchAll();
            foreach ($statsResult as $row) {
                $stats[$row['user_name']] = $row['finished_count'];
            }
        }

        // Fill missing users with 0
        foreach ($usersInQueue as $user) {
            if (!isset($stats[$user])) {
                $stats[$user] = 0;
            }
        }

        // 3. Move newbies forward if they are far (index >= 6)
        $newbies = [];
        $others = [];
        foreach ($queue as $index => $song) {
            if ($stats[$song['user_name']] == 0 && $index >= 6) {
                $newbies[] = $song;
            } else {
                $others[] = $song;
            }
        }

        // Insert newbies at position 5 (6th place)
        foreach ($newbies as $newbie) {
            array_splice($others, 5, 0, [$newbie]);
        }
        
        $reordered = $others;

        // 4. Redistribute to avoid consecutive singers and ensure 4-turn gap
        $finalQueue = [];
        $pool = $reordered;
        // Keep track of the last few users to ensure a gap (up to 4)
        $history = []; 
        $maxGap = 4;

        while (!empty($pool)) {
            $found = false;
            foreach ($pool as $index => $song) {
                // Check if user has sung in the last $maxGap turns
                if (!in_array($song['user_name'], $history)) {
                    $finalQueue[] = $song;
                    
                    // Add to history and maintain size
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
                // If we can't satisfy the gap, try to satisfy at least non-consecutive
                foreach ($pool as $index => $song) {
                    if (empty($history) || $song['user_name'] !== $history[0]) {
                        $finalQueue[] = $song;
                        array_unshift($history, $song['user_name']);
                        if (count($history) > $maxGap) array_pop($history);
                        array_splice($pool, $index, 1);
                        $found = true;
                        break;
                    }
                }
                
                // If still not found, we have to take the first one available
                if (!$found) {
                    $song = array_shift($pool);
                    $finalQueue[] = $song;
                    array_unshift($history, $song['user_name']);
                    if (count($history) > $maxGap) array_pop($history);
                }
            }
        }

        // 5. Update database
        $pdo->beginTransaction();
        try {
            // Get max sort_order from singing status to start from there
            $stmtSinging = $pdo->prepare("SELECT MAX(sort_order) FROM songs WHERE status = 'singing'");
            $stmtSinging->execute();
            $baseOrder = (int)$stmtSinging->fetchColumn();
            
            $stmtUpdate = $pdo->prepare("UPDATE songs SET sort_order = ? WHERE id = ?");
            foreach ($finalQueue as $index => $song) {
                $stmtUpdate->execute([$baseOrder + $index + 1, $song['id']]);
            }
            $pdo->commit();
            $response = ['success' => true, 'message' => 'Cola optimizada con éxito'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $response['message'] = 'Error al actualizar: ' . $e->getMessage();
        }
        break;

    case 'remove_from_queue':
        if (!isAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;

        $stmt = $pdo->prepare("UPDATE songs SET status = 'finished' WHERE id = ?");
        if ($stmt->execute([$id])) {
            $response = ['success' => true, 'message' => 'Cola actualizada'];
        }
        break;

    case 'clear_queue':
        if (!isAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $stmt = $pdo->prepare("UPDATE songs SET status = 'deleted' WHERE status IN ('waiting', 'singing')");
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Lista vaciada'];
        }
        break;

    case 'update_night_code':
        if (!isAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $new_code = strtoupper(trim($data['newCode'] ?? ''));

        if (!$new_code) {
            $response['message'] = 'El código no puede estar vacío';
            break;
        }

        $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('night_code', ?)");
        if ($stmt->execute([$new_code])) {
            $response = ['success' => true, 'message' => 'Código de la noche actualizado'];
        }
        break;

    case 'update_staff_pin':
        if (!isAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $new_pin = trim($data['newPin'] ?? '');

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
        if (!isAdmin()) {
            $response['message'] = 'Acceso denegado';
            break;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $enabled = isset($data['enabled']) ? ($data['enabled'] ? '1' : '0') : '1';

        $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('registration_enabled', ?)");
        if ($stmt->execute([$enabled])) {
            $message = $enabled === '1' ? 'Registro habilitado' : 'Registro deshabilitado';
            $response = ['success' => true, 'message' => $message];
        }
        break;

    case 'send_reaction':
        $data = json_decode(file_get_contents('php://input'), true);
        $emoji = trim($data['emoji'] ?? '');
        if ($emoji) {
            $stmt = $pdo->prepare("INSERT INTO reactions (emoji) VALUES (?)");
            if ($stmt->execute([$emoji])) {
                $response = ['success' => true];
            }
        }
        break;

    case 'get_reactions':
        $since = (int)($_GET['since'] ?? 0);
        // Solo traer reacciones de los últimos 30 segundos para evitar sobrecarga si el ID es muy viejo
        $stmt = $pdo->prepare("SELECT id, emoji FROM reactions WHERE id > ? AND created_at >= NOW() - INTERVAL 30 SECOND ORDER BY id ASC");
        $stmt->execute([$since]);
        $response = [
            'success' => true,
            'reactions' => $stmt->fetchAll()
        ];
        break;
}

echo json_encode($response);
?>
