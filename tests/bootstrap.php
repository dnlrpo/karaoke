<?php
// tests/bootstrap.php - Arranca la DB de test y un servidor HTTP real
declare(strict_types=1);

const KARAOKE_ROOT = __DIR__ . '/..';
const TEST_ADMIN_PIN = '1234';

// Configura la DB de test ANTES de cargar db.php
putenv('DB_NAME=karaoke_test');
$_ENV['DB_NAME'] = 'karaoke_test';

require_once KARAOKE_ROOT . '/db.php';

// Crea la DB de test si no existe (usa credenciales del .env)
try {
    $adminPdo = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';charset=utf8mb4',
        getenv('DB_USER'),
        getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $adminPdo->exec('CREATE DATABASE IF NOT EXISTS karaoke_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (PDOException $e) {
    fwrite(STDERR, "[bootstrap] No se pudo crear karaoke_test: " . $e->getMessage() . "\n");
}

// El schema ya existe por initDatabase; fuerza un PIN conocido para los tests
$stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE username = 'staff'");
$stmt->execute([password_hash(TEST_ADMIN_PIN, PASSWORD_DEFAULT)]);

/**
 * HTTP client con soporte de sesión (cookie jar).
 */
final class TestClient
{
    private string $jar;

    public function __construct()
    {
        $this->jar = sys_get_temp_dir() . '/karaoke_test_' . bin2hex(random_bytes(6)) . '.txt';
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    public function post(string $path, ?array $body = null, array $headers = []): array
    {
        return $this->request('POST', $path, $body, $headers);
    }

    private function request(string $method, string $path, ?array $body, array $headers): array
    {
        $ch = curl_init(KARAOKE_BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HEADER => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                $headers[] = 'Content-Type: application/json';
            }
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('Request failed: ' . curl_error($ch));
        }
        curl_close($ch);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta no JSON: ' . substr($raw, 0, 200));
        }
        return $decoded;
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }
}

/**
 * Arranca el servidor de desarrollo si no está corriendo ya.
 */
function ensureTestServer(): void
{
    if (defined('KARAOKE_BASE_URL')) {
        return;
    }

    $port = random_int(9000, 9999);
    $cmd = sprintf(
        'php -S 127.0.0.1:%d -t %s',
        $port,
        escapeshellarg(KARAOKE_ROOT)
    );
    $proc = proc_open(
        $cmd,
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('No se pudo arrancar el servidor de test');
    }

    define('KARAOKE_BASE_URL', "http://127.0.0.1:$port");
    define('KARAOKE_TEST_SERVER', $proc);

    // Esperar readiness (hasta 5s)
    $ok = false;
    for ($i = 0; $i < 50; $i++) {
        $ch = curl_init(KARAOKE_BASE_URL . '/api.php?action=get_queue');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res !== false) {
            $ok = true;
            break;
        }
        usleep(100000);
    }
    if (!$ok) {
        throw new RuntimeException('El servidor de test no respondió a tiempo');
    }
}

/**
 * Reset del estado compartido de la DB de test.
 */
function resetTestDatabase(): void
{
    $pdo = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';dbname=karaoke_test;charset=utf8mb4',
        getenv('DB_USER'),
        getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec('DELETE FROM songs');
    $pdo->exec('DELETE FROM reactions');
    $pdo->exec("UPDATE settings SET `value` = '1' WHERE `key` = 'registration_enabled'");
}

ensureTestServer();
register_shutdown_function(static function (): void {
    if (defined('KARAOKE_TEST_SERVER')) {
        proc_terminate(KARAOKE_TEST_SERVER);
    }
});
