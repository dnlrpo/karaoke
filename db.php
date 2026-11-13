<?php
// db.php - Database connection and helpers
declare(strict_types=1);

/**
 * Load .env file if it exists
 */
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    $vars = parse_ini_file($path, false, INI_SCANNER_RAW);
    if ($vars === false) {
        return;
    }
    foreach ($vars as $name => $value) {
        // Las variables de entorno reales tienen prioridad sobre el archivo .env
        if (getenv($name) !== false) {
            continue;
        }
        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
}

loadEnv(__DIR__ . '/.env');

$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'karaoke';
$user = getenv('DB_USER') ?: 'karaoke';
$pass = getenv('DB_PASS') ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    die("Database connection failed. Please contact the administrator.");
}

/** Bump this constant when the schema changes to trigger pending migrations */
const SCHEMA_VERSION = 3;

/**
 * Security headers for every response. They are sent from PHP so they work the
 * same behind Apache, nginx or the built-in server. The CSP allows the remote
 * logo used by the empty-queue state.
 */
function sendSecurityHeaders(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "img-src 'self' data: https://latrillacultural.com; " .
        "style-src 'self'; script-src 'self'; font-src 'self'; " .
        "connect-src 'self'; frame-ancestors 'none'; " .
        "base-uri 'none'; form-action 'self'"
    );
}

/**
 * Start a hardened session (HttpOnly, SameSite, Secure on HTTPS).
 */
function secureSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Get the current night code from settings (never hardcoded).
 */
function getNightCode(PDO $pdo): string
{
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'night_code'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result !== false ? (string)$result['value'] : '';
}

/**
 * Auth epoch: bumping it invalidates every staff session except the current one.
 * Stored in settings so it survives restarts and is shared by all PHP workers.
 */
function authEpoch(PDO $pdo, bool $refresh = false): int
{
    static $epoch = null;
    if ($epoch === null || $refresh) {
        try {
            $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'auth_epoch'");
            $stmt->execute();
            $row = $stmt->fetch();
            $epoch = $row !== false ? (int)$row['value'] : 0;
        } catch (PDOException $e) {
            $epoch = 0;
        }
    }
    return $epoch;
}

/**
 * Client IP, honouring X-Forwarded-For only when the request comes from a
 * proxy listed in TRUSTED_PROXIES (never trust the header blindly).
 */
