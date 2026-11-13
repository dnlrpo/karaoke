<?php
// src/QueueOptimizer.php - Lógica pura de optimización de la cola
declare(strict_types=1);

/**
 * Reordena la cola de espera sin tocar la base de datos ni el DOM.
 *
 * Reglas (comportamiento actual de la app, fijado por los tests):
 *  1. Los participantes sin canciones terminadas en las últimas 12 h son
 *     "novatos". Si están en la posición 7 o posterior (índice >= bumpAt),
 *     se adelantan a la posición 6.
 *  2. Después se evita que un mismo participante vuelva a cantar en los
 *     siguientes `maxGap` turnos, manteniendo el orden relativo siempre que
 *     sea posible.
 *
 * Nota: con una cola en la que TODOS son novatos, la regla 1 mueve al 6º
 * participante al final y adelanta una posición al resto. Es el
 * comportamiento que valida la suite de integración.
 */
final class QueueOptimizer
{
    public const DEFAULT_BUMP_AT = 6;
    public const DEFAULT_MAX_GAP = 4;

    /**
     * @param list<array{id:int|string, user_name:string, singer_key?:string}> $queue
     * @param array<string,int> $finishedCounts Canciones terminadas por clave de cantante
     * @return list<array{id:int|string, user_name:string, singer_key?:string}>
     */
    public static function optimize(
        array $queue,
        array $finishedCounts = [],
        int $bumpAt = self::DEFAULT_BUMP_AT,
        int $maxGap = self::DEFAULT_MAX_GAP
    ): array {
        if ($queue === []) {
            return [];
        }

        $bumpAt = max(1, $bumpAt);
        $maxGap = max(1, $maxGap);

        // 1. Separar novatos que están lejos y adelantarlos
        $newbies = [];
        $others = [];
        foreach ($queue as $index => $song) {
            $finished = (int)($finishedCounts[self::key($song)] ?? 0);
            if ($finished === 0 && $index >= $bumpAt) {
                $newbies[] = $song;
            } else {
                $others[] = $song;
            }
        }

        // Índice 5 = sexta posición (0-based)
        array_splice($others, $bumpAt - 1, 0, $newbies);

        // 2. Repartir para no repetir cantante dentro de la ventana
        $final = [];
        $pool = $others;
        $history = [];

        while ($pool !== []) {
            $index = self::nextIndex($pool, $history);
            $song = $pool[$index];

            $final[] = $song;
            array_unshift($history, self::key($song));
            if (count($history) > $maxGap) {
                array_pop($history);
            }

            array_splice($pool, $index, 1);
        }

        return $final;
    }

    /**
     * Siguiente canción: la primera cuyo cantante no esté en la ventana;
     * si no hay, la primera que no sea la última en cantar; si no, la primera.
     *
     * @param list<array{user_name:string, singer_key?:string}> $pool
     * @param list<string> $history
     */
    private static function nextIndex(array $pool, array $history): int
    {
        foreach ($pool as $index => $song) {
            if (!in_array(self::key($song), $history, true)) {
                return $index;
            }
        }

        foreach ($pool as $index => $song) {
            if ($history === [] || self::key($song) !== $history[0]) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * Clave de identidad: participant_id si viene, nombre para filas antiguas.
     *
     * @param array{user_name:string, singer_key?:string} $song
     */
    private static function key(array $song): string
    {
        return (string)($song['singer_key'] ?? $song['user_name']);
    }
}
