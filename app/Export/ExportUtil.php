<?php


final class ExportUtil
{
    public static function questionName(string $text, int $n): string
    {
        $text = preg_replace(ImageStore::MARK, '', $text);
        $t = trim(preg_replace('/[:{}~=#\\\\]/u', '', Text::normalize($text)));
        $short = preg_match('/^.{0,60}/u', $t, $m) ? $m[0] : $t;
        if (strlen($short) < strlen($t)) $short = rtrim($short) . '...';
        return sprintf('P%02d - %s', $n, $short !== '' ? $short : 'Pregunta');
    }

    public static function categoryName(string $s): string
    {
        $s = trim(preg_replace('/[\/\\\\\r\n:{}]+/u', ' ', Text::normalize($s)));
        if (preg_match('/^.{0,80}/u', $s, $m)) $s = trim($m[0]);
        return $s !== '' ? $s : 'Importadas';
    }

    public static function unitLabel(array $meta, string $fallback): string
    {
        $exp = null; $ses = null;
        foreach ($meta as $k => $v) {
            $kl = strtolower($k);
            if (strpos($kl, 'experiencia') !== false) $exp = $v;
            if (strpos($kl, 'sesi') !== false || strpos($kl, 'semana') !== false) $ses = $v;
        }
        $parts = array_filter([$exp, $ses ? ('Sesión/Semana ' . $ses) : null]);
        return $parts ? implode(' — ', $parts) : $fallback;
    }
}
