<?php
// tests/QueueOptimizerTest.php - Tests unitarios de la lógica pura de la cola
declare(strict_types=1);

final class QueueOptimizerTest extends PHPUnit\Framework\TestCase
{
    /**
     * @param list<string> $names
     * @return list<array{id:int, user_name:string}>
     */
    private function queue(array $names): array
    {
        $queue = [];
        foreach (array_values($names) as $i => $name) {
            $queue[] = ['id' => $i + 1, 'user_name' => $name];
        }
        return $queue;
    }

    /**
     * @param list<array{id:int|string, user_name:string}> $queue
     * @return list<int>
     */
    private function ids(array $queue): array
    {
        return array_map(static fn(array $song): int => (int)$song['id'], $queue);
    }

    public function testEmptyQueue(): void
    {
        $this->assertSame([], QueueOptimizer::optimize([], []));
    }

    public function testShortQueueKeepsOrder(): void
    {
        $queue = $this->queue(['Ana', 'Beto', 'Carla']);

        $this->assertSame([1, 2, 3], $this->ids(QueueOptimizer::optimize($queue, [])));
    }

    public function testAllNewbiesRotateSixthToTheEnd(): void
    {
        // Comportamiento actual: los 5 primeros no se mueven, el 6º pasa al final
        $queue = $this->queue(['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7', 'u8']);

        $this->assertSame([1, 2, 3, 4, 5, 7, 8, 6], $this->ids(QueueOptimizer::optimize($queue, [])));
    }

    public function testNewbieBeyondBumpMovesForward(): void
    {
        $queue = $this->queue(['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7', 'u8']);
        $finished = ['u1' => 1, 'u2' => 1, 'u3' => 1, 'u4' => 1, 'u5' => 1, 'u6' => 1];

        $this->assertSame([1, 2, 3, 4, 5, 7, 8, 6], $this->ids(QueueOptimizer::optimize($queue, $finished)));
    }

    public function testNewbieInsideBumpDoesNotMove(): void
    {
        $queue = $this->queue(['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7', 'u8']);
        $finished = ['u1' => 1, 'u3' => 1, 'u4' => 1, 'u5' => 1, 'u6' => 1, 'u7' => 1, 'u8' => 1];
        // u2 es novato, pero está en el índice 1 (< bumpAt): no se adelanta

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $this->ids(QueueOptimizer::optimize($queue, $finished)));
    }

    public function testEverySongAppearsExactlyOnce(): void
    {
        $queue = $this->queue(['Ana', 'Beto', 'Carla', 'Diego', 'Ana', 'Elena', 'Beto', 'Fabián', 'Gina', 'Hugo']);

        $result = QueueOptimizer::optimize($queue, []);

        $this->assertCount(10, $result);
        $ids = $this->ids($result);
        $expected = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        sort($ids);
        $this->assertSame($expected, $ids);
    }

    public function testSingleSingerRepeatedKeepsAllTurns(): void
    {
        // No debe entrar en bucle ni perder turnos aunque todos sean el mismo cantante
        $queue = $this->queue(['Ana', 'Ana', 'Ana', 'Ana', 'Ana']);

        $result = QueueOptimizer::optimize($queue, []);

        $this->assertCount(5, $result);
        foreach ($result as $song) {
            $this->assertSame('Ana', $song['user_name']);
        }
    }

    public function testSameNameDifferentIdentityCanStayTogether(): void
    {
        // Dos "Ana" distintas (singer_key distinto) no deben separarse por nombre
        $queue = [
            ['id' => 1, 'user_name' => 'Ana', 'singer_key' => 'p:1'],
            ['id' => 2, 'user_name' => 'Ana', 'singer_key' => 'p:2'],
            ['id' => 3, 'user_name' => 'Beto', 'singer_key' => 'p:3'],
            ['id' => 4, 'user_name' => 'Carla', 'singer_key' => 'p:4'],
        ];

        $this->assertSame([1, 2, 3, 4], $this->ids(QueueOptimizer::optimize($queue, [])));
    }

    public function testSameSingerKeyIsSeparated(): void
    {
        // La misma identidad sí se separa aunque cambie el nombre visible
        $queue = [
            ['id' => 1, 'user_name' => 'Ana', 'singer_key' => 'p:1'],
            ['id' => 2, 'user_name' => 'Ana', 'singer_key' => 'p:1'],
            ['id' => 3, 'user_name' => 'Beto', 'singer_key' => 'p:2'],
            ['id' => 4, 'user_name' => 'Carla', 'singer_key' => 'p:3'],
        ];

        $this->assertSame([1, 3, 4, 2], $this->ids(QueueOptimizer::optimize($queue, [])));
    }

    public function testFinishedCountsUseSingerKey(): void
    {
        $queue = [];
        for ($i = 1; $i <= 8; $i++) {
            $queue[] = ['id' => $i, 'user_name' => "u$i", 'singer_key' => "p:$i"];
        }

        $finished = ['p:1' => 1, 'p:2' => 1, 'p:3' => 1, 'p:4' => 1, 'p:5' => 1, 'p:6' => 1];

        $this->assertSame([1, 2, 3, 4, 5, 7, 8, 6], $this->ids(QueueOptimizer::optimize($queue, $finished)));
    }

    public function testRepeatedSingerIsNotBackToBack(): void
    {
        $queue = $this->queue(['Ana', 'Beto', 'Ana', 'Beto', 'Ana', 'Beto', 'Ana', 'Beto', 'Ana', 'Beto']);

        $result = QueueOptimizer::optimize($queue, []);

        $this->assertCount(10, $result);
        for ($i = 1; $i < count($result); $i++) {
            $this->assertNotSame(
                $result[$i - 1]['user_name'],
                $result[$i]['user_name'],
                'Dos turnos seguidos del mismo cantante en la posición ' . $i
            );
        }
    }
}
