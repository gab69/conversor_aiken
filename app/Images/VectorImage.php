<?php

/**
 * Convierte imágenes vectoriales de Word (WMF/EMF: ecuaciones de MathType, figuras) a SVG usando sólo PHP.
 * No necesita LibreOffice, ImageMagick ni GD. El SVG conserva la posición exacta de cada carácter y trazo.
 */
final class VectorImage
{
    /** Alfabeto de la fuente Symbol (códigos 0x20–0xFF) → Unicode */
    const SYMBOL = [
        0x22 => '∀', 0x24 => '∃', 0x27 => '∋', 0x2A => '∗', 0x2D => '−', 0x40 => '≅',
        0x41 => 'Α', 0x42 => 'Β', 0x43 => 'Χ', 0x44 => 'Δ', 0x45 => 'Ε', 0x46 => 'Φ', 0x47 => 'Γ', 0x48 => 'Η', 0x49 => 'Ι', 0x4A => 'ϑ',
        0x4B => 'Κ', 0x4C => 'Λ', 0x4D => 'Μ', 0x4E => 'Ν', 0x4F => 'Ο', 0x50 => 'Π', 0x51 => 'Θ', 0x52 => 'Ρ', 0x53 => 'Σ', 0x54 => 'Τ',
        0x55 => 'Υ', 0x56 => 'ς', 0x57 => 'Ω', 0x58 => 'Ξ', 0x59 => 'Ψ', 0x5A => 'Ζ', 0x5C => '∴', 0x5E => '⊥', 0x60 => '‾',
        0x61 => 'α', 0x62 => 'β', 0x63 => 'χ', 0x64 => 'δ', 0x65 => 'ε', 0x66 => 'φ', 0x67 => 'γ', 0x68 => 'η', 0x69 => 'ι', 0x6A => 'ϕ',
        0x6B => 'κ', 0x6C => 'λ', 0x6D => 'μ', 0x6E => 'ν', 0x6F => 'ο', 0x70 => 'π', 0x71 => 'θ', 0x72 => 'ρ', 0x73 => 'σ', 0x74 => 'τ',
        0x75 => 'υ', 0x76 => 'ϖ', 0x77 => 'ω', 0x78 => 'ξ', 0x79 => 'ψ', 0x7A => 'ζ', 0x7E => '∼',
        0xA0 => '€', 0xA1 => 'ϒ', 0xA2 => '′', 0xA3 => '≤', 0xA4 => '⁄', 0xA5 => '∞', 0xA6 => 'ƒ', 0xA7 => '♣', 0xA8 => '♦', 0xA9 => '♥',
        0xAA => '♠', 0xAB => '↔', 0xAC => '←', 0xAD => '↑', 0xAE => '→', 0xAF => '↓', 0xB0 => '°', 0xB1 => '±', 0xB2 => '″', 0xB3 => '≥',
        0xB4 => '×', 0xB5 => '∝', 0xB6 => '∂', 0xB7 => '•', 0xB8 => '÷', 0xB9 => '≠', 0xBA => '≡', 0xBB => '≈', 0xBC => '…', 0xBD => '⏐',
        0xBE => '⎯', 0xBF => '↵', 0xC0 => 'ℵ', 0xC1 => 'ℑ', 0xC2 => 'ℜ', 0xC3 => '℘', 0xC4 => '⊗', 0xC5 => '⊕', 0xC6 => '∅', 0xC7 => '∩',
        0xC8 => '∪', 0xC9 => '⊃', 0xCA => '⊇', 0xCB => '⊄', 0xCC => '⊂', 0xCD => '⊆', 0xCE => '∈', 0xCF => '∉', 0xD0 => '∠', 0xD1 => '∇',
        0xD2 => '®', 0xD3 => '©', 0xD4 => '™', 0xD5 => '∏', 0xD6 => '√', 0xD7 => '⋅', 0xD8 => '¬', 0xD9 => '∧', 0xDA => '∨', 0xDB => '⇔',
        0xDC => '⇐', 0xDD => '⇑', 0xDE => '⇒', 0xDF => '⇓', 0xE0 => '◊', 0xE1 => '〈', 0xE5 => '∑', 0xE6 => '⎛', 0xE7 => '⎜', 0xE8 => '⎝',
        0xE9 => '⎡', 0xEA => '⎢', 0xEB => '⎣', 0xEC => '⎧', 0xED => '⎨', 0xEE => '⎩', 0xEF => '⎪', 0xF1 => '〉', 0xF2 => '∫', 0xF3 => '⌠', 0xF4 => '⎮', 0xF5 => '⌡', 0xF6 => '⎞', 0xF7 => '⎟',
        0xF8 => '⎠', 0xF9 => '⎤', 0xFA => '⎥', 0xFB => '⎦', 0xFC => '⎫', 0xFD => '⎬', 0xFE => '⎭',
    ];

