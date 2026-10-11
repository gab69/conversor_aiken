<?php


final class AnswerResolver
{
    const FEATURES = ['hl', 'col', 'bold', 'ul'];
    const PRIORITY = ['hl' => 0, 'col' => 1, 'bold' => 2, 'ul' => 3];

    /**
     * Elige la correcta de cada pregunta. Analiza TODO el documento para descubrir qué marca
     * de formato usa el docente de forma sistemática y la aplica por orden de fiabilidad.
     * @return array [preguntas, señales de formato usadas]
     */
    private $positional = false;

    public function resolve(array $raws, array $keyMap): array
    {
        $this->positional = AnswerKey::isPositional(array_column($raws, 'num'));
        list($raws, $order) = $this->rankFormatSignals($raws);

        $questions = [];
        foreach ($raws as $i => $r) {
            $questions[] = ($r['type'] === Config::TYPE_ESSAY)
                ? $this->essayQuestion($r)
                : $this->mcqQuestion($r, $i, $order, $keyMap);
        }
        return [$questions, $order];
    }

    private function rankFormatSignals(array $raws): array
    {
        $score = array_fill_keys(self::FEATURES, 0);
        $nMcq  = 0;
        foreach ($raws as &$r) {
            if ($r['type'] === Config::TYPE_ESSAY) continue;
            $nMcq++;
            $r['sig'] = [];
            foreach (self::FEATURES as $f) {
                $idx = [];
                foreach ($r['opts'] as $i => $o) if (!empty($o['f'][$f])) $idx[] = $i;
                $r['sig'][$f] = $idx;
                if (count($idx) === 1) $score[$f]++;
            }
        }
        unset($r);

        $order = self::FEATURES;
        usort($order, function ($a, $b) use ($score) {
            return ($score[$b] <=> $score[$a]) ?: (self::PRIORITY[$a] <=> self::PRIORITY[$b]);
        });
        $minHits = max(1, (int)ceil($nMcq * 0.15));
        $order = array_values(array_filter($order, function ($f) use ($score, $minHits) { return $score[$f] >= $minHits; }));
        return [$raws, $order];
    }

    private function essayQuestion(array $r): array
    {
        return ['type' => Config::TYPE_ESSAY, 'text' => $r['text'], 'options' => [], 'correct' => 0,
                'confident' => true, 'num' => $r['num'], 'source' => 'essay',
                'grader' => $r['grader'], 'grade' => $r['grade'], 'oneline' => false];
    }

    private function mcqQuestion(array $r, int $i, array $order, array $keyMap): array
    {
        $texts = array_map(function ($o) { return $o['text']; }, $r['opts']);
        list($correct, $confident, $source) = $this->pickCorrect($r, $i, $texts, $order, $keyMap);
        return ['type' => Config::TYPE_MCQ, 'text' => $r['text'], 'options' => $texts,
                'correct' => $correct ?? 0, 'confident' => $confident, 'num' => $r['num'], 'source' => $source,
                'ctx' => $r['ctx'] ?? '', 'grade' => ($r['grade'] ?? null) ?: 1.0,
                'oneline' => (bool)preg_match(Patterns::ORDER, $r['text'])];
    }

    private function pickCorrect(array $r, int $i, array $texts, array $order, array $keyMap): array
    {
        $correct = null; $confident = false; $source = 'none';

        // a) "Respuesta: X"
        if ($r['ans'] !== null && ($idx = $this->resolveAnswerLine($r['ans'], $texts, !empty($r['tf']))) !== null) {
            return [$idx, true, 'respuesta'];
        }
        // b) ✓ / * / (correcta) / casilla marcada
        $checked = [];
        foreach ($r['opts'] as $k => $o) if ($o['check']) $checked[] = $k;
        if (count($checked) === 1)   return [$checked[0], true, 'inline'];
        if (count($checked) > 1)     $correct = $checked[0];

        // c) formato sistemático del documento
        if ($correct === null) {
            foreach ($order as $f) {
                if (count($r['sig'][$f]) === 1) { $correct = $r['sig'][$f][0]; $confident = true; $source = 'inline'; break; }
            }
        }
        if ($correct === null) {
            foreach ($order as $f) {
                $c = count($r['sig'][$f]);
                if ($c > 1 && $c < count($texts)) { $correct = $r['sig'][$f][0]; break; }
            }
        }
        // d) clave de respuestas al final
        if (!$confident && $keyMap) {
            $k = (!$this->positional && $r['num'] !== null && isset($keyMap[$r['num']])) ? $r['num'] : ($i + 1);
            if (isset($keyMap[$k]) && $keyMap[$k] < count($texts)) return [$keyMap[$k], true, 'clave_final'];
        }
        return [$correct, $confident, $source];
    }

    /** Verdadero/Falso: "V", "F", "Verdadero", "Falso", "true", "false" → 0 / 1 */
    private function trueFalseAnswer(array $ans): ?int
    {
        if (isset($ans['letter'])) return $ans['letter'] === 5 ? 1 : null;             // la "F" se leyó como letra F
        $t = Text::compare($ans['text']);
        if (preg_match('/^(?:v|t|verdadero|verdadera|cierto|true)$/', $t)) return 0;
        if (preg_match('/^(?:f|falso|falsa|false)$/', $t)) return 1;
        return null;
    }

    private function resolveAnswerLine(array $ans, array $texts, bool $trueFalse = false): ?int
    {
        if ($trueFalse) return $this->trueFalseAnswer($ans);
        if (isset($ans['letter'])) return ($ans['letter'] >= 0 && $ans['letter'] < count($texts)) ? $ans['letter'] : null;
        $want = Text::compare($ans['text']);
        if ($want === '') return null;
        foreach ($texts as $i => $tx) if (Text::compare($tx) === $want) return $i;
        foreach ($texts as $i => $tx) {
            $c = Text::compare($tx);
            if ($c !== '' && (strpos($c, $want) === 0 || strpos($want, $c) === 0)) return $i;
        }
        return null;
    }
}
