<?php
// db.php - Database connection and helpers

$host = 'localhost';
$db   = 'karaoke';
$user = 'karaoke';
$pass = 'aU5sR2gQ6i';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

/**
 * Get the current night code from settings
 */
function getNightCode($pdo) {
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'night_code'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result ? $result['value'] : 'TRILLA24';
}

/**
 * Check if the staff user is logged in
 */
function isAdmin() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true;
}

/**
 * Initialize the database tables if they don't exist
 */
function initDatabase($pdo) {
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
    ";
    
    $pdo->exec($sql);

    // Migration for sort_order column
    try {
        $pdo->query("SELECT sort_order FROM songs LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE songs ADD COLUMN sort_order INT DEFAULT 0");
    }

    // Initial data
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES ('night_code', 'TRILLA24')");
    $stmt->execute();
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES ('registration_enabled', '1')");
    $stmt->execute();
}

/**
 * Hash and set initial admin password if none exists
 */
function checkDefaultAdmin($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $pin = '1234';
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash) VALUES ('staff', ?)");
        $stmt->execute([$hash]);
    }
}

// Ensure the database is ready
initDatabase($pdo);
checkDefaultAdmin($pdo);
?>
