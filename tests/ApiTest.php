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
        $this->assertSame(false, $res['access_code_validated']);
        $this->assertNull($res['participant_name']);
    }

    public function testHealthEndpoint(): void
    {
        $res = $this->client()->get('/api.php?action=health');

        $this->assertTrue($res['success']);
        $this->assertSame('ok', $res['status']);
        $this->assertSame('ok', $res['db']);
        $this->assertSame(SCHEMA_VERSION, $res['schema_version']);
        $this->assertIsString($res['time']);
    }

    public function testAddSongRequiresSongAndArtist(): void
    {
        $client = $this->participant('Ana');

        $res = $client->post('/api.php?action=add_to_queue', [
            'songTitle' => '',
            'artistName' => '',
        ]);

        $this->assertSame(422, $res['_http_status']);
        $this->assertFalse($res['success']);
        $this->assertSame('Faltan campos obligatorios', $res['message']);
    }

    public function testAddWithoutValidationIsRejected(): void
    {
        $res = $this->client()->post('/api.php?action=add_to_queue', [
            'songTitle' => 'Canción',
            'artistName' => 'Artista',
        ]);

        $this->assertSame(403, $res['_http_status']);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Valida el código', $res['message']);
    }

    public function testValidateCodeRejectsWrongCode(): void
    {
        $res = $this->client()->post('/api.php?action=validate_code', [
            'userName' => 'Invitado',
            'accessCode' => 'INCORRECTO',
        ]);

        $this->assertSame(403, $res['_http_status']);
        $this->assertFalse($res['success']);
        $this->assertSame('Código de local incorrecto', $res['message']);
    }

    public function testValidateCodeRateLimit(): void
    {
        $client = $this->client();

        for ($i = 1; $i <= 10; $i++) {
            $res = $client->post('/api.php?action=validate_code', [
                'userName' => 'Invitado',
                'accessCode' => 'MAL',
            ]);
            $this->assertSame(403, $res['_http_status']);
        }

        $res = $client->post('/api.php?action=validate_code', [
            'userName' => 'Invitado',
            'accessCode' => 'MAL',
        ]);

        $this->assertSame(429, $res['_http_status']);
        $this->assertStringContainsString('Demasiados intentos', $res['message']);
    }

    public function testValidateCodeOnceAndAddSeveralSongs(): void
    {
        $client = $this->client();

        $res = $client->post('/api.php?action=validate_code', [
            'userName' => 'Ana',
            'accessCode' => 'trilla24', // case-insensitive
        ]);
        $this->assertTrue($res['success']);
        $this->assertSame('Ana', $res['participant_name']);

        $first = $this->addSong($client, 'Despacito', 'Luis Fonsi');
        $this->assertTrue($first['success']);
        $this->assertSame(1, $first['position']);
        $this->assertIsInt($first['id']);

        // Ya no hace falta código ni nombre: la sesión recuerda a la persona
        $second = $this->addSong($client, 'Otra canción', 'Otro artista');
        $this->assertTrue($second['success']);
        $this->assertSame(2, $second['position']);

        $queue = $client->get('/api.php?action=get_queue');
        $this->assertCount(2, $queue['queue']);
        $this->assertSame('Ana', $queue['participant_name']);
        $this->assertTrue($queue['access_code_validated']);
        $this->assertSame(['Ana', 'Ana'], array_column($queue['queue'], 'user_name'));
    }

    public function testLoginRateLimitBlocksAfterFiveAttempts(): void
    {
        $client = $this->client();

        for ($i = 1; $i <= 4; $i++) {
            $res = $client->post('/api.php?action=login', ['pin' => '999999']);
            $this->assertFalse($res['success']);
            $this->assertStringContainsString('restantes', $res['message']);
        }

        // El 5º intento agota el límite
        $res = $client->post('/api.php?action=login', ['pin' => '999999']);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('bloqueado', $res['message']);

        $res = $client->post('/api.php?action=login', ['pin' => '999999']);
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
        $ids = $this->addParticipants(3);

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
        $ids = $this->addParticipants(3);

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
        [$id] = $this->addParticipants(1);

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

        $res = $this->client()->post('/api.php?action=validate_code', [
            'userName' => 'Tarde',
            'accessCode' => 'TRILLA24',
        ]);
        $this->assertSame(403, $res['_http_status']);
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
        $ids = $this->addParticipants(8);

        $res = $client->post('/api.php?action=auto_reorder', null, $this->csrfHeader($csrf));
        $this->assertTrue($res['success']);

        $queue = $client->get('/api.php?action=get_queue');
        $this->assertCount(8, $queue['queue']);
        $ordered = array_map('intval', array_column($queue['queue'], 'id'));

        // IDs únicos (sin duplicados ni pérdidas)
        $this->assertSame($ids, array_values(array_intersect($ids, $ordered)));
        $this->assertCount(8, array_unique($ordered));

        // Los novatos (u7, u8) se adelantan: quedan antes de u6
        $expected = array_merge(array_slice($ids, 0, 5), [$ids[6], $ids[7], $ids[5]]);
        $this->assertSame($expected, $ordered);
    }
}
