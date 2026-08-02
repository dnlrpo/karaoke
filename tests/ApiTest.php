<?php
// tests/ApiTest.php - Tests de la API REST
declare(strict_types=1);

final class ApiTest extends KaraokeTestCase
{
    public function testGetQueueReturnsBasics(): void
    {
        $res = $this->client()->get('/api.php?action=get_queue');

        $this->assertTrue($res['success']);
        $this->assertIsArray($res['queue']);
        $this->assertSame(false, $res['is_admin']);
        $this->assertSame(true, $res['registration_enabled']);
        $this->assertIsString($res['csrf_token']);
        $this->assertGreaterThanOrEqual(32, strlen($res['csrf_token']));
        $this->assertIsArray($res['reactions']);
    }

    public function testAddToQueueRequiresAllFields(): void
    {
        $res = $this->client()->post('/api.php?action=add_to_queue', [
            'userName' => 'Solo nombre',
            'songTitle' => '',
            'artistName' => '',
        ]);

        $this->assertFalse($res['success']);
        $this->assertSame('Faltan campos obligatorios', $res['message']);
    }

    public function testAddToQueueRejectsWrongCode(): void
    {
        $res = $this->client()->post('/api.php?action=add_to_queue', [
            'userName' => 'Invitado',
            'songTitle' => 'Canción',
            'artistName' => 'Artista',
            'accessCode' => 'INCORRECTO',
        ]);

        $this->assertFalse($res['success']);
        $this->assertSame('Código de local incorrecto', $res['message']);
    }

    public function testAddToQueueValidatesCodeOncePerSession(): void
    {
        $client = $this->client();

        // Código correcto, case-insensitive
        $res = $client->post('/api.php?action=add_to_queue', [
            'userName' => 'Ana',
            'songTitle' => 'Despacito',
            'artistName' => 'Luis Fonsi',
            'accessCode' => 'trilla24',
        ]);
        $this->assertTrue($res['success']);

        // Segundo registro sin código (validado en sesión por 8h)
        $res = $client->post('/api.php?action=add_to_queue', [
            'userName' => 'Luis',
            'songTitle' => 'Otra canción',
            'artistName' => 'Otro artista',
        ]);
        $this->assertTrue($res['success']);

        $queue = $client->get('/api.php?action=get_queue');
        $this->assertCount(2, $queue['queue']);
    }

    public function testLoginRateLimitBlocksAfterFiveAttempts(): void
    {
        $client = $this->client();

        for ($i = 1; $i <= 5; $i++) {
            $res = $client->post('/api.php?action=login', ['pin' => '9999']);
            $this->assertFalse($res['success']);
            $this->assertStringContainsString('restantes', $res['message']);
        }

        $res = $client->post('/api.php?action=login', ['pin' => '9999']);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Demasiados intentos', $res['message']);
    }

    public function testLoginSuccessAndLogout(): void
    {
        $client = $this->client();
        $csrf = $this->loginAdmin($client);

        $res = $client->post('/api.php?action=logout', null, $this->csrfHeader($csrf));
        $this->assertTrue($res['success']);

        $queue = $client->get('/api.php?action=get_queue');
        $this->assertFalse($queue['is_admin']);
    }

    public function testReorderDeniedWithoutAdmin(): void
    {
        $res = $this->client()->post('/api.php?action=reorder_queue', ['orderedIds' => [1, 2]]);
        $this->assertFalse($res['success']);
        $this->assertSame('Acceso denegado', $res['message']);
    }

    public function testReorderDeniedWithoutCsrfToken(): void
    {
        $client = $this->client();
        $this->loginAdmin($client);

        $res = $client->post('/api.php?action=reorder_queue', ['orderedIds' => [1, 2]]);
        $this->assertFalse($res['success']);
        $this->assertSame('Acceso denegado', $res['message']);
    }

    public function testReorderRejectsListMismatch(): void
    {
        $client = $this->client();
        $csrf = $this->loginAdmin($client);
        $ids = $this->addToQueue($client, 3);

        $res = $client->post('/api.php?action=reorder_queue', ['orderedIds' => $ids], $this->csrfHeader($csrf));
        $this->assertTrue($res['success']);

        // Lista incompleta -> rechazada
        $res = $client->post('/api.php?action=reorder_queue', ['orderedIds' => [$ids[0]]], $this->csrfHeader($csrf));
        $this->assertFalse($res['success']);
        $this->assertSame('La lista no coincide con la cola activa', $res['message']);
    }

