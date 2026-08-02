<?php
// bin/reset-admin-pin.php - Regenera el PIN del staff e imprime el nuevo
// Uso: php bin/reset-admin-pin.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$pin = (string)random_int(1000, 9999);
$hash = password_hash($pin, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE username = 'staff'");
$stmt->execute([$hash]);
if ($stmt->rowCount() === 0) {
    $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash) VALUES ('staff', ?)");
    $stmt->execute([$hash]);
}

echo "Nuevo PIN de staff: {$pin}\n";
echo "Cámbialo desde el panel: Configuración de Hoy -> Cambiar PIN Staff.\n";
