<?php


final class Shell
{
    public static function available(): bool
    {
        if (!function_exists('shell_exec')) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('shell_exec', $disabled, true);
    }

    public static function isWindows(): bool { return stripos(PHP_OS, 'WIN') === 0; }

    /** Ruta de un programa instalado (o null) */
    public static function find(array $names, array $extraPaths = []): ?string
    {
        foreach ($extraPaths as $p) if (is_file($p)) return $p;
        if (!self::available()) return null;
        foreach ($names as $n) {
            $out = trim((string)@shell_exec(self::isWindows() ? 'where ' . $n . ' 2>NUL' : 'command -v ' . $n . ' 2>/dev/null'));
            $first = trim((string)strtok($out, "\r\n"));
            if ($first !== '') return $first;
        }
        return null;
    }
}
