<?php

/** Decide qué imágenes del documento se usan y las deja listas para Moodle (PNG/JPG/GIF/SVG en base64) */
final class ImageStore
{
    const MARK = '/⟦img:(\d+)⟧/u';
    const WEB  = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];

    /** @return array [imágenes utilizables, cantidad de imágenes que no se pudieron convertir] */
    public static function prepare(array $questions, array $raw): array
    {
        if (!$raw || !preg_match_all(self::MARK, json_encode($questions, JSON_UNESCAPED_UNICODE), $m)) return [[], 0];
        $used = array_unique(array_map('intval', $m[1]));
        $images = []; $vector = [];
        foreach ($used as $id) {
            if (!isset($raw[$id])) continue;
            $ext = $raw[$id]['ext'];
            if (isset(self::WEB[$ext])) $images[$id] = self::entry($id, $raw[$id]['data'], $ext, 1.0);
            else $vector[$id] = $raw[$id];
        }
        foreach ($vector as $id => $v) {                       // 1.º) PHP puro: WMF/EMF → SVG (sin programas externos)
            $svg = VectorImage::toSvg($v['data'], $v['ext']);
            if ($svg) {
                $k = ($svg['w'] > 600) ? 600 / $svg['w'] : 1;
                $images[$id] = ['name' => 'img' . $id . '.svg', 'mime' => 'image/svg+xml', 'b64' => base64_encode($svg['svg']),
                                'w' => max(1, (int)round($svg['w'] * $k)), 'h' => max(1, (int)round($svg['h'] * $k))];
                unset($vector[$id]);
            }
        }
        foreach (ImageConverter::vectorToPng($vector) as $id => $png) $images[$id] = self::entry($id, $png, 'png', ImageConverter::SCALE);   // 2.º) LibreOffice/ImageMagick/Inkscape
        return [$images, count($used) - count($images)];
    }

    private static function entry(int $id, string $data, string $ext, float $scale): array
    {
        $e = ['name' => 'img' . $id . '.' . ($ext === 'jpeg' ? 'jpg' : $ext), 'mime' => self::WEB[$ext], 'b64' => base64_encode($data)];
        $size = ($ext === 'svg') ? false : @getimagesizefromstring($data);
        if ($size) {
            $w = $size[0] * $scale; $h = $size[1] * $scale;
            if ($w > 600) { $h = $h * 600 / $w; $w = 600; }
            $e['w'] = max(1, (int)round($w)); $e['h'] = max(1, (int)round($h));
        }
        return $e;
    }
}
