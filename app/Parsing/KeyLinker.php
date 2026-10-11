<?php


final class KeyLinker
{
    const STOP = ['clave', 'claves', 'respuesta', 'respuestas', 'solucionario', 'key', 'answer', 'hoja', 'del', 'de', 'el', 'la', 'los', 'las', 'y', 'en'];

    /** Aplica cada clave al examen que mejor le corresponde. @return array [batches, keys] */
    public static function link(array $batches, array $keys): array
    {
        foreach ($keys as &$key) {
            $key['target'] = null; $key['applied'] = 0;
            $bi = self::bestBatch($batches, $key);
            if ($bi === null) continue;
            $key['target'] = $batches[$bi]['filename'];
            $positional = AnswerKey::isPositional(array_column($batches[$bi]['questions'], 'num'));
            foreach ($batches[$bi]['questions'] as $qi => &$q) {
                $k = $positional ? $qi + 1 : ($q['num'] ?? null);
                if ($q['type'] === Config::TYPE_MCQ && empty($q['confident']) && $k !== null
                    && isset($key['map'][$k]) && $key['map'][$k] < count($q['options'])) {
                    $q['correct'] = $key['map'][$k]; $q['confident'] = true; $q['source'] = 'clave_final';
                }
            }
            unset($q);
            foreach ($batches[$bi]['questions'] as $q) if (($q['source'] ?? '') === 'clave_final') $key['applied']++;
        }
        unset($key);
        return [$batches, $keys];
    }

    private static function tokens(string $name): array
    {
        $c = Text::compare(preg_replace('/\.(docx|pdf|txt)$/i', '', $name));
        $w = array_filter(explode(' ', $c), function ($x) { return strlen($x) > 1 && !in_array($x, self::STOP, true); });
        return array_values(array_unique($w));
    }

    private static function bestBatch(array $batches, array $key): ?int
    {
        $best = null; $bestScore = -INF;
        $kt = self::tokens($key['filename']); $maxKey = max(array_keys($key['map']));
        foreach ($batches as $bi => $b) {
            $overlap = count(array_intersect($kt, self::tokens($b['filename'])));
            $diff    = abs($maxKey - count($b['questions']));
            if ($overlap === 0 && count($batches) > 1 && $diff > 3) continue;
            $score = $overlap * 100 - $diff;
            if ($score > $bestScore) { $bestScore = $score; $best = $bi; }
        }
        return $best;
    }
}
