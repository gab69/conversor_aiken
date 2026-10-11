<?php

/** Funciones de apoyo para las plantillas (formato de datos, miniaturas, estadísticas). */
final class ViewHelper
{
    public static function h($s): string { return Text::h($s); }

    /** Texto con marcadores ⟦img:N⟧ → HTML con las imágenes en miniatura */
    public static function rich(string $text, array $images): string
    {
        $out = '';
        foreach (preg_split(ImageStore::MARK, $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $piece) {
            if ($i % 2 === 0) { $out .= Text::h($piece); continue; }
            $img = $images[(int)$piece] ?? null;
            $out .= $img ? '<img class="th" alt="imagen" src="data:' . $img['mime'] . ';base64,' . $img['b64'] . '">'
                         : '<span class="badge b-warn">imagen sin convertir</span>';
        }
        return $out;
    }

    /** 1.0 → "1", 2.50 → "2.5" */
    public static function grade($g): string
    {
        return rtrim(rtrim(number_format((float)($g ?? 1), 2, '.', ''), '0'), '.');
    }

    public static function describeInfo(array $info): string
    {
        $names = ['hl' => 'resaltado', 'col' => 'color', 'bold' => 'negrita', 'ul' => 'subrayado'];
        $parts = [!empty($info['numbered']) ? 'preguntas numeradas' : 'preguntas sin numeración'];
        if (!empty($info['essays'])) $parts[] = $info['essays'] . ' de ensayo';
        if (!empty($info['mcq']))    $parts[] = $info['mcq'] . ' de opción múltiple';
        $sig = array_map(function ($s) use ($names) { return $names[$s] ?? $s; }, $info['signals'] ?? []);
        if ($sig) $parts[] = 'marca de correcta: ' . implode(' / ', $sig);
        if (!empty($info['key']))       $parts[] = 'clave final: ' . $info['key'] . ' respuestas';
        if (!empty($info['discarded'])) $parts[] = $info['discarded'] . ' bloque(s) descartado(s) por tener menos de 2 opciones';
        return implode(' · ', $parts);
    }

    /** Totales para las tarjetas de resumen */
    public static function stats(array $batches): array
    {
        $total = 0; $essay = 0; $unsure = 0;
        foreach ($batches as $b) {
            foreach ($b['questions'] as $q) {
                $total++;
                if ($q['type'] === Config::TYPE_ESSAY) $essay++;
                elseif (empty($q['confident'])) $unsure++;
            }
        }
        return ['total' => $total, 'essay' => $essay, 'mcq' => $total - $essay, 'unsure' => $unsure];
    }
}
