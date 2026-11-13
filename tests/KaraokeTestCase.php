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
     * Crea un participante validado con su propia sesión y cookie.
     */
    protected function participant(string $name, string $code = 'TRILLA24'): TestClient
    {
        $client = $this->client();
        $res = $client->post('/api.php?action=validate_code', [
            'userName' => $name,
            'accessCode' => $code,
        ]);
        $this->assertTrue($res['success'], 'validate_code falló: ' . ($res['message'] ?? ''));
        return $client;
    }

    /**
     * Añade una canción desde una sesión de participante ya validada.
     *
     * @return array<string,mixed>
     */
    protected function addSong(TestClient $client, string $title, string $artist = 'Artista'): array
    {
        return $client->post('/api.php?action=add_to_queue', [
            'songTitle' => $title,
            'artistName' => $artist,
        ]);
    }

    /**
     * Crea N participantes distintos, con una canción cada uno.
     *
     * @return list<int> IDs de las canciones en orden de inserción
     */
    protected function addParticipants(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $client = $this->participant("Usuario $i");
            $res = $this->addSong($client, "Canción $i", "Artista $i");
            $this->assertTrue($res['success'], 'add_to_queue falló: ' . ($res['message'] ?? ''));
            $ids[] = (int)$res['id'];
        }
        return $ids;
    }
}