    /** @return array|null ['svg' => string, 'w' => px, 'h' => px] */
    public static function toSvg(string $data, string $ext): ?array
    {
        try {
            if ($ext === 'wmf') return self::wmf($data);
            if ($ext === 'emf') return self::emf($data);
        } catch (Throwable $e) { /* se intentará con otra herramienta */ }
        return null;
    }

    // ---- lectura binaria ---------------------------------------------------
    private static function u16($d, $o) { return ord($d[$o]) | (ord($d[$o + 1]) << 8); }
    private static function i16($d, $o) { $v = self::u16($d, $o); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private static function u32($d, $o) { return self::u16($d, $o) | (self::u16($d, $o + 2) << 16); }
    private static function i32($d, $o) { $v = self::u32($d, $o); return $v >= 0x80000000 ? $v - 0x100000000 : $v; }
    private static function f32($d, $o) { return unpack('g', substr($d, $o, 4))[1]; }
    private static function rgb($d, $o) { return 'rgb(' . ord($d[$o]) . ',' . ord($d[$o + 1]) . ',' . ord($d[$o + 2]) . ')'; }
    private static function n($v): string { return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.') ?: '0'; }
    private static function esc(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

    private static function family(string $face): string
    {
        $f = strtolower($face);
        if (strpos($f, 'arial') !== false || strpos($f, 'helvetica') !== false || strpos($f, 'calibri') !== false) return "Arial,Helvetica,'Liberation Sans',sans-serif";
        if (strpos($f, 'courier') !== false || strpos($f, 'consolas') !== false) return "'Courier New',monospace";
        return "'Times New Roman',Times,'Liberation Serif',serif";
    }

    /** Bytes (ANSI/Symbol) → lista de caracteres UTF-8, uno por byte */
    private static function bytesToChars(string $s, string $face, int $charset): array
    {
        $out = [];
        $symbol = (strcasecmp($face, 'Symbol') === 0 || $charset === 2);
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = ord($s[$i]);
            if ($symbol && isset(self::SYMBOL[$c])) $out[] = self::SYMBOL[$c];
            elseif ($c < 0x80) $out[] = chr($c);
            else $out[] = function_exists('iconv') ? (string)@iconv('CP1252', 'UTF-8//IGNORE', chr($c)) : utf8_encode(chr($c));
        }
        return $out;
    }

    private static function textEl(array $chars, array $xs, float $y, array $font, string $fill): string
    {
        if (!$chars || trim(implode('', $chars)) === '') return '';
        $size = max(1, abs($font['h'])) ;
        return '<text x="' . implode(' ', array_map([self::class, 'n'], $xs)) . '" y="' . self::n($y) . '" font-family="' . self::esc(self::family($font['face'])) . '"'
             . ' font-size="' . self::n($size) . '" fill="' . $fill . '"' . ($font['italic'] ? ' font-style="italic"' : '')
             . ($font['weight'] >= 700 ? ' font-weight="bold"' : '') . ' xml:space="preserve">' . self::esc(implode('', $chars)) . '</text>';
    }

    /** $min = grosor mínimo (1 px de pantalla) expresado en unidades lógicas */
    private static function strokeAttr(array $pen, float $k, float $min = 0): string
    {
        if ($pen['null']) return ' stroke="none"';
        return ' stroke="' . $pen['color'] . '" stroke-width="' . self::n(max($pen['w'] > 0 ? $pen['w'] : $k, $min)) . '" stroke-linecap="round" stroke-linejoin="round"';
    }

    private static function fillAttr(array $br): string
    {
        return $br['null'] ? ' fill="none"' : ' fill="' . $br['color'] . '"';
    }

    private static function finish(array $els, array $vb, float $wpx, float $hpx): ?array
    {
        if (!$els || $vb[2] <= 0 || $vb[3] <= 0 || $wpx <= 0 || $hpx <= 0) return null;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . self::n($wpx) . '" height="' . self::n($hpx) . '" viewBox="'
             . implode(' ', array_map([self::class, 'n'], $vb)) . '">' . implode('', $els) . '</svg>';
        return ['svg' => $svg, 'w' => max(1, (int)round($wpx)), 'h' => max(1, (int)round($hpx))];
    }

    // ---- WMF ---------------------------------------------------------------
    private static function wmf(string $d): ?array
    {
        $n = strlen($d); $off = 0; $bbox = null; $inch = 1440;
        if ($n > 40 && substr($d, 0, 4) === "\xD7\xCD\xC6\x9A") {
            $bbox = [self::i16($d, 6), self::i16($d, 8), self::i16($d, 10), self::i16($d, 12)];
            $inch = self::u16($d, 14) ?: 1440;
            $off = 22;
        }
        $off += 18;
        $org = [0, 0]; $ext = null; $pos = [0, 0]; $align = 0; $tcol = 'rgb(0,0,0)';
        $font = ['h' => -12, 'face' => 'Times New Roman', 'italic' => false, 'weight' => 400, 'charset' => 0];
        $pen = ['null' => false, 'color' => 'rgb(0,0,0)', 'w' => 0]; $brush = ['null' => false, 'color' => 'rgb(255,255,255)'];
        $objs = []; $els = []; $items = [];
        while ($off + 6 <= $n) {
            $size = self::u32($d, $off); $fn = self::u16($d, $off + 4);
            if ($size < 3 || $off + $size * 2 > $n) break;
            $p = substr($d, $off + 6, $size * 2 - 6); $off += $size * 2;
            switch ($fn) {
                case 0x020B: $org = [self::i16($p, 2), self::i16($p, 0)]; break;
                case 0x020C: $ext = [self::i16($p, 2), self::i16($p, 0)]; break;
                case 0x0209: $tcol = self::rgb($p, 0); break;
                case 0x012E: $align = self::u16($p, 0); break;
                case 0x02FB:
                    $face = explode("\0", substr($p, 18))[0];
                    $o = ['t' => 'font', 'h' => self::i16($p, 0), 'italic' => ord($p[10]) !== 0, 'weight' => self::u16($p, 8), 'charset' => ord($p[13]), 'face' => $face];
                    $objs[self::slot($objs)] = $o; break;
                case 0x02FA:
                    $objs[self::slot($objs)] = ['t' => 'pen', 'null' => (self::u16($p, 0) & 0xF) === 5, 'w' => self::i16($p, 2), 'color' => self::rgb($p, 6)]; break;
                case 0x02FC:
                    $objs[self::slot($objs)] = ['t' => 'brush', 'null' => self::u16($p, 0) === 1, 'color' => self::rgb($p, 2)]; break;
                case 0x012D:
                    $o = $objs[self::u16($p, 0)] ?? null;
                    if ($o) { if ($o['t'] === 'font') $font = $o; elseif ($o['t'] === 'pen') $pen = $o; else $brush = $o; }
                    break;
                case 0x01F0: unset($objs[self::u16($p, 0)]); break;
                case 0x0214: $pos = [self::i16($p, 2), self::i16($p, 0)]; break;
                case 0x0213:
                    $to = [self::i16($p, 2), self::i16($p, 0)];
                    $items[] = ['line', $pos, $to, $pen]; $pos = $to; break;
                case 0x0325: case 0x0324:
                    $c = self::u16($p, 0); $pts = [];
                    for ($i = 0; $i < $c && 2 + $i * 4 + 4 <= strlen($p); $i++) $pts[] = [self::i16($p, 2 + $i * 4), self::i16($p, 4 + $i * 4)];
                    $items[] = [$fn === 0x0325 ? 'polyline' : 'polygon', $pts, $pen, $brush]; break;
                case 0x041B: case 0x0418:
                    $items[] = [$fn === 0x041B ? 'rect' : 'ellipse', [self::i16($p, 6), self::i16($p, 4), self::i16($p, 2), self::i16($p, 0)], $pen, $brush]; break;
                case 0x0817:
                    $items[] = ['arc', [self::i16($p, 14), self::i16($p, 12), self::i16($p, 10), self::i16($p, 8)], [self::i16($p, 6), self::i16($p, 4)], [self::i16($p, 2), self::i16($p, 0)], $pen]; break;
                case 0x0A32:
                    $y = self::i16($p, 0); $x = self::i16($p, 2); $len = self::u16($p, 4); $opts = self::u16($p, 6);
                    $q = 8 + (($opts & 6) ? 8 : 0);
                    $txt = substr($p, $q, $len); $q += $len + ($len & 1);
                    $dx = [];
                    if (strlen($p) - $q >= $len * 2) for ($i = 0; $i < $len; $i++) $dx[] = self::i16($p, $q + $i * 2);
                    if ($align & 1) { $x = $pos[0]; $y = $pos[1]; $pos[0] += $dx ? array_sum($dx) : $len * max(1, abs($font['h'])) * 0.5; }
                    $items[] = ['text', $x, $y, $txt, $dx, $font, $align, $tcol]; break;
                case 0x0521:
                    $len = self::u16($p, 0); $txt = substr($p, 2, $len); $q = 2 + $len + ($len & 1);
                    $tx = self::i16($p, $q + 2); $ty = self::i16($p, $q);
                    if ($align & 1) { $tx = $pos[0]; $ty = $pos[1]; $pos[0] += $len * max(1, abs($font['h'])) * 0.5; }
                    $items[] = ['text', $tx, $ty, $txt, [], $font, $align, $tcol]; break;
            }
        }
        // ventana lógica → viewBox
        if ($ext && $ext[0] != 0 && $ext[1] != 0) $vb = [min($org[0], $org[0] + $ext[0]), min($org[1], $org[1] + $ext[1]), abs($ext[0]), abs($ext[1])];
        elseif ($bbox) $vb = [$bbox[0], $bbox[1], abs($bbox[2] - $bbox[0]), abs($bbox[3] - $bbox[1])];
        else return null;
        if ($bbox && abs($bbox[2] - $bbox[0]) > 0 && abs($bbox[3] - $bbox[1]) > 0) { $wpx = abs($bbox[2] - $bbox[0]) / $inch * 96; $hpx = abs($bbox[3] - $bbox[1]) / $inch * 96; }
        else { $wpx = $vb[2]; $hpx = $vb[3]; }
        $k = $wpx > 0 ? $vb[2] / $wpx : 1;
        foreach ($items as $it) { $e = self::wmfItem($it, $k); if ($e !== '') $els[] = $e; }
        return self::finish($els, $vb, $wpx, $hpx);
    }

    private static function slot(array $objs): int { $i = 0; while (isset($objs[$i])) $i++; return $i; }

    private static function wmfItem(array $it, float $k): string
    {
        switch ($it[0]) {
            case 'line':
                return '<line x1="' . self::n($it[1][0]) . '" y1="' . self::n($it[1][1]) . '" x2="' . self::n($it[2][0]) . '" y2="' . self::n($it[2][1]) . '"' . self::strokeAttr($it[3], $k) . '/>';
            case 'polyline': case 'polygon':
                if (count($it[1]) < 2) return '';
                $pts = implode(' ', array_map(function ($q) { return self::n($q[0]) . ',' . self::n($q[1]); }, $it[1]));
                return $it[0] === 'polyline'
                    ? '<polyline points="' . $pts . '" fill="none"' . self::strokeAttr($it[2], $k) . '/>'
                    : '<polygon points="' . $pts . '"' . self::fillAttr($it[3]) . self::strokeAttr($it[2], $k) . '/>';
            case 'rect':
                return '<rect x="' . self::n($it[1][0]) . '" y="' . self::n($it[1][1]) . '" width="' . self::n($it[1][2] - $it[1][0]) . '" height="' . self::n($it[1][3] - $it[1][1]) . '"' . self::fillAttr($it[3]) . self::strokeAttr($it[2], $k) . '/>';
            case 'ellipse':
                return '<ellipse cx="' . self::n(($it[1][0] + $it[1][2]) / 2) . '" cy="' . self::n(($it[1][1] + $it[1][3]) / 2) . '" rx="' . self::n(abs($it[1][2] - $it[1][0]) / 2) . '" ry="' . self::n(abs($it[1][3] - $it[1][1]) / 2) . '"' . self::fillAttr($it[3]) . self::strokeAttr($it[2], $k) . '/>';
            case 'arc':
                return self::arcPath($it[1], $it[2], $it[3], $it[4], $k);
            case 'text':
                list(, $x, $y, $txt, $dx, $font, $align, $col) = $it;
                $chars = self::bytesToChars($txt, $font['face'], $font['charset']);
                $size = max(1, abs($font['h']));
                $xs = []; $cx = $x;
                foreach ($chars as $i => $c) { $xs[] = $cx; $cx += $dx[$i] ?? $size * 0.5; }
                $total = $cx - $x;
                if (($align & 6) === 6) $shift = -$total / 2; elseif ($align & 2) $shift = -$total; else $shift = 0;
                $xs = array_map(function ($v) use ($shift) { return $v + $shift; }, $xs);
                $va = $align & 24;
                $base = $va === 0 ? $y + $size * 0.85 : ($va === 8 ? $y - $size * 0.2 : $y);
                return self::textEl($chars, $xs, $base, $font, $col);
        }
        return '';
    }

    /** Arco GDI (sentido antihorario) → <path> de SVG */
    private static function arcPath(array $r, array $s, array $e, array $pen, float $k): string
    {
        $cx = ($r[0] + $r[2]) / 2; $cy = ($r[1] + $r[3]) / 2; $rx = abs($r[2] - $r[0]) / 2; $ry = abs($r[3] - $r[1]) / 2;
        if ($rx <= 0 || $ry <= 0) return '';
        $pt = function ($q) use ($cx, $cy, $rx, $ry) {
            $a = atan2(($q[1] - $cy) / $ry, ($q[0] - $cx) / $rx);
            return [$cx + $rx * cos($a), $cy + $ry * sin($a), $a];
        };
        $a = $pt($s); $b = $pt($e);
        $sweep = fmod($a[2] - $b[2] + 4 * M_PI, 2 * M_PI);       // recorrido antihorario en pantalla
        if ($sweep == 0) $sweep = 2 * M_PI;
        $large = $sweep > M_PI ? 1 : 0;
        return '<path d="M' . self::n($a[0]) . ' ' . self::n($a[1]) . ' A' . self::n($rx) . ' ' . self::n($ry) . ' 0 ' . $large . ' 0 ' . self::n($b[0]) . ' ' . self::n($b[1]) . '" fill="none"' . self::strokeAttr($pen, $k) . '/>';
    }

    // ---- EMF ---------------------------------------------------------------
    private static function mul(array $a, array $b): array
    {
        return [$a[0] * $b[0] + $a[1] * $b[2], $a[0] * $b[1] + $a[1] * $b[3], $a[2] * $b[0] + $a[3] * $b[2], $a[2] * $b[1] + $a[3] * $b[3],
                $a[4] * $b[0] + $a[5] * $b[2] + $b[4], $a[4] * $b[1] + $a[5] * $b[3] + $b[5]];
    }

    private static function emf(string $d): ?array
    {
        $n = strlen($d);
        if ($n < 88 || self::u32($d, 0) !== 1) return null;
        $bounds = [self::i32($d, 8), self::i32($d, 12), self::i32($d, 16), self::i32($d, 20)];
        $frame  = [self::i32($d, 24), self::i32($d, 28), self::i32($d, 32), self::i32($d, 36)];
        $dev = [self::i32($d, 72), self::i32($d, 76)]; $mm = [self::i32($d, 80), self::i32($d, 84)];
        $fw = ($frame[2] - $frame[0]) / 100; $fh = ($frame[3] - $frame[1]) / 100;       // mm
        if ($bounds[2] > $bounds[0] && $bounds[3] > $bounds[1]) $vb = [$bounds[0], $bounds[1], $bounds[2] - $bounds[0] + 1, $bounds[3] - $bounds[1] + 1];
        elseif ($fw > 0 && $fh > 0 && $mm[0] > 0 && $mm[1] > 0) $vb = [0, 0, $fw * $dev[0] / $mm[0], $fh * $dev[1] / $mm[1]];
        else return null;
        if ($fw > 0 && $fh > 0) { $wpx = $fw / 25.4 * 96; $hpx = $fh / 25.4 * 96; } else { $wpx = $vb[2]; $hpx = $vb[3]; }
        $k = $wpx > 0 ? $vb[2] / $wpx : 1;

        $objs = []; $els = []; $M = [1, 0, 0, 1, 0, 0]; $pos = [0, 0]; $align = 0; $tcol = 'rgb(0,0,0)';
        $font = ['h' => -12, 'face' => 'Times New Roman', 'italic' => false, 'weight' => 400, 'charset' => 0];
        $pen = ['null' => false, 'color' => 'rgb(0,0,0)', 'w' => 0]; $brush = ['null' => false, 'color' => 'rgb(255,255,255)'];
        $path = null; $cur = '';
        $emit = function (string $el) use (&$els, &$M) {
            if ($el === '') return;
            if ($M !== [1, 0, 0, 1, 0, 0]) $el = '<g transform="matrix(' . implode(' ', array_map([self::class, 'n'], $M)) . ')">' . $el . '</g>';
            $els[] = $el;
        };
        $pts = function ($rec, $o, $cnt, $wide) {          // puntos del registro actual (16 o 32 bits)
            $r = [];
            for ($i = 0; $i < $cnt && $o + ($wide ? 8 : 4) * ($i + 1) <= strlen($rec); $i++) {
                $r[] = $wide ? [self::i32($rec, $o + $i * 8), self::i32($rec, $o + $i * 8 + 4)] : [self::i16($rec, $o + $i * 4), self::i16($rec, $o + $i * 4 + 2)];
            }
            return $r;
        };
        $fmt = function (array $q) { return self::n($q[0]) . ' ' . self::n($q[1]); };
        $stroke = function () use (&$pen, &$M, $k) {
            $sc = sqrt(abs($M[0] * $M[3] - $M[1] * $M[2]));
            return self::strokeAttr($pen, $k, $sc > 0 ? $k / $sc : $k);
        };

        for ($off = 0; $off + 8 <= $n;) {
            $type = self::u32($d, $off); $size = self::u32($d, $off + 4);
            if ($size < 8 || $off + $size > $n) break;
            $rec = substr($d, $off, $size); $off += $size;
            if ($type === 14) break;
            switch ($type) {
                case 35: $M = [self::f32($rec, 8), self::f32($rec, 12), self::f32($rec, 16), self::f32($rec, 20), self::f32($rec, 24), self::f32($rec, 28)]; break;
                case 36:
                    $X = [self::f32($rec, 8), self::f32($rec, 12), self::f32($rec, 16), self::f32($rec, 20), self::f32($rec, 24), self::f32($rec, 28)];
                    $mode = self::u32($rec, 32);
                    if ($mode === 1) $M = [1, 0, 0, 1, 0, 0]; elseif ($mode === 2) $M = self::mul($X, $M); elseif ($mode === 3) $M = self::mul($M, $X); elseif ($mode === 4) $M = $X;
                    break;
                case 22: $align = self::u32($rec, 8); break;
                case 24: $tcol = self::rgb($rec, 8); break;
                case 38: $objs[self::u32($rec, 8)] = ['t' => 'pen', 'null' => (self::u32($rec, 12) & 0xF) === 5, 'w' => self::i32($rec, 16), 'color' => self::rgb($rec, 24)]; break;
                case 95: $objs[self::u32($rec, 8)] = ['t' => 'pen', 'null' => (self::u32($rec, 28) & 0xF) === 5, 'w' => self::u32($rec, 32), 'color' => self::rgb($rec, 40)]; break;
                case 39: $objs[self::u32($rec, 8)] = ['t' => 'brush', 'null' => self::u32($rec, 12) === 1, 'color' => self::rgb($rec, 16)]; break;
                case 82:
                    $face = (string)@iconv('UTF-16LE', 'UTF-8//IGNORE', explode("\0\0", substr($rec, 40, 64) . "\0\0")[0] . "\0");
                    $objs[self::u32($rec, 8)] = ['t' => 'font', 'h' => self::i32($rec, 12), 'italic' => ord($rec[32]) !== 0, 'weight' => self::i32($rec, 28), 'charset' => ord($rec[35]), 'face' => trim($face, "\0")];
                    break;
                case 37:
                    $h = self::u32($rec, 8);
                    if ($h & 0x80000000) {
                        $sn = $h & 0x7FFFFFFF;
                        if ($sn === 5) $brush = ['null' => true, 'color' => 'none']; elseif ($sn === 4) $brush = ['null' => false, 'color' => 'rgb(0,0,0)'];
                        elseif ($sn === 0) $brush = ['null' => false, 'color' => 'rgb(255,255,255)']; elseif ($sn === 8) $pen = ['null' => true, 'color' => 'none', 'w' => 0];
                        elseif ($sn === 7) $pen = ['null' => false, 'color' => 'rgb(0,0,0)', 'w' => 0]; elseif ($sn === 6) $pen = ['null' => false, 'color' => 'rgb(255,255,255)', 'w' => 0];
                    } elseif (isset($objs[$h])) {
                        $o = $objs[$h];
                        if ($o['t'] === 'font') $font = $o; elseif ($o['t'] === 'pen') $pen = $o; else $brush = $o;
                    }
                    break;
                case 40: unset($objs[self::u32($rec, 8)]); break;
                case 59: $path = ''; break;
                case 60: break;
                case 61: if ($path !== null) $path .= 'Z '; break;
                case 62: case 63: case 64:
                    if ($path !== null && trim($path) !== '') {
                        $f = ($type === 64) ? ' fill="none"' : self::fillAttr($brush);
                        $s = ($type === 62) ? ' stroke="none"' : $stroke();
                        $emit('<path d="' . trim($path) . '"' . $f . $s . '/>');
                    }
                    $path = null; break;
                case 27: $pos = [self::i32($rec, 8), self::i32($rec, 12)]; if ($path !== null) $path .= 'M' . $fmt($pos) . ' '; break;
                case 54:
                    $to = [self::i32($rec, 8), self::i32($rec, 12)];
                    if ($path !== null) $path .= 'L' . $fmt($to) . ' ';
                    else $emit('<line x1="' . self::n($pos[0]) . '" y1="' . self::n($pos[1]) . '" x2="' . self::n($to[0]) . '" y2="' . self::n($to[1]) . '"' . $stroke() . '/>');
                    $pos = $to; break;
                case 2: case 3: case 4: case 5: case 6: case 85: case 86: case 87: case 88: case 89:
                    $wide = $type <= 6; $cnt = self::u32($rec, 24); $P = $pts($rec, 28, $cnt, $wide);
                    if (!$P) break;
                    $bez = in_array($type, [2, 5, 85, 88], true); $to = in_array($type, [5, 6, 88, 89], true); $poly = in_array($type, [3, 86], true);
                    $d2 = $to ? '' : 'M' . $fmt($P[0]) . ' ';
                    $rest = $to ? $P : array_slice($P, 1);
                    if ($bez) { for ($i = 0; $i + 2 < count($rest); $i += 3) $d2 .= 'C' . $fmt($rest[$i]) . ' ' . $fmt($rest[$i + 1]) . ' ' . $fmt($rest[$i + 2]) . ' '; }
                    else foreach ($rest as $q) $d2 .= 'L' . $fmt($q) . ' ';
                    if ($poly) $d2 .= 'Z ';
                    $pos = end($P);
                    if ($path !== null) { $path .= $d2; break; }
                    $emit('<path d="' . trim($d2) . '"' . ($poly ? self::fillAttr($brush) : ' fill="none"') . $stroke() . '/>');
                    break;
                case 43: case 42:
                    $r = [self::i32($rec, 8), self::i32($rec, 12), self::i32($rec, 16), self::i32($rec, 20)];
                    $emit(self::wmfItem([$type === 43 ? 'rect' : 'ellipse', $r, $pen, $brush], $k)); break;
                case 83: case 84:
                    // EMR_EXTTEXTOUT: tras Bounds(16), modo(4), exScale(4), eyScale(4) viene EMRTEXT en el byte 36
                    $x = self::i32($rec, 36); $y = self::i32($rec, 40);
                    $len = self::u32($rec, 44); $offS = self::u32($rec, 48); $offD = self::u32($rec, 72);
                    if ($len < 1 || $len > 5000) break;
                    if ($align & 1) { $x = $pos[0]; $y = $pos[1]; }
                    if ($type === 84) {
                        $u = (string)@iconv('UTF-16LE', 'UTF-8//IGNORE', substr($rec, $offS, $len * 2));
                        preg_match_all('/./su', $u, $m); $chars = $m[0];
                    } else $chars = self::bytesToChars(substr($rec, $offS, $len), $font['face'], $font['charset']);
                    $dx = [];
                    if ($offD > 0 && $offD + $len * 4 <= strlen($rec)) for ($i = 0; $i < $len; $i++) $dx[] = self::i32($rec, $offD + $i * 4);
                    $size = max(1, abs($font['h']));
                    $xs = []; $cx = $x;
                    foreach ($chars as $i => $c) { $xs[] = $cx; $cx += $dx[$i] ?? $size * 0.5; }
                    $total = $cx - $x;
                    if ($align & 1) $pos[0] += $total;
                    $shift = (($align & 6) === 6) ? -$total / 2 : (($align & 2) ? -$total : 0);
                    $xs = array_map(function ($v) use ($shift) { return $v + $shift; }, $xs);
                    $va = $align & 24;
                    $base = $va === 0 ? $y + $size * 0.85 : ($va === 8 ? $y - $size * 0.2 : $y);
                    $emit(self::textEl($chars, $xs, $base, $font, $tcol));
                    break;
            }
        }
        return self::finish($els, $vb, $wpx, $hpx);
    }
}
