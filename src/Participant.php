<?php
// src/Participant.php - Identidad anónima del participante (cookie + sesión)
declare(strict_types=1);

/**
 * Identifica a quien canta sin necesidad de una cuenta.
 *
 * - Al validar el código se crea (o reutiliza) una fila en `participants`.
 * - La cookie `karaoke_pid` guarda un token aleatorio; en la BD solo su hash.
 * - La sesión guarda el id, el nombre y la hora de validación (12 h).
 *
 * Reutiliza la identidad si la cookie existe y el nombre normalizado coincide;
 * si el nombre cambia, crea una identidad nueva (otra persona en el móvil).
 */
final class Participant
{
    public const COOKIE = 'karaoke_pid';
    public const TTL = PARTICIPANT_TTL; // 12 h

    /**
     * @return array{id:int, name:string}|null
     */
    public static function fromSession(): ?array
    {
        $id = $_SESSION['participant_id'] ?? null;
        $name = $_SESSION['participant_name'] ?? null;
        $validatedAt = $_SESSION['access_code_time'] ?? null;

        if (!is_int($id) || $id <= 0 || !is_string($name) || !is_int($validatedAt)
            || (time() - $validatedAt) >= self::TTL) {
            self::clearSession();
            return null;
        }

        return ['id' => $id, 'name' => $name];
    }

    /**
     * @return array{id:int, name:string}|null
     */
    public static function fromCookie(PDO $pdo): ?array
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT id, name FROM participants WHERE token_hash = ? LIMIT 1");
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    }

    /**
     * Devuelve la identidad de esta persona: reutiliza la cookie si el nombre
     * coincide; si no, crea una nueva y reemplaza la cookie.
     *
     * @return array{id:int, name:string}
     */
    public static function identify(PDO $pdo, string $name): array
    {
        $name = self::normalizeName($name);
        $existing = self::fromCookie($pdo);

        if ($existing !== null && self::sameName($existing['name'], $name)) {
            $stmt = $pdo->prepare("UPDATE participants SET name = ?, last_seen_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $existing['id']]);
            return ['id' => $existing['id'], 'name' => $name];
        }

        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("INSERT INTO participants (token_hash, name) VALUES (?, ?)");
        $stmt->execute([hash('sha256', $token), $name]);
        $id = (int)$pdo->lastInsertId();

        self::issueCookie($token);

        return ['id' => $id, 'name' => $name];
    }

    /**
     * @param array{id:int, name:string} $participant
     */
    public static function attachToSession(array $participant): void
    {
        $_SESSION['participant_id'] = $participant['id'];
        $_SESSION['participant_name'] = $participant['name'];
        $_SESSION['access_code_validated'] = true;
        $_SESSION['access_code_time'] = time();
    }

    public static function clearSession(): void
    {
        unset(
            $_SESSION['participant_id'],
            $_SESSION['participant_name'],
            $_SESSION['access_code_validated'],
            $_SESSION['access_code_time']
        );
    }

    public static function clearCookie(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        setcookie(self::COOKIE, '', self::cookieOptions(time() - 3600));
    }

    public static function normalizeName(string $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($name));
        return $normalized ?? trim($name);
    }

    private static function sameName(string $a, string $b): bool
    {
        return mb_strtolower(self::normalizeName($a)) === mb_strtolower(self::normalizeName($b));
    }

    private static function issueCookie(string $token): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        setcookie(self::COOKIE, $token, self::cookieOptions(time() + self::TTL));
    }

    /**
     * @return array{expires:int, path:string, secure:bool, httponly:bool, samesite:string}
     */
    private static function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