    public function testReorderUpdatesQueueOrder(): void
    {
        $client = $this->client();
        $csrf = $this->loginAdmin($client);
        $ids = $this->addToQueue($client, 3);

        $reversed = array_reverse($ids);
        $res = $client->post('/api.php?action=reorder_queue', ['orderedIds' => $reversed], $this->csrfHeader($csrf));
        $this->assertTrue($res['success']);

        $queue = $client->get('/api.php?action=get_queue');
        $this->assertSame($reversed, array_map('intval', array_column($queue['queue'], 'id')));
    }

    public function testRemoveFromQueueMarksFinished(): void
    {
        $client = $this->client();
        $csrf = $this->loginAdmin($client);
        [$id] = $this->addToQueue($client, 1);

        $res = $client->post('/api.php?action=remove_from_queue', ['id' => $id], $this->csrfHeader($csrf));
        $this->assertTrue($res['success']);

        $queue = $client->get('/api.php?action=get_queue');
        $this->assertCount(0, $queue['queue']);
    }

    public function testRemoveFromQueueRejectsInvalidId(): void
    {
        $client = $this->client();
        $csrf = $this->loginAdmin($client);

        $res = $client->post('/api.php?action=remove_from_queue', ['id' => 'abc'], $this->csrfHeader($csrf));
        $this->assertFalse($res['success']);
        $this->assertSame('ID inválido', $res['message']);
    }

    public function testRegistrationToggleRequiresAdmin(): void
    {
        $res = $this->client()->post('/api.php?action=toggle_registration', ['enabled' => false]);
        $this->assertFalse($res['success']);
        $this->assertSame('Acceso denegado', $res['message']);
    }

    public function testClosedRegistrationRejectsNewEntries(): void
    {
        $client = $this->client();
        $csrf = $this->loginAdmin($client);

        $res = $client->post('/api.php?action=toggle_registration', ['enabled' => false], $this->csrfHeader($csrf));
        $this->assertTrue($res['success']);

        $res = $client->post('/api.php?action=add_to_queue', [
            'userName' => 'Tarde',
            'songTitle' => 'Nada',
            'artistName' => 'Nadie',
            'accessCode' => 'TRILLA24',
        ]);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('registro está cerrado', $res['message']);
    }

    public function testReactionWhitelistRejectsUnknownEmoji(): void
    {
        $res = $this->client()->post('/api.php?action=send_reaction', ['emoji' => '🚀']);
        $this->assertFalse($res['success']);
        $this->assertSame('Emoji no permitido', $res['message']);
    }

    public function testSendReactionRateLimit(): void
    {
        $client = $this->client();

        for ($i = 0; $i < 15; $i++) {
            $res = $client->post('/api.php?action=send_reaction', ['emoji' => '👏']);
            $this->assertTrue($res['success'], "Reacción $i falló: " . ($res['message'] ?? ''));
        }

        $res = $client->post('/api.php?action=send_reaction', ['emoji' => '👏']);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Demasiadas reacciones', $res['message']);
    }

    public function testUnifiedPollingReturnsReactions(): void
    {
        $client = $this->client();

        $res = $client->post('/api.php?action=send_reaction', ['emoji' => '🔥']);
        $this->assertTrue($res['success']);
        $this->assertIsInt($res['id']);

        $queue = $client->get('/api.php?action=get_queue&since=0');
        $this->assertTrue($queue['success']);
        $this->assertNotEmpty($queue['reactions']);
        $this->assertSame('🔥', $queue['reactions'][0]['emoji']);
    }

    public function testAutoReorderPlacesNewbiesForward(): void
    {
        $client = $this->client();
        $csrf = $this->loginAdmin($client);
        $ids = $this->addToQueue($client, 8);

        $res = $client->post('/api.php?action=auto_reorder', null, $this->csrfHeader($csrf));
        $this->assertTrue($res['success']);

        $queue = $client->get('/api.php?action=get_queue');
        $this->assertCount(8, $queue['queue']);

        // sort_order debe ser secuencial y único (1..8)
        $orders = array_column($queue['queue'], 'sort_order');
        $this->assertSame(range(1, 8), array_map('intval', $orders));
    }
}
