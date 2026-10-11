<?php


final class QuestionExtractor
{
    private $numbered   = false;
    private $raws       = [];
    private $discarded  = 0;
    private $cur        = null;
    private $inExpl     = false;
    private $graderMode = false;
    private $ctx        = '';     // texto de lectura vigente
    private $ctxOn      = false;
    private $ctxAge     = 0;      // preguntas desde el último encabezado de lectura

    public function extract(array $rawLines, ?bool $forceNumbered = null): array
    {
        $lines = $this->prepareLines($rawLines);
        list($lines, $keyMap) = AnswerKey::split($lines);

        $this->numbered = ($forceNumbered === null) ? $this->detectNumbered($lines) : $forceNumbered;
        $this->raws = []; $this->discarded = 0; $this->cur = null; $this->inExpl = false; $this->graderMode = false;
        $this->ctx = ''; $this->ctxOn = false; $this->ctxAge = 0;

        foreach ($lines as $ln) $this->processLine($ln);
        $this->closeCurrent();

        list($questions, $signals) = (new AnswerResolver())->resolve($this->raws, $keyMap);
        $essays = count(array_filter($questions, function ($q) { return $q['type'] === Config::TYPE_ESSAY; }));

        return ['questions' => $questions, 'info' => [
            'numbered' => $this->numbered, 'discarded' => $this->discarded, 'key' => count($keyMap),
            'signals' => $signals, 'essays' => $essays, 'mcq' => count($questions) - $essays,
        ]];
    }

    // ---- máquina de estados --------------------------------------------------
    private function processLine(array $ln): void
    {
        $t = $ln['text'];
        if ($this->isNoise($t)) return;

        if ($this->cur !== null && ($a = $this->matchAnswerLine($t)) !== null) {
            $this->cur['ans'] = $a; $this->inExpl = false;
            return;
        }
        if ($this->cur !== null && preg_match(Patterns::GRADER, $t, $gm)) {
            if (trim($gm[1]) !== '') $this->cur['grader'][] = trim($gm[1]);
            $this->inExpl = true; $this->graderMode = true;
            return;
        }
        if (preg_match(Patterns::EXPLANATION, $t)) {
            $this->inExpl = true; $this->graderMode = false;
            return;
        }

        $opt = ($this->cur !== null) ? $this->matchOption($ln) : null;
        $qm  = ($opt === null && $this->numbered) ? $this->matchQuestionNumber($ln) : null;

        if ($qm !== null && $this->isSubListItem($ln)) {
            $this->cur['stemBuf'][] = ['text' => $ln['auto']['n'] . '. ' . $ln['text'], 'hard' => true];
            $this->cur['subN'] = $ln['auto']['n'];
            $this->inExpl = false; $this->graderMode = false;
            return;
        }
        if ($this->inExpl && $opt === null && $qm === null
            && ($this->numbered || (!$ln['hard'] && Text::startsLower($t)))) {
            if ($this->graderMode && $this->cur !== null) $this->cur['grader'][] = $t;
            return;
        }
        $this->inExpl = false; $this->graderMode = false;

        if ($opt !== null) { $this->addOption($opt); return; }
        if ($qm !== null)  { $this->startQuestion($qm); return; }
        $this->handleLooseText($ln);
    }

    /** Lista automática de Word dentro del enunciado: sigue a un "…:" y aún no hay opciones */
    private function isSubListItem(array $ln): bool
    {
        if ($this->cur === null || !$ln['auto'] || $ln['auto']['type'] !== 'decimal' || !empty($this->cur['opts'])) return false;
        $n = (int)$ln['auto']['n'];
        if (isset($this->cur['subN'])) return $n === $this->cur['subN'] + 1;
        $last = end($this->cur['stemBuf']);
        return $last && preg_match('/:$/u', $last['text']);
    }

    private function startQuestion(array $qm): void
    {
        $this->closeCurrent();
        $this->cur = $this->newQuestion($qm['num']);
        if ($qm['body'] !== '') $this->cur['stemBuf'][] = ['text' => $qm['body'], 'hard' => true];
    }

    private function addOption(array $opt): void
    {
        if (!empty($opt['reset'])) {       // las "viñetas" previas eran parte del enunciado
            foreach ($this->cur['opts'] as $o) $this->cur['stemBuf'][] = ['text' => '• ' . $o['text'], 'hard' => true];
            $this->cur['opts'] = [];
        }
        if (empty($this->cur['opts'])) $this->cur['stemText'] = $this->buildStem($this->cur['stemBuf']);
        $this->cur['opts'][] = $opt['opt'];                    // puede venir vacía: su texto llega en la línea siguiente
    }

