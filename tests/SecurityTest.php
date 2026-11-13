<?php
// tests/SecurityTest.php - Endurecimiento de la API (fase 1)
declare(strict_types=1);

final class SecurityTest extends KaraokeTestCase
{
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
        $res = $this->client()->post('/api.php?action=validate_code', [
            'userName' => str_repeat('a', 61),
            'accessCode' => 'TRILLA24',
        ]);

        $this->assertSame(422, $res['_http_status']);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('60', $res['message']);

        $client = $this->participant('Ana');
        $res = $this->addSong($client, str_repeat('b', 121));

        $this->assertSame(422, $res['_http_status']);
        $this->assertStringContainsString('120', $res['message']);
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

        $client = $this->participant('Tarde');
        $res = $this->addSong($client, 'Canción');

        $this->assertSame(409, $res['_http_status']);
        $this->assertStringContainsString('completa', $res['message']);
    }

    public function testReorderRejectsDuplicateIds(): void
    {
        $client = $this->client();
        $csrf = $this->loginAdmin($client);
        $ids = $this->addParticipants(2);

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
        $client = $this->participant('Persona');

        for ($i = 1; $i <= 12; $i++) {
            $res = $this->addSong($client, "Canción $i");
            $this->assertTrue($res['success'], "Registro $i falló: " . ($res['message'] ?? ''));
        }

        $res = $this->addSong($client, 'Canción 13');

        $this->assertSame(429, $res['_http_status']);
        $this->assertStringContainsString('Demasiados registros', $res['message']);
    }

    public function testUpdatePinRequiresSixDigits(): void
    {
        $client = $this->client();
        $csrf = $this->loginAdmin($client);

        $res = $client->post(
            '/api.php?action=update_staff_pin',
            ['newPin' => '1234'],
            $this->csrfHeader($csrf)
        );

        $this->assertSame(422, $res['_http_status']);
        $this->assertStringContainsString('6 dígitos', $res['message']);
    }

    public function testLegacyFourDigitPinStillLogsIn(): void
    {
        // Los PIN de 4 dígitos creados antes del cambio siguen siendo válidos
        $this->db()
            ->prepare("UPDATE admins SET password_hash = ? WHERE username = 'staff'")
            ->execute([password_hash('4321', PASSWORD_DEFAULT)]);

        $res = $this->client()->post('/api.php?action=login', ['pin' => '4321']);

        $this->assertTrue($res['success']);
    }

    public function testPinChangeInvalidatesOtherSessions(): void
    {
        $clientA = $this->client();
        $csrfA = $this->loginAdmin($clientA);

        $clientB = $this->client();
        $this->loginAdmin($clientB);

        $res = $clientA->post(
            '/api.php?action=update_staff_pin',
            ['newPin' => '567890'],
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
        $this->assertTrue($clientC->post('/api.php?action=login', ['pin' => '567890'])['success']);

        $clientD = $this->client();
        $this->assertFalse($clientD->post('/api.php?action=login', ['pin' => TEST_ADMIN_PIN])['success']);
    }
}
