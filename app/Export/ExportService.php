<?php

/** Genera el archivo Moodle XML con todas las unidades (una categoría de Moodle por unidad). */
final class ExportService
{
    /** @return array lista con un archivo ['name','content','mime','count'] (vacía si no hay preguntas) */
    public static function build(array $batches, string $baseName): array
    {
        $chunks = []; $count = 0;
        foreach ($batches as $bi => $b) {
            if (!$b['questions']) continue;
            $count += count($b['questions']);
            $category = ExportUtil::categoryName($b['label'] ?? $b['filename']);
            $chunks[] = MoodleXmlExporter::render($b['questions'], $category, true, $b['images'] ?? [], 'u' . ($bi + 1) . '_');
        }
        if ($count === 0) return [];
        return [[
            'name' => Text::safeFilename($baseName) . '_moodle.xml', 'content' => MoodleXmlExporter::wrap(implode('', $chunks)),
            'mime' => 'application/xml; charset=utf-8', 'count' => $count,
        ]];
    }

    /** Preguntas que no se pueden exportar todavía (sin enunciado o con menos de 2 opciones) */
    public static function incomplete(array $batches): array
    {
        $issues = [];
        foreach ($batches as $b) {
            foreach ($b['questions'] as $qi => $q) {
                $where = '«' . Text::h($b['label'] ?? $b['filename']) . '» · pregunta ' . ($qi + 1);
                if (trim($q['text']) === '') { $issues[] = $where . ': falta el enunciado.'; continue; }
                $filled = count(array_filter($q['options'] ?? [], function ($o) { return trim($o) !== ''; }));
                if ($q['type'] === Config::TYPE_MCQ && $filled < 2) $issues[] = $where . ': necesita al menos 2 opciones.';
            }
        }
        return $issues;
    }
}
