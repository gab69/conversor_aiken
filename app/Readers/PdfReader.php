<?php



final class PdfReader
{
    /** Devuelve varias "lecturas" alternativas del PDF; el parser se queda con la mejor */
    public static function lineSets(string $path): array
    {
        $sets = self::withPdftotext($path)
            ?: self::withSmalot($path)
            ?: self::withNaiveExtractor($path);
        if (!$sets) {
            throw new Exception('No se pudo extraer texto del PDF. Puede ser un PDF escaneado (imagen) o el servidor no tiene '
                . 'herramienta de lectura de PDF. Instala "poppler-utils" (pdftotext) o "composer require smalot/pdfparser". '
                . 'Si es un escaneo, conviértelo primero con OCR.');
        }
        return $sets;
    }

    private static function withPdftotext(string $path): array
    {
        $bin = Shell::find(['pdftotext']);
        if (!$bin) return [];
        $devnull = Shell::isWindows() ? 'NUL' : '/dev/null';
        $sets = [];
        foreach (['', '-layout'] as $opt) {
            $cmd = escapeshellarg($bin) . ' -enc UTF-8 ' . $opt . ' ' . escapeshellarg($path) . ' - 2>' . $devnull;
            $txt = @shell_exec($cmd);
            if (is_string($txt) && trim($txt) !== '') $sets[] = PlainTextReader::toLines($txt, true);
        }
        return $sets;
    }

    private static function withSmalot(string $path): array
    {
        foreach ([APP_ROOT . '/vendor/autoload.php'] as $al) {
            if (is_file($al)) { require_once $al; break; }
        }
        if (!class_exists('\Smalot\PdfParser\Parser')) return [];
        try {
            $pdf = (new \Smalot\PdfParser\Parser())->parseFile($path);
            $txt = '';
            foreach ($pdf->getPages() as $pg) $txt .= $pg->getText() . "\f";
            return trim($txt) !== '' ? [PlainTextReader::toLines($txt, true)] : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function withNaiveExtractor(string $path): array
    {
        $txt = self::naiveText($path);
        return trim($txt) !== '' ? [PlainTextReader::toLines($txt, true)] : [];
    }

    private static function unescape(string $s): string
    {
        return preg_replace_callback('/\\\\([0-7]{1,3}|.)/s', function ($m) {
            $c = $m[1];
            if (ctype_digit($c)) return chr(octdec($c) & 255);
            $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => '', 'f' => ''];
            return $map[$c] ?? $c;
        }, $s);
    }

    /** Extractor interno muy básico (PDFs con texto simple). Último recurso. */
    private static function naiveText(string $path): string
    {
        $data = @file_get_contents($path);
        if ($data === false || strpos($data, '%PDF') !== 0) return '';
        if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $data, $sm)) return '';
        $out = '';
        foreach ($sm[1] as $stream) {
            $d = @gzuncompress($stream);
            if ($d === false) $d = @gzinflate(substr($stream, 2));
            if ($d === false) $d = $stream;
            if (strpos($d, 'BT') === false || strpos($d, 'ET') === false) continue;
            if (!preg_match_all('/BT(.*?)ET/s', $d, $bt)) continue;
            foreach ($bt[1] as $blk) $out .= self::textBlock($blk) . "\n";
            $out = preg_replace("/\n{2,}/", "\n", $out) . "\f";
        }
        $utf = function_exists('iconv') ? @iconv('CP1252', 'UTF-8//IGNORE', $out) : utf8_encode($out);
        if (!is_string($utf) || strlen($utf) < 20) return '';
        $total = preg_match_all('/./su', $utf);
        $good  = preg_match_all('/[\p{L}\p{N}\s\.,;:\?\!\(\)¿¡\-]/u', $utf);
        return ($total === 0 || ($good / $total) < 0.85) ? '' : $utf;
    }

    private static function textBlock(string $blk): string
    {
        $re = '/\[((?:[^\]\\\\]|\\\\.)*)\]\s*TJ|\(((?:[^\\\\\)]|\\\\.)*)\)\s*(?:Tj|\\\'|")|(T\*|Td|TD|Tm)/s';
        if (!preg_match_all($re, $blk, $mm, PREG_SET_ORDER)) return '';
        $out = '';
        foreach ($mm as $g) {
            if (isset($g[3]) && $g[3] !== '') { $out .= "\n"; continue; }
            if (isset($g[1]) && $g[1] !== '') {
                if (!preg_match_all('/\(((?:[^\\\\\)]|\\\\.)*)\)|(-?\d+(?:\.\d+)?)/s', $g[1], $parts, PREG_SET_ORDER)) continue;
                foreach ($parts as $p) {
                    if (isset($p[1]) && $p[1] !== '') $out .= self::unescape($p[1]);
                    elseif (isset($p[2]) && $p[2] !== '' && (float)$p[2] < -200) $out .= ' ';
                }
            } elseif (isset($g[2])) {
                $out .= self::unescape($g[2]);
            }
        }
        return $out;
    }
}