    private function handleLooseText(array $ln): void
    {
        $t = $ln['text'];
        $entry = ['text' => $t, 'hard' => $ln['hard'], 'br' => !empty($ln['br'])];

        if ($this->cur === null) {
            if ($this->numbered) { $this->collectContext($ln); return; }   // título / instrucciones / lectura
            $this->cur = $this->newQuestion(null);
            $this->cur['stemBuf'][] = $entry;
            return;
        }
        if (empty($this->cur['opts'])) {                      // aún armando el enunciado
            if (!$this->numbered && $this->stemIsComplete($this->cur['stemBuf']) && $this->looksLikeQuestionStart($t)) {
                $this->closeCurrent();
                $this->cur = $this->newQuestion(null);
            }
            $this->cur['stemBuf'][] = $entry;
            return;
        }
        $k    = count($this->cur['opts']) - 1;
        $last = $this->cur['opts'][$k]['text'];
        $wraps = $this->numbered && !preg_match('/[\.\?\!:;]$/u', $last);              // opción larga partida en varias líneas
        if ($last === '' || (!$ln['hard'] && (Text::startsLower($t) || $wraps))) {   // continuación de la última opción
            $this->cur['opts'][$k]['text'] = Text::normalize($last . ' ' . $t);
            return;
        }
        if ($this->numbered) { $this->collectContext($ln); return; }   // lecturas y comentarios entre preguntas
        $this->closeCurrent();
        $this->cur = $this->newQuestion(null);
        $this->cur['stemBuf'][] = $entry;
    }

    private function newQuestion($num): array
    {
        $ctx = ($this->ctxOn && $this->ctxAge < 10) ? $this->ctx : '';
        if ($this->ctxOn) $this->ctxAge++;
        return ['num' => $num, 'stemBuf' => [], 'opts' => [], 'ans' => null, 'grader' => [], 'ctx' => $ctx];
    }

    /** "Texto 1" / "Read the text." + párrafo largo: se guarda para las preguntas que lo citan */
    private function collectContext(array $ln): void
    {
        $t = $ln['text'];
        if (preg_match(Patterns::CTX_HEAD, $t)) { $this->ctx = ''; $this->ctxOn = true; $this->ctxAge = 0; return; }
        if ($this->ctxOn && $ln['hard'] && strlen($t) >= 80) $this->ctx = Text::normalizeLines($this->ctx . "\n" . $t);
    }

    // ---- preparación de líneas -----------------------------------------------
    private function prepareLines(array $rawLines): array
    {
        $lines = [];
        foreach ($rawLines as $rl) {
            foreach ($this->expandInline($rl) as $part) {
                $text = Text::normalize($part['raw']);
                if ($text === '') continue;
                $lines[] = [
                    'raw' => $part['raw'], 'text' => $text, 'flags' => $this->flagsFor($part),
                    'hard' => $part['hard'], 'br' => !empty($part['br']), 'cell' => $part['cell'], 'auto' => $part['auto'],
                ];
            }
        }
        return $lines;
    }

    private function sliceTokens(array $tokens, int $s, int $e): array
    {
        $out = []; $off = 0;
        foreach ($tokens as $t) {
            $ts = $off; $te = $off + strlen($t['text']); $off = $te;
            if ($te <= $s || $ts >= $e) continue;
            $a = max($s, $ts) - $ts; $b = min($e, $te) - $ts;
            $t['text'] = substr($t['text'], $a, $b - $a);
            $out[] = $t;
        }
        return $out;
    }

