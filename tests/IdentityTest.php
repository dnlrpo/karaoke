<?php
// tests/IdentityTest.php - Identidad anónima del participante
declare(strict_types=1);

final class IdentityTest extends KaraokeTestCase
{
    public function testValidateCodeReturnsAndPersistsParticipantName(): void
    {
        $client = $this->client();

        $res = $client->post('/api.php?action=validate_code', [
            'userName' => '  Ana   María  ',
            'accessCode' => 'trilla24',
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame('Ana María', $res['participant_name']);
        $this->assertSame(43200, $res['expires_in']);

        $queue = $client->get('/api.php?action=get_queue');
        $this->assertSame('Ana María', $queue['participant_name']);
        $this->assertTrue($queue['access_code_validated']);
    }

    public function testSameNameReusesParticipant(): void
    {
        $client = $this->client();
        $client->post('/api.php?action=validate_code', ['userName' => 'Ana', 'accessCode' => 'TRILLA24']);
        $client->post('/api.php?action=validate_code', ['userName' => 'Ana', 'accessCode' => 'TRILLA24']);

        $count = (int)$this->db()->query("SELECT COUNT(*) FROM participants WHERE name = 'Ana'")->fetchColumn();

        $this->assertSame(1, $count, 'El mismo nombre con la misma cookie debe reutilizar la identidad');
    }

    public function testDifferentNameCreatesNewParticipant(): void
    {
        $client = $this->client();
        $client->post('/api.php?action=validate_code', ['userName' => 'Ana', 'accessCode' => 'TRILLA24']);

        $res = $client->post('/api.php?action=validate_code', ['userName' => 'Beto', 'accessCode' => 'TRILLA24']);
        $this->assertTrue($res['success']);
        $this->assertSame('Beto', $res['participant_name']);

        $count = (int)$this->db()->query("SELECT COUNT(*) FROM participants")->fetchColumn();
        $this->assertSame(2, $count, 'Un nombre distinto en la misma cookie debe crear otra identidad');

        $added = $this->addSong($client, 'Canción de Beto');
        $this->assertTrue($added['success']);

        $queue = $client->get('/api.php?action=get_queue');
        $this->assertSame('Beto', $queue['queue'][0]['user_name']);
    }

    public function testSameNameDifferentSessionsGetDifferentIds(): void
    {
        $anaA = $this->participant('Ana');
        $anaB = $this->participant('Ana');

        $this->assertTrue($this->addSong($anaA, 'Canción A')['success']);
        $this->assertTrue($this->addSong($anaB, 'Canción B')['success']);

        $ids = array_map(
            'intval',
            $this->db()->query("SELECT participant_id FROM songs ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)
        );

        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1], 'Dos "Ana" en sesiones distintas son identidades distintas');
    }

    public function testResetParticipantClearsIdentity(): void
    {
        $client = $this->participant('Ana');

        $res = $client->post('/api.php?action=reset_participant');
        $this->assertTrue($res['success']);

        $queue = $client->get('/api.php?action=get_queue');
        $this->assertNull($queue['participant_name']);
        $this->assertFalse($queue['access_code_validated']);

        $added = $this->addSong($client, 'Canción');
        $this->assertSame(403, $added['_http_status']);
    }

    public function testResetParticipantKeepsQueueHistory(): void
    {
        $client = $this->participant('Ana');
        $this->assertTrue($this->addSong($client, 'Canción')['success']);

        $client->post('/api.php?action=reset_participant');

        $count = (int)$this->db()->query("SELECT COUNT(*) FROM songs")->fetchColumn();
        $this->assertSame(1, $count);
    }
}
