<?php



final class PlainTextReader
{
    /** Convierte texto (TXT o salida de PDF; páginas separadas por \f) en líneas */
    public static function toLines(string $text, bool $isPdf = false): array
    {
        $text  = str_replace(["\r\n", "\r"], "\n", Text::clean($text));
        if ($isPdf) $text = preg_replace('/(\p{L})-\n(\p{Ll})/u', '$1$2', $text);          // "pre-\nguntas" → "preguntas"
        $pages = array_map(function ($pg) { return explode("\n", $pg); }, explode("\f", $text));
        if ($isPdf && count($pages) > 1) $pages = self::stripRepeatedEdges($pages);

        $out = [];
        foreach ($pages as $rows) {
            $prevBlank = true;
            foreach ($rows as $row) {
                if (trim($row) === '') { $prevBlank = true; continue; }
                $out[] = ['raw' => $row, 'tokens' => null, 'hard' => $prevBlank, 'cell' => false, 'auto' => null];
                $prevBlank = false;
            }
        }
        return $out;
    }

    /** Quita números de página y encabezados/pies repetidos */
    private static function stripRepeatedEdges(array $pages): array
    {
        $pageNumRe = '/^\s*(?:p[aá]g(?:ina)?\.?\s*)?\d{1,4}(?:\s*(?:de|\/)\s*\d{1,4})?\s*$/iu';
        $nonBlank  = function ($rows) { return array_keys(array_filter($rows, function ($r) { return trim($r) !== ''; })); };

        $edge = [];
        foreach ($pages as $rows) {
            $idx = $nonBlank($rows);
            if (!$idx) continue;
            foreach ([reset($idx), end($idx)] as $k) {
                $key = Text::compare($rows[$k]);
                if ($key !== '') $edge[$key] = ($edge[$key] ?? 0) + 1;
            }
        }
        $minRepeat = max(3, (int)ceil(count($pages) * 0.6));
        foreach ($pages as &$rows) {
            $idx = $nonBlank($rows);
            if (!$idx) continue;
            foreach (array_unique([reset($idx), end($idx)]) as $k) {
                $key = Text::compare($rows[$k]);
                if (preg_match($pageNumRe, $rows[$k]) || ($key !== '' && ($edge[$key] ?? 0) >= $minRepeat)) $rows[$k] = '';
            }
        }
        unset($rows);
        return $pages;
    }
}