    /** Separa "a) x  b) y  c) z" (varias opciones en una misma línea) */
    private function expandInline(array $rl): array
    {
        $raw = $rl['raw'];
        if (!preg_match_all('/(?<![\pL\pN])[\(\[]?([A-Ha-h])[\)\]](?=\s*\S)/u', $raw, $mm, PREG_OFFSET_CAPTURE)) return [$rl];
        $autoLetter = ($rl['auto'] && $rl['auto']['type'] === 'letter');
        $chain = []; $expect = $autoLetter ? (int)$rl['auto']['n'] : 0;
        // Línea que ya arranca con su letra (p. ej. "D) … E) …" tras "A) … B) … C) …"): la cadena empieza en esa letra
        if (!$autoLetter && preg_match('/^[\s\*✓✔☑✅]*$/u', substr($raw, 0, $mm[0][0][1]))) $expect = ord(strtolower($mm[1][0][0])) - 97;
        foreach ($mm[1] as $k => $g) {
            if (ord(strtolower($g[0])) - 97 === $expect) { $chain[] = $mm[0][$k][1]; $expect++; }
        }
        if ($autoLetter) {                                   // la letra de la 1.ª opción la puso Word
            if (count($chain) < 1) return [$rl];
            array_unshift($chain, 0);
        } else {
            if (count($chain) < 2) return [$rl];
            $prefix = substr($raw, 0, $chain[0]);
            if (preg_match('/^[\s\*✓✔☑✅]*$/u', $prefix)) { $chain[0] = 0; $prefix = ''; }
            if (trim($prefix) !== '' && count($chain) < 3) return [$rl];
        }

        $bounds = array_merge($chain, [strlen($raw)]);
        $parts = [];
        if ($chain[0] > 0) $parts[] = [0, $chain[0], $rl['hard'], $rl['auto']];
        foreach ($chain as $i => $s) {
            $parts[] = [$s, $bounds[$i + 1], ($i === 0 && $chain[0] === 0) ? $rl['hard'] : true, ($autoLetter && $i === 0) ? $rl['auto'] : null];
        }
        $out = [];
        foreach ($parts as $p) {
            $sub = $rl;
            $sub['raw']    = substr($raw, $p[0], $p[1] - $p[0]);
            $sub['tokens'] = $rl['tokens'] ? $this->sliceTokens($rl['tokens'], $p[0], $p[1]) : null;
            $sub['hard']   = $p[2];
            $sub['auto']   = $p[3];
            $out[] = $sub;
        }
        return $out;
    }

    /** Formato de la línea sin contar el marcador "a)": resaltado, color, negrita, subrayado */
    private function flagsFor(array $rl): array
    {
        $none = ['hl' => false, 'col' => false, 'bold' => false, 'ul' => false];
        if (empty($rl['tokens'])) return $none;

        $markerLen = 0;
        if (!$rl['auto'] && (preg_match(Patterns::LETTER, $rl['raw'], $m) || preg_match(Patterns::BULLET, $rl['raw'], $m))) {
            $markerLen = strlen($m[0]);
        }
        $off = 0; $any = false; $hl = false; $allB = true; $allC = true; $allU = true;
        foreach ($rl['tokens'] as $tk) {
            $end = $off + strlen($tk['text']);
            if ($end > max($off, $markerLen) && trim($tk['text']) !== '') {
                $any = true;
                $hl   = $hl || $tk['hl'];
                $allB = $allB && $tk['bold'];
                $allC = $allC && $tk['col'];
                $allU = $allU && $tk['ul'];
            }
            $off = $end;
        }
        return ['hl' => $any && $hl, 'col' => $any && $allC, 'bold' => $any && $allB, 'ul' => $any && $allU];
    }

    // ---- reconocimiento ------------------------------------------------------
    private function isNoise(string $t): bool
    {
        return (bool)(preg_match(Patterns::NOISE, $t) || preg_match('/^[\W_]+$/u', $t));
    }

    private function looksLikeOption(array $l): bool
    {
        if ($l['auto'] && in_array($l['auto']['type'], ['letter', 'bullet'], true)) return true;
        return (bool)(preg_match(Patterns::LETTER, $l['text']) || preg_match(Patterns::BULLET, $l['text']));
    }

    /** ¿Inicio de pregunta numerada? → ['num','body'] o null */
    private function matchQuestionNumber(array $l): ?array
    {
        if ($l['auto'] && $l['auto']['type'] === 'decimal') return ['num' => (int)$l['auto']['n'], 'body' => $l['text']];
        $t = $l['text'];
        foreach ([Patterns::QHEAD, Patterns::QNUM, Patterns::QNUM2] as $re) {
            if (preg_match($re, $t, $m)) return ['num' => (int)$m[1], 'body' => trim($m[2])];
        }
        if (preg_match(Patterns::QNUM3, $l['raw'], $m)) return ['num' => (int)$m[1], 'body' => Text::normalize($m[2])];
        if (($l['cell'] || $l['hard']) && preg_match('/^\s*(\d{1,3})\s*[\.\)]?\s*$/u', $t, $m)) {
            return ['num' => (int)$m[1], 'body' => ''];
        }
        return null;
    }

    /** ¿Esta opción abre un bloque nuevo de opciones? (la "a", aunque una opción larga se parta en varias líneas) */
    private function startsOptionRun(array $l, bool $prevOpt): bool
    {
        if ($l['auto'] && $l['auto']['type'] === 'letter') return (int)$l['auto']['n'] === 1;
        if (preg_match(Patterns::LETTER, $l['text'], $m)) return strtolower($m[2]) === 'a';
        return !$prevOpt;                                  // viñetas: un bloque nuevo cada vez que se interrumpe
    }

