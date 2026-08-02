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
const SCHEMA_VERSION = 1;

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
 * Check if the staff user is logged in.
 */
function isAdmin(): bool
{
    secureSessionStart();
    return isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true;
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
