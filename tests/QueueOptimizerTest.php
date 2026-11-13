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