    /** ¿El documento numera sus preguntas? Compara numeraciones vs. bloques de opciones */
    private function detectNumbered(array $lines): bool
    {
        $q = 0; $runs = 0; $prevOpt = false;
        foreach ($lines as $l) {
            if ($this->isNoise($l['text'])) continue;
            $isOpt = $this->looksLikeOption($l);
            if ($isOpt && $this->startsOptionRun($l, $prevOpt)) $runs++;
            if (!$isOpt && $this->matchQuestionNumber($l) !== null) $q++;
            $prevOpt = $isOpt;
        }
        return ($runs === 0) ? $q >= 2 : $q >= 0.6 * $runs;
    }

    private function makeOption(string $body, bool $star, bool $chk, string $type, array $flags): array
    {
        $text = $body; $check = $star || $chk;
        if (preg_match('/[✓✔☑☒✅]/u', $text)) { $check = true; $text = preg_replace('/[✓✔☑☒✅]/u', '', $text); }
        if (preg_match('/\s*\*+\s*$/u', $text)) { $check = true; $text = preg_replace('/\s*\*+\s*$/u', '', $text); }
        $mark = '/\s*[\(\[]\s*(?:correcta|correcto|correct|ok|x)\s*[\)\]]\s*$/iu';
        if (preg_match($mark, $text)) { $check = true; $text = preg_replace($mark, '', $text); }
        return ['text' => Text::normalize($text), 'check' => $check, 'type' => $type, 'f' => $flags];
    }

    /** ¿Esta línea es la siguiente opción de la pregunta actual? */
    private function matchOption(array $ln): ?array
    {
        $cur = $this->cur;
        if (empty($cur['stemBuf']) && empty($cur['opts'])) return null;
        $n = count($cur['opts']);

        if ($ln['auto'] && in_array($ln['auto']['type'], ['letter', 'bullet'], true)) {
            return ['opt' => $this->makeOption($ln['text'], false, false, $ln['auto']['type'], $ln['flags'])];
        }
        $t = $ln['text'];
        if (preg_match(Patterns::LETTER, $t, $m)) {
            $idx  = ord(strtolower($m[2])) - 97;
            $body = trim(substr($t, strlen($m[0])));
            $opt  = $this->makeOption($body, !empty($m[1]), false, 'letter', $ln['flags']);
            if ($idx === $n) return ['opt' => $opt];
            if ($idx === 0 && $n > 0 && $cur['opts'][0]['type'] === 'bullet') return ['reset' => true, 'opt' => $opt];
        }
        if (preg_match(Patterns::BULLET, $t, $m)) {
            if ($n > 0 && $cur['opts'][0]['type'] === 'letter') return null;
            if ($n === 0 && empty($cur['stemBuf'])) return null;
            $chk = (bool)preg_match('/[xX✓✔☑☒✅]/u', $m[1]);
            return ['opt' => $this->makeOption(trim(substr($t, strlen($m[0]))), false, $chk, 'bullet', $ln['flags'])];
        }
        return null;
    }

    /** "Respuesta: B" → ['letter'=>1] | "Respuesta: Lima" → ['text'=>'Lima'] */
    private function matchAnswerLine(string $t): ?array
    {
        if (preg_match(Patterns::ANSWER_ALT, $t, $m)) return ['letter' => ord(strtolower($m[1])) - 97];
        if (preg_match(Patterns::ANSWER, $t, $m)) {
            $rest = trim($m[1]);
            if (preg_match(Patterns::ANSWER_LTR, $rest, $mm)) return ['letter' => ord(strtolower($mm[1])) - 97];
            return $rest !== '' ? ['text' => $rest] : null;
        }
        return null;
    }

    // ---- enunciados ----------------------------------------------------------
    private function joinStem(array $buf, int $start): string
    {
        $out = '';
        for ($i = $start; $i < count($buf); $i++) {
            $newLine = $buf[$i]['hard'] || !empty($buf[$i]['br']);
            $out .= ($i === $start ? '' : ($newLine ? "\n" : ' ')) . $buf[$i]['text'];
        }
        return Text::normalizeLines($out);
    }

