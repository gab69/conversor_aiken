<?php

/**
 * Aplica a los datos guardados las correcciones hechas en la pantalla de revisión.
 * Las ediciones llegan como una lista JSON (sólo las preguntas modificadas) y se identifican
 * por el id de cada pregunta, así que agregar o eliminar preguntas no desordena nada.
 *
 * Cada edición: id, text, ctx, grade, oneline, type, grader, opts[], ans, ansSet
 */
final class ReviewEditor
{
    public static function apply(array $batches, array $edits): array
    {
        $byId = [];
        foreach ($edits as $e) {
            if (!empty($e['id'])) $byId[(string)$e['id']] = $e;
        }
        foreach ($batches as &$batch) {
            foreach ($batch['questions'] as &$q) {
                if (isset($byId[$q['id'] ?? ''])) self::applyToQuestion($q, $byId[$q['id']]);
            }
            unset($q);
        }
        unset($batch);
        return $batches;
    }

    /** Cambia el nombre de las unidades (es el nombre de la categoría que Moodle creará al importar) */
    public static function applyUnits(array $batches, array $units): array
    {
        foreach ($units as $u) {
            $bi = (int)($u['bi'] ?? -1);
            if (!isset($batches[$bi])) continue;
            $label = Text::normalize($u['label'] ?? '');
            $batches[$bi]['label'] = $label !== '' ? $label : $batches[$bi]['filename'];     // vacío → nombre del archivo
        }
        return $batches;
    }

    /** Agrega una pregunta en blanco al final de una unidad. @return array [datos, pregunta nueva o null] */
    public static function addQuestion(array $batches, int $bi, string $type): array
    {
        if (!isset($batches[$bi])) return [$batches, null];
        $q = ['id' => Uid::make(), 'type' => $type, 'text' => '', 'options' => [], 'correct' => 0, 'confident' => false,
              'num' => null, 'source' => 'none', 'ctx' => '', 'grade' => 1.0, 'oneline' => false];
        if ($type === Config::TYPE_ESSAY) { $q['grader'] = ''; $q['confident'] = true; $q['source'] = 'essay'; }
        else $q['options'] = ['', '', '', ''];
        $batches[$bi]['questions'][] = $q;
        return [$batches, $q];
    }

    public static function deleteQuestion(array $batches, string $id): array
    {
        foreach ($batches as &$batch) {
            $batch['questions'] = array_values(array_filter($batch['questions'], function ($q) use ($id) {
                return ($q['id'] ?? '') !== $id;
            }));
        }
        unset($batch);
        return $batches;
    }

    private static function applyToQuestion(array &$q, array $e): void
    {
        $isEssay = ($q['type'] === Config::TYPE_ESSAY);

        if (!$isEssay && isset($e['opts']) && is_array($e['opts'])) self::applyOptions($q, $e['opts'], $e['ans'] ?? null, !empty($e['ansSet']));
        if (array_key_exists('oneline', $e)) $q['oneline'] = (bool)$e['oneline'];
        if (isset($e['ctx'])) $q['ctx'] = Text::normalizeLines($e['ctx']);

        $grade = self::number($e['grade'] ?? '');
        if ($grade !== null) $q['grade'] = $grade;

        if (isset($e['text'])) $q['text'] = Text::normalizeLines($e['text']);          // puede quedar vacío en una pregunta nueva
        if ($isEssay && isset($e['grader'])) $q['grader'] = Text::normalize($e['grader']);
        if (!$isEssay && ($e['type'] ?? '') === Config::TYPE_ESSAY) self::convertToEssay($q);
    }

    /**
     * Aplica opciones editadas (texto, altas y bajas) y la respuesta marcada (índice de la fila).
     * La respuesta sólo pasa a "confirmada" cuando la persona la eligió ($chosen).
     */
    private static function applyOptions(array &$q, array $texts, $ans, bool $chosen): void
    {
        $sel = (is_numeric($ans) && $ans !== '') ? (int)$ans : null;
        $new = []; $correct = null;
        foreach (array_values($texts) as $i => $t) {
            $t = Text::normalize($t);
            if ($t === '') continue;                          // fila vacía = opción eliminada
            if ($sel === $i) $correct = count($new);
            $new[] = $t;
        }
        if (count($new) < 2) return;                          // mínimo 2 opciones
        $q['options'] = array_slice($new, 0, count(Config::LETTERS));

        if ($sel === null) return;
        if ($correct !== null && $correct < count($q['options'])) {
            if ($chosen) { $q['confident'] = true; $q['source'] = 'manual'; }
            $q['correct'] = $correct;
        } else {                                              // se eliminó la opción que estaba marcada
            $q['correct'] = 0; $q['confident'] = false; $q['source'] = 'none';
        }
    }

    /** Una "opción múltiple" mal detectada pasa a ensayo: sus opciones se integran al enunciado */
    private static function convertToEssay(array &$q): void
    {
        $extra = [];
        foreach ($q['options'] as $oi => $o) if ($o !== '') $extra[] = Config::LETTERS[$oi] . ') ' . $o;
        $q['text'] = Text::normalize($q['text'] . ' ' . implode(' ', $extra));
        $q['type'] = Config::TYPE_ESSAY; $q['options'] = []; $q['correct'] = 0;
        $q['confident'] = true; $q['source'] = 'manual'; $q['grader'] = ''; $q['oneline'] = false;
    }

    /** "3,5" → 3.5 · vacío o no numérico → null */
    private static function number($v): ?float
    {
        $v = str_replace(',', '.', trim((string)$v));
        return ($v !== '' && is_numeric($v)) ? max(0.0, (float)$v) : null;
    }
}
