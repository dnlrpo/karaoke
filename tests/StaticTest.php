<?php
// tests/StaticTest.php - Invariantes de seguridad del frontend y del esquema
declare(strict_types=1);

final class StaticTest extends KaraokeTestCase
{
    public function testNoInlineEventHandlersOrStyles(): void
    {
        foreach (['index.php', 'assets/js/app.js'] as $file) {
            $source = (string)file_get_contents(KARAOKE_ROOT . '/' . $file);

            $this->assertSame(
                0,
                preg_match('/\son(click|change|submit|input|load|error|keydown)\s*=/i', $source),
                "$file contiene handlers inline; romperían la CSP"
            );
            $this->assertSame(
                0,
                preg_match('/\sstyle="/i', $source),
                "$file contiene estilos inline; romperían la CSP"
            );
        }
    }

    public function testSecurityHeadersAreSent(): void
    {
        $client = $this->client();
        $client->get('/api.php?action=get_queue');
        $headers = $client->lastHeaders();

        $this->assertSame('nosniff', $headers['x-content-type-options'] ?? null);
        $this->assertSame('strict-origin-when-cross-origin', $headers['referrer-policy'] ?? null);
        $this->assertSame('DENY', $headers['x-frame-options'] ?? null);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $headers['x-request-id'] ?? '');

        $csp = $headers['content-security-policy'] ?? '';
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("style-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);

        // El logo remoto del estado vacío se mantiene a propósito
        $this->assertStringContainsString('https://latrillacultural.com', $csp);
    }

    public function testQueueIndexesExist(): void
    {
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?"
        );

        foreach ([['songs', 'idx_songs_status_sort'], ['reactions', 'idx_reactions_created']] as [$table, $index]) {
            $stmt->execute([$table, $index]);
            $this->assertGreaterThan(0, (int)$stmt->fetchColumn(), "Falta el índice $index en $table");
        }
    }

    public function testQueuePositionsAreSequential(): void
    {
        $client = $this->participant('Ana');
        $this->assertTrue($this->addSong($client, 'Canción 1')['success']);
        $this->assertTrue($this->addSong($client, 'Canción 2')['success']);
        $this->assertTrue($this->addSong($client, 'Canción 3')['success']);

        $positions = $this->db()
            ->query("SELECT sort_order FROM songs WHERE status = 'waiting' ORDER BY sort_order, id")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame([1, 2, 3], array_map('intval', $positions));
    }
}
