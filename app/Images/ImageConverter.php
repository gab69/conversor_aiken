<?php

/** Convierte imágenes vectoriales de Word (WMF/EMF: ecuaciones, dibujos) a PNG recortado */
final class ImageConverter
{
    const SCALE = 0.32;     // 300 dpi → 96 dpi (tamaño natural en pantalla)

    /** @param array $files id => ['ext','data'] @return array id => PNG binario */
    public static function vectorToPng(array $files): array
    {
        if (!$files || !Shell::available()) return [];
        @set_time_limit(300);
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'moodle_img_' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true)) return [];
        $paths = [];
        foreach ($files as $id => $f) {
            $paths[$id] = $dir . DIRECTORY_SEPARATOR . 'v' . $id . '.' . $f['ext'];
            file_put_contents($paths[$id], $f['data']);
        }
        $done = self::viaLibreOffice($dir, $paths);
        foreach (array_diff_key($paths, $done) as $id => $path) {
            $png = self::viaImageMagick($path) ?? self::viaInkscape($path);
            if ($png !== null) $done[$id] = $png;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) is_dir($f) ? self::rrmdir($f) : @unlink($f);
        @rmdir($dir);
        return array_map([self::class, 'trim'], $done);
    }

    private static function rrmdir(string $d): void
    {
        foreach (glob($d . DIRECTORY_SEPARATOR . '*') ?: [] as $f) is_dir($f) ? self::rrmdir($f) : @unlink($f);
        foreach (glob($d . DIRECTORY_SEPARATOR . '.*') ?: [] as $f) if (is_file($f)) @unlink($f);
        @rmdir($d);
    }

    private static function viaLibreOffice(string $dir, array $paths): array
    {
        $bin = Shell::find(['soffice', 'libreoffice'], [
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe', 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ]);
        if (!$bin) return [];
        $out = $dir . DIRECTORY_SEPARATOR . 'out';
        @mkdir($out);
        $opts   = '{"PixelWidth":{"type":"long","value":"2480"},"PixelHeight":{"type":"long","value":"3508"}}';
        $filter = 'png:draw_png_Export:' . $opts;
        $filterArg = Shell::isWindows() ? '"' . str_replace('"', '\\"', $filter) . '"' : escapeshellarg($filter);
        $profile = 'file:///' . ltrim(str_replace('\\', '/', $dir), '/') . '/profile';
        $cmd = escapeshellarg($bin) . ' ' . escapeshellarg('-env:UserInstallation=' . $profile)
             . ' --headless --norestore --convert-to ' . $filterArg . ' --outdir ' . escapeshellarg($out) . ' '
             . implode(' ', array_map('escapeshellarg', $paths)) . ' 2>&1';
        @shell_exec($cmd);
        $done = [];
        foreach ($paths as $id => $path) {
            $png = $out . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '.png';
            if (is_file($png) && filesize($png) > 0) $done[$id] = file_get_contents($png);
        }
        return $done;
    }

    private static function viaImageMagick(string $path): ?string
    {
        $bin = Shell::find(Shell::isWindows() ? ['magick'] : ['magick', 'convert']);
        return $bin ? self::runToPng(escapeshellarg($bin) . ' -density 300 -background white -flatten ' . escapeshellarg($path) . ' %OUT%') : null;
    }

    private static function viaInkscape(string $path): ?string
    {
        $bin = Shell::find(['inkscape']);
        return $bin ? self::runToPng(escapeshellarg($bin) . ' ' . escapeshellarg($path) . ' --export-type=png --export-dpi=300 --export-filename=%OUT%') : null;
    }

    private static function runToPng(string $cmdTemplate): ?string
    {
        $out = tempnam(sys_get_temp_dir(), 'moodle_png_') . '.png';
        @shell_exec(str_replace('%OUT%', escapeshellarg($out), $cmdTemplate) . ' 2>&1');
        $png = (is_file($out) && filesize($out) > 0) ? file_get_contents($out) : null;
        @unlink($out);
        return $png;
    }

    /** Plan B del recorte cuando PHP no tiene la extensión GD */
    private static function trimWithMagick(string $png): ?string
    {
        $bin = Shell::find(Shell::isWindows() ? ['magick'] : ['magick', 'convert']);
        if (!$bin) return null;
        $in = tempnam(sys_get_temp_dir(), 'moodle_in_') . '.png';
        file_put_contents($in, $png);
        $res = self::runToPng(escapeshellarg($bin) . ' ' . escapeshellarg($in) . ' -trim +repage -bordercolor white -border 8 %OUT%');
        @unlink($in);
        return $res;
    }

    /** Recorta el espacio en blanco y deja un margen de 8 px */
    private static function trim(string $png): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecropauto')) return self::trimWithMagick($png) ?? $png;
        $im = @imagecreatefromstring($png);
        if (!$im) return $png;
        $crop = @imagecropauto($im, IMG_CROP_WHITE);
        if (!$crop) return $png;
        $w = imagesx($crop) + 16; $h = imagesy($crop) + 16;
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $crop, 8, 8, 0, 0, imagesx($crop), imagesy($crop));
        ob_start(); imagepng($canvas); return (string)ob_get_clean();
    }
}
