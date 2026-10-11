<?php


final class Text
{
    public static function clean($s): string
    {
        if ($s === null || $s === '') return '';
        if (!preg_match('//u', $s)) {
            $c = function_exists('iconv') ? @iconv('UTF-8', 'UTF-8//IGNORE', $s) : false;
            $s = ($c !== false) ? $c : utf8_encode($s);
        }
        $s = str_replace(["\u{FB01}", "\u{FB02}"], ['fi', 'fl'], $s);
        $s = preg_replace('/[\x{F0B7}\x{F0A7}\x{F0D8}\x{F076}\x{F09F}]/u', '•', $s);
        $s = preg_replace('/[\x{F0FC}\x{F0FE}]/u', '✓', $s);
        $s = preg_replace('/[\x{F0A8}\x{F06F}]/u', '☐', $s);
        $s = preg_replace('/\x{00AD}/u', '', $s);
        return preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{FEFF}]/u', ' ', $s);
    }

    public static function normalize($text): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::clean($text)));
    }

    public static function unaccent(string $s): string
    {
        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
    }

    /** Forma comparable: minúsculas, sin tildes ni signos */
    public static function compare($s): string
    {
        $s = strtolower(self::unaccent(self::normalize($s)));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    public static function safeFilename(string $name): string
    {
        $name = preg_replace('/\.(docx|pdf|txt)$/i', '', $name);
        $name = trim(preg_replace('/[^A-Za-z0-9_\-\.]+/', '_', $name), '_');
        return $name === '' ? 'documento' : $name;
    }

    /** Como normalize(), pero conserva los saltos de línea (cada línea = un párrafo) */
    public static function normalizeLines($s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", self::clean($s));
        $lines = [];
        foreach (explode("\n", $s) as $l) {
            $l = trim(preg_replace('/[^\S\n]+/u', ' ', $l));
            if ($l !== '') $lines[] = $l;
        }
        return implode("\n", $lines);
    }

    public static function startsLower(string $t): bool
    {
        return (bool)preg_match('/^[\p{Ll},;:\)\]]/u', $t);
    }

    public static function h($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
