<?php
// tests/SecurityTest.php - Endurecimiento de la API (fase 1)
declare(strict_types=1);

final class SecurityTest extends KaraokeTestCase
{
    private function register(TestClient $client, string $name, string $code = 'TRILLA24'): array
    {
        return $client->post('/api.php?action=add_to_queue', [
            'userName' => $name,
            'songTitle' => 'Canción de ' . $name,
            'artistName' => 'Artista',
            'accessCode' => $code,
        ]);
    }

    public function testGetQueueRejectsPost(): void
    {
        $res = $this->client()->post('/api.php?action=get_queue');

        $this->assertSame(405, $res['_http_status']);
        $this->assertFalse($res['success']);
        $this->assertSame('Método no permitido', $res['message']);
    }

    public function testAdminActionRejectsGet(): void
    {
        $client = $this->client();
        $csrf = $this->loginAdmin($client);

        $res = $client->get('/api.php?action=clear_queue', $this->csrfHeader($csrf));

        $this->assertSame(405, $res['_http_status']);
        $this->assertFalse($res['success']);
    }

    public function testAdminActionWithoutCsrfReturns403(): void
    {
        $client = $this->client();
        $this->loginAdmin($client);

        $res = $client->post('/api.php?action=clear_queue');

        $this->assertSame(403, $res['_http_status']);
        $this->assertSame('Acceso denegado', $res['message']);
    }

    public function testLongFieldsAreRejected(): void
    {
        $res = $this->client()->post('/api.php?action=add_to_queue', [
            'userName' => str_repeat('a', 61),
            'songTitle' => 'Canción',
            'artistName' => 'Artista',
            'accessCode' => 'TRILLA24',
        ]);

        $this->assertSame(422, $res['_http_status']);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('60', $res['message']);
    }

    public function testQueueLimitRejectsWhenFull(): void
    {
        $stmt = $this->db()->prepare(
            "INSERT INTO songs (user_name, song_title, artist_name, status, sort_order)
             VALUES (?, ?, ?, 'waiting', ?)"
        );
        for ($i = 1; $i <= 80; $i++) {
            $stmt->execute(["Usuario $i", "Canción $i", "Artista $i", $i]);
        }

        $res = $this->register($this->client(), 'Tarde');

        $this->assertSame(409, $res['_http_status']);
        $this->assertStringContainsString('completa', $res['message']);
    }

    public function testReorderRejectsDuplicateIds(): void
    {
        $client = $this->client();
        $csrf = $this->loginAdmin($client);
        $ids = $this->addToQueue($client, 2);

        $res = $client->post(
            '/api.php?action=reorder_queue',
            ['orderedIds' => [$ids[0], $ids[0], $ids[1]]],
            $this->csrfHeader($csrf)
        );

        $this->assertSame(422, $res['_http_status']);
        $this->assertStringContainsString('duplicados', $res['message']);
    }

    public function testRegistrationRateLimitPerSession(): void
    {
        $client = $this->client();

        for ($i = 1; $i <= 12; $i++) {
            $res = $this->register($client, "Persona $i");
            $this->assertTrue($res['success'], "Registro $i falló: " . ($res['message'] ?? ''));
        }

        $res = $this->register($client, 'Persona 13');

        $this->assertSame(429, $res['_http_status']);
        $this->assertStringContainsString('Demasiados registros', $res['message']);
    }

    public function testPinChangeInvalidatesOtherSessions(): void
    {
        $clientA = $this->client();
        $csrfA = $this->loginAdmin($clientA);

        $clientB = $this->client();
        $this->loginAdmin($clientB);

        $res = $clientA->post(
            '/api.php?action=update_staff_pin',
            ['newPin' => '5678'],
            $this->csrfHeader($csrfA)
        );
        $this->assertTrue($res['success']);

        // La sesión que cambió el PIN sigue siendo válida
        $queueA = $clientA->get('/api.php?action=get_queue');
        $this->assertTrue($queueA['is_admin']);

        // Las demás sesiones de staff quedan invalidadas
        $queueB = $clientB->get('/api.php?action=get_queue');
        $this->assertFalse($queueB['is_admin']);

        // El PIN nuevo funciona y el anterior no
        $clientC = $this->client();
        $this->assertTrue($clientC->post('/api.php?action=login', ['pin' => '5678'])['success']);

        $clientD = $this->client();
        $this->assertFalse($clientD->post('/api.php?action=login', ['pin' => '1234'])['success']);
    }
}
