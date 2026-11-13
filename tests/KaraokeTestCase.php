<?php
// tests/KaraokeTestCase.php - Base para los tests de la API
declare(strict_types=1);

abstract class KaraokeTestCase extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        resetTestDatabase();
    }

    protected function client(): TestClient
    {
        return new TestClient();
    }

    /**
     * Conexión directa a la BD de test (para preparar estado que la API no expone).
     */
    protected function db(): PDO
    {
        return new PDO(
            'mysql:host=' . getenv('DB_HOST') . ';dbname=' . (getenv('DB_NAME') ?: 'karaoke_test') . ';charset=utf8mb4',
            (string)getenv('DB_USER'),
            (string)getenv('DB_PASS'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * Login como staff y devuelve el token CSRF de la sesión.
     */
    protected function loginAdmin(TestClient $client): string
    {
        $res = $client->post('/api.php?action=login', ['pin' => TEST_ADMIN_PIN]);
        $this->assertTrue($res['success'], 'Login admin falló: ' . ($res['message'] ?? ''));
        $queue = $client->get('/api.php?action=get_queue');
        $this->assertTrue($queue['success']);
        $this->assertTrue($queue['is_admin']);
        return (string)$queue['csrf_token'];
    }

    protected function csrfHeader(string $token): array
    {
        return ['X-CSRF-Token: ' . $token];
    }

    /**
     * Registra N participantes en la cola (con código) y devuelve sus IDs.
     */
    protected function addToQueue(TestClient $client, int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $res = $client->post('/api.php?action=add_to_queue', [
                'userName' => "Usuario $i",
                'songTitle' => "Canción $i",
                'artistName' => "Artista $i",
                'accessCode' => 'TRILLA24',
            ]);
            $this->assertTrue($res['success'], 'add_to_queue falló: ' . ($res['message'] ?? ''));
            $queue = $client->get('/api.php?action=get_queue');
            $ids[] = (int)$queue['queue'][array_key_last($queue['queue'])]['id'];
        }
        return $ids;
    }
}
