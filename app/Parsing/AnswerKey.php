<?php

/** Clave de respuestas: sección al final del examen o documento aparte ("1-A 2-B…", tabla N.º | R, "A B C…"). */
final class AnswerKey
{
    const PAIR   = '/(?<![a-z0-9])(\d{1,3})\s*[:\.\-\)–=]{0,2}\s*\(?([a-h])\)?(?![a-z0-9])/u';
    const FILLER = '/(?:secci[oó]n|parte|bloque|grupo|tema|examen|prueba|nivel|[aá]rea)\s*(?:[a-z]|\d+|[ivx]+)?\s*[:.\-–]?/iu';

    /** Separa la sección "Clave de respuestas…" del resto. Devuelve [líneas, mapa nº→índice] */
    public static function split(array $lines): array
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (!preg_match(Patterns::KEY_HEADING, $lines[$i]['text'], $m)) continue;
            $buf = substr($lines[$i]['text'], strlen($m[0]));
            for ($j = $i + 1; $j < count($lines); $j++) $buf .= ' ' . $lines[$j]['text'];
            $map = self::fromText($buf, 2);
            if ($map) return [array_slice($lines, 0, $i), $map];
        }
        return [$lines, []];
    }

    /** Documento que sólo contiene la clave: devuelve el mapa nº → índice de letra */
    public static function fromRawLines(array $rawLines): array
    {
        $flat = '';
        foreach ($rawLines as $l) $flat .= ' ' . Text::normalize($l['raw']);
        return self::fromText($flat, 5);
    }

    /** ¿Las preguntas no tienen numeración confiable (repetida o ausente)? Entonces la clave se aplica por posición */
    public static function isPositional(array $nums): bool
    {
        $nums = array_values($nums);
        $known = array_filter($nums, function ($n) { return $n !== null; });
        return count($known) < count($nums) || count($known) !== count(array_unique($known));
    }

    /** Mapa nº → índice, sólo si el texto es en su mayor parte una clave ("1-A 2-B" o "A B C B A") */
    private static function fromText(string $text, int $min): array
    {
        $flat = strtolower(Text::unaccent(Text::normalize($text)));
        $flat = preg_replace(self::FILLER, ' ', $flat);
        $size = strlen(preg_replace('/\s+/', '', $flat));
        if ($size === 0) return [];

        $pairs = []; $covered = 0;
        if (preg_match_all(self::PAIR, $flat, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $p) { $pairs[] = [(int)$p[1], ord($p[2]) - 97]; $covered += strlen(preg_replace('/\s+/', '', $p[0])); }
        }
        if (count($pairs) >= $min && $covered >= 0.5 * $size) return self::toMap($pairs);

        if (preg_match('/^[^a-z0-9]*((?:[a-h][^a-z0-9]*){4,})$/', $flat)) {       // sólo letras: "A B C B A"
            preg_match_all('/[a-h]/', $flat, $ll);
            $seq = array_map(function ($c) { return ord($c) - 97; }, $ll[0]);
            if (count($seq) >= max($min, 4)) return array_combine(range(1, count($seq)), $seq);
        }
        return [];
    }

    /** Si los números se repiten (secciones que reinician) la clave se toma en el orden en que aparece */
    private static function toMap(array $pairs): array
    {
        $nums = array_column($pairs, 0);
        $map = [];
        foreach ($pairs as $i => $p) $map[count($nums) === count(array_unique($nums)) ? $p[0] : $i + 1] = $p[1];
        return $map;
    }
}