    /** Sin numeración, la pregunta es el último bloque (párrafo u oración) */
    private function buildStem(array $buf): string
    {
        if (!$buf) return '';
        $start = 0;
        if (!$this->numbered) {
            for ($i = count($buf) - 1; $i > 0; $i--) {
                $endsSentence = preg_match('/[\.\?\!:;]$/u', $buf[$i - 1]['text']);
                if ($buf[$i]['hard'] || (!Text::startsLower($buf[$i]['text']) && $endsSentence)) { $start = $i; break; }
            }
        }
        return $this->joinStem($buf, $start);
    }

    private function buildEssayStem(array $buf): string
    {
        $start = 0;
        for ($i = count($buf) - 1; $i >= 0; $i--) {
            if ($buf[$i]['hard']) { $start = $i; break; }
        }
        return $this->joinStem($buf, $start);
    }

    private function isEssayText(string $stem): bool
    {
        if (preg_match_all('/\pL+/u', $stem) < 3) return false;
        if ($this->numbered) return true;
        return (bool)(preg_match('/\?\s*$/u', $stem) || preg_match('/^\s*¿/u', $stem) || preg_match(Patterns::ESSAY_VERB, $stem));
    }

    private function looksLikeQuestionStart(string $t): bool
    {
        return (bool)(preg_match('/^\s*¿/u', $t) || preg_match(Patterns::ESSAY_VERB, $t));
    }

    private function stemIsComplete(array $buf): bool
    {
        if (!$buf) return false;
        $s = Text::normalize(preg_replace(Patterns::POINTS, '', $this->buildStem($buf)));
        return $this->isEssayText($s) && preg_match('/[\?\.]$/u', $s);
    }

    // ---- cierre de pregunta --------------------------------------------------
    private function closeCurrent(): void
    {
        $cur = $this->cur;
        if ($cur === null) return;
        while ($cur['opts'] && end($cur['opts'])['text'] === '') array_pop($cur['opts']);     // opciones vacías al final
        $nOpt = count($cur['opts']);

        if ($nOpt >= 2) {
            $stem = Text::normalizeLines($cur['stemText'] ?? $this->buildStem($cur['stemBuf']));
            if ($stem !== '') {
                list($stem, $grade) = $this->takePoints($stem);
                $this->raws[] = ['type' => Config::TYPE_MCQ, 'text' => $stem, 'num' => $cur['num'], 'grade' => $grade,
                                 'opts' => array_slice($cur['opts'], 0, 8), 'ans' => $cur['ans'],
                                 'ctx' => (preg_match(Patterns::CTX_REF, $stem) && !preg_match(Patterns::ORDER, $stem)) ? $cur['ctx'] : ''];
            }
            return;
        }

        $stem = Text::normalizeLines($this->numbered ? $this->buildStem($cur['stemBuf']) : $this->buildEssayStem($cur['stemBuf']));
        if ($stem === '') return;

        if ($nOpt === 0 && preg_match(Patterns::TRUE_FALSE, $stem)) {                 // verdadero / falso → opción múltiple de 2
            list($stem, $grade) = $this->takePoints(trim(preg_replace(Patterns::TF_TAG, '', $stem)));
            $flags = ['hl' => false, 'col' => false, 'bold' => false, 'ul' => false];
            $opt = function ($t) use ($flags) { return ['text' => $t, 'check' => false, 'type' => 'letter', 'f' => $flags]; };
            $this->raws[] = ['type' => Config::TYPE_MCQ, 'text' => $stem, 'num' => $cur['num'], 'grade' => $grade, 'tf' => true,
                             'opts' => [$opt('Verdadero'), $opt('Falso')], 'ans' => $cur['ans'], 'ctx' => ''];
            return;
        }

        if ($nOpt === 0 && $this->isEssayText($stem)) {
            $this->raws[] = $this->buildEssay($cur, $stem);
        } elseif ($nOpt === 1 || $cur['num'] !== null) {
            $this->discarded++;
        }
    }

    /** Quita "(5 puntos)" del enunciado y lo devuelve como puntaje */
    private function takePoints(string $stem): array
    {
        if (!preg_match(Patterns::POINTS, $stem, $pm)) return [$stem, null];
        return [Text::normalizeLines(preg_replace(Patterns::POINTS, '', $stem, 1)), (float)str_replace(',', '.', $pm[1])];
    }

    private function buildEssay(array $cur, string $stem): array
    {
        list($stem, $grade) = $this->takePoints($stem);
        $grader = Text::normalize(implode(' ', $cur['grader']));
        if ($grader === '' && isset($cur['ans']['text'])) $grader = Text::normalize($cur['ans']['text']);
        return ['type' => Config::TYPE_ESSAY, 'text' => $stem, 'num' => $cur['num'], 'grader' => $grader, 'grade' => $grade];
    }
}