function clientIp(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $trustedRaw = (string)(getenv('TRUSTED_PROXIES') ?: '');

    if ($trustedRaw !== '' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $trusted = array_filter(array_map('trim', explode(',', $trustedRaw)));
        if (in_array($remote, $trusted, true)) {
            $candidate = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
    }

    return $remote;
}

/**
 * Rate limit key: IPs are stored as packed bytes, anything else (e.g. a
 * session id) as a sha256 digest, so a single VARBINARY column fits both.
 */
function rateLimitSubject(string $key): string
{
    $packed = @inet_pton($key);
    return $packed !== false ? $packed : hash('sha256', $key, true);
}

/**
 * Attempts recorded for a subject inside the current window (0 if expired).
 */
function rateLimitCount(PDO $pdo, string $bucket, string $key, int $window): int
{
    $stmt = $pdo->prepare(
        "SELECT attempts FROM rate_limits
         WHERE bucket = ? AND subject = ? AND window_start >= ? - ?"
    );
    $stmt->execute([$bucket, rateLimitSubject($key), time(), $window]);
    $count = $stmt->fetchColumn();
    return $count === false ? 0 : (int)$count;
}

/**
 * Record one attempt. Starts a new window when the previous one expired.
 */
function rateLimitIncrement(PDO $pdo, string $bucket, string $key, int $window): void
{
    $now = time();
    $stmt = $pdo->prepare(
        "INSERT INTO rate_limits (bucket, subject, window_start, attempts)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             attempts = IF(window_start < ? - ?, 1, attempts + 1),
             window_start = IF(window_start < ? - ?, ?, window_start)"
    );
    $stmt->execute([$bucket, rateLimitSubject($key), $now, $now, $window, $now, $window, $now]);

    // Cleanup oportunista de ventanas antiguas (1 % de los incrementos)
    if (random_int(1, 100) === 1) {
        $pdo->prepare("DELETE FROM rate_limits WHERE window_start < ? - 86400")
            ->execute([$now]);
    }
}

/**
 * Forget the attempts for a subject (e.g. after a successful login).
 */
function rateLimitReset(PDO $pdo, string $bucket, string $key): void
{
    $pdo->prepare("DELETE FROM rate_limits WHERE bucket = ? AND subject = ?")
        ->execute([$bucket, rateLimitSubject($key)]);
}

/**
 * Check if the staff user is logged in: session flag plus a current auth epoch.
 * Sessions created before this check existed carry no epoch (0) and stay valid
 * until the PIN is changed once.
 */
function isAdmin(PDO $pdo): bool
{
    secureSessionStart();
    if (($_SESSION['admin_auth'] ?? null) !== true) {
        return false;
    }
    return (int)($_SESSION['auth_epoch'] ?? 0) === authEpoch($pdo);
}

/**
 * CSRF token: generate on first use, then reuse for the session.
 */
function csrfToken(): string
{
    secureSessionStart();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token using a timing-safe comparison.
 */
function csrfValidate(?string $token): bool
{
    secureSessionStart();
    return isset($_SESSION['csrf_token'], $token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Current schema version stored in settings (0 when missing/unreadable).
 */
function getSchemaVersion(PDO $pdo): int
{
    try {
        $stmt = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'schema_version'");
        $row = $stmt->fetch();
        return $row !== false ? (int)$row['value'] : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Run pending migrations. Each migration runs once inside a transaction.
 */
function initDatabase(PDO $pdo): void
{
    $migrations = [
        1 => static function (PDO $pdo): void {
            $sql = "
                CREATE TABLE IF NOT EXISTS songs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_name VARCHAR(255) NOT NULL,
                    song_title VARCHAR(255) NOT NULL,
                    artist_name VARCHAR(255) NOT NULL,
                    status ENUM('waiting', 'singing', 'finished', 'deleted') DEFAULT 'waiting',
                    sort_order INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS settings (
                    `key` VARCHAR(50) PRIMARY KEY,
                    `value` TEXT NOT NULL
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS admins (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(50) UNIQUE NOT NULL,
                    password_hash VARCHAR(255) NOT NULL
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS reactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    emoji VARCHAR(10) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;
            ";
            $pdo->exec($sql);

            $seed = $pdo->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
            $seed->execute(['night_code', 'TRILLA24']);
            $seed->execute(['registration_enabled', '1']);
        },
        2 => static function (PDO $pdo): void {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    bucket VARCHAR(32) NOT NULL,
                    subject VARBINARY(64) NOT NULL,
                    window_start INT UNSIGNED NOT NULL,
                    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (bucket, subject),
                    KEY idx_rate_limits_window (window_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        },
        3 => static function (PDO $pdo): void {
            // MySQL no soporta CREATE INDEX IF NOT EXISTS: comprobamos primero
            $indexes = [
                ['songs', 'idx_songs_status_sort', '(status, sort_order)'],
                ['reactions', 'idx_reactions_created', '(created_at)'],
            ];
            $exists = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?"
            );
            foreach ($indexes as [$table, $index, $columns]) {
                $exists->execute([$table, $index]);
                if ((int)$exists->fetchColumn() === 0) {
                    $pdo->exec("CREATE INDEX `{$index}` ON `{$table}` {$columns}");
                }
            }
        },
    ];

    $current = getSchemaVersion($pdo);
    foreach ($migrations as $version => $migration) {
        if ($version <= $current) {
            continue;
        }
        // Note: MySQL commits implicitly on DDL, so migrations run without
        // transactions; CREATE TABLE IF NOT EXISTS keeps them idempotent.
        try {
            $migration($pdo);
            $stmt = $pdo->prepare(
                "INSERT INTO settings (`key`, `value`) VALUES ('schema_version', ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
            );
            $stmt->execute([(string)$version]);
            error_log("[karaoke] Migración de esquema aplicada (v{$version})");
        } catch (Throwable $e) {
            error_log("[karaoke] Migración v{$version} falló: " . $e->getMessage());
            throw $e;
        }
    }
}

/**
 * Create the default staff admin with a random PIN on first run.
 */
function checkDefaultAdmin(PDO $pdo): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins");
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        return;
    }

    $pin = (string)random_int(1000, 9999);
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash) VALUES ('staff', ?)");
    $stmt->execute([$hash]);

    // Deja el PIN visible en la sesión hasta el primer login
    secureSessionStart();
    $_SESSION['initial_admin_pin'] = $pin;
    error_log("[karaoke] Admin 'staff' creado. PIN inicial: {$pin} — cámbialo desde el panel.");
}

// Ensure the database is ready
initDatabase($pdo);
checkDefaultAdmin($pdo);
