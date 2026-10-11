<?php



final class MoodleXmlExporter
{
    /** Devuelve los nodos <question> de una unidad (sin el envoltorio <quiz>) */
    public static function render(array $questions, string $category, bool $withCat, array $images, string $prefix): string
    {
        $x = $withCat
            ? "<question type=\"category\">\n  <category><text>" . self::esc('$course$/top/' . $category) . "</text></category>\n</question>\n"
            : '';
        $n = 0;
        foreach ($questions as $q) $x .= self::question($q, ExportUtil::questionName($q['text'], ++$n), $images, $prefix);
        return $x;
    }

    public static function wrap(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n<quiz>\n" . $body . "</quiz>\n";
    }

    private static function esc(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
    private static function cdata(string $s): string { return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $s) . ']]>'; }

    /** Puntaje de la pregunta (defaultgrade) */
    private static function grade(array $q): string
    {
        $g = (isset($q['grade']) && $q['grade'] !== '') ? (float)$q['grade'] : 1.0;
        return number_format(max(0.0, $g), 7, '.', '');
    }

    /** Escapa el texto y convierte ⟦img:N⟧ en <img src="@@PLUGINFILE@@/…">; $files recibe los adjuntos usados */
    private static function html(string $text, array $images, string $prefix, array &$files, bool $paragraph = true): string
    {
        $html = '';
        foreach (preg_split(ImageStore::MARK, $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $piece) {
            if ($i % 2 === 0) { $html .= htmlspecialchars($piece, ENT_QUOTES, 'UTF-8'); continue; }
            $img = $images[(int)$piece] ?? null;
            if (!$img) { $html .= '[imagen]'; continue; }
            $name = $prefix . $img['name'];
            $files[$name] = $img['b64'];
            $dim = !empty($img['w']) ? ' width="' . $img['w'] . '" height="' . $img['h'] . '"' : '';
            $html .= '<img src="@@PLUGINFILE@@/' . $name . '" alt="imagen"' . $dim . ' />';
        }
        $html = trim($html);
        return $paragraph ? '<p>' . $html . '</p>' : $html;
    }

    /** Enunciado en HTML ordenado: texto de lectura aparte, un párrafo por línea y la pregunta final en negrita */
    private static function stemHtml(array $q, array $images, string $prefix, array &$files): string
    {
        $html = '';
        $ctx = trim($q['ctx'] ?? '');
        if ($ctx !== '') $html .= '<blockquote><p><strong>Texto</strong></p>' . self::paragraphs($ctx, $images, $prefix, $files, false) . '</blockquote>';
        $text = trim($q['text']);
        if (!empty($q['oneline'])) $text = preg_replace('/\s*\n\s*/u', ' ', $text);      // preguntas de ordenar: un solo párrafo
        return $html . self::paragraphs($text, $images, $prefix, $files, true);
    }

    private static function paragraphs(string $text, array $images, string $prefix, array &$files, bool $boldLast): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), function ($l) { return $l !== ''; }));
        $out = '';
        foreach ($lines as $i => $line) {
            $h = self::html($line, $images, $prefix, $files, false);
            if ($boldLast && count($lines) > 1 && $i === count($lines) - 1 && preg_match('/\?\s*$/u', $line)) $h = '<strong>' . $h . '</strong>';
            $out .= '<p>' . $h . '</p>';
        }
        return $out;
    }

    private static function fileTags(array $files): string
    {
        $x = '';
        foreach ($files as $name => $b64) $x .= '<file name="' . self::esc($name) . '" path="/" encoding="base64">' . $b64 . '</file>';
        return $x;
    }

    private static function question(array $q, string $name, array $images, string $prefix): string
    {
        $files = [];
        $text  = self::stemHtml($q, $images, $prefix, $files);
        $head  = '  <name><text>' . self::esc($name) . "</text></name>\n"
               . '  <questiontext format="html"><text>' . self::cdata($text) . '</text>' . self::fileTags($files) . "</questiontext>\n"
               . "  <generalfeedback format=\"html\"><text></text></generalfeedback>\n";
        return ($q['type'] === Config::TYPE_ESSAY)
            ? self::essay($q, $head, $images, $prefix)
            : self::multichoice($q, $head, $images, $prefix);
    }

    private static function essay(array $q, string $head, array $images, string $prefix): string
    {
        $files = []; $info = '';
        if (trim($q['grader'] ?? '') !== '') $info = self::cdata(self::html($q['grader'], $images, $prefix, $files));
        return "<question type=\"essay\">\n" . $head
            . '  <defaultgrade>' . self::grade($q) . "</defaultgrade>\n  <penalty>0.0000000</penalty>\n  <hidden>0</hidden>\n"
            . "  <responseformat>editor</responseformat>\n  <responserequired>1</responserequired>\n"
            . "  <responsefieldlines>15</responsefieldlines>\n  <attachments>0</attachments>\n  <attachmentsrequired>0</attachmentsrequired>\n"
            . '  <graderinfo format="html"><text>' . $info . '</text>' . self::fileTags($files) . "</graderinfo>\n"
            . "  <responsetemplate format=\"html\"><text></text></responsetemplate>\n</question>\n";
    }

    private static function multichoice(array $q, string $head, array $images, string $prefix): string
    {
        $out = "<question type=\"multichoice\">\n" . $head
            . '  <defaultgrade>' . self::grade($q) . "</defaultgrade>\n  <penalty>0.3333333</penalty>\n  <hidden>0</hidden>\n"
            . "  <single>true</single>\n  <shuffleanswers>true</shuffleanswers>\n  <answernumbering>abc</answernumbering>\n"
            . "  <showstandardinstruction>0</showstandardinstruction>\n"
            . "  <correctfeedback format=\"html\"><text></text></correctfeedback>\n"
            . "  <partiallycorrectfeedback format=\"html\"><text></text></partiallycorrectfeedback>\n"
            . "  <incorrectfeedback format=\"html\"><text></text></incorrectfeedback>\n  <shownumcorrect/>\n";
        foreach ($q['options'] as $i => $o) {
            $files = [];
            $html  = self::html($o, $images, $prefix, $files, false);
            $out .= '  <answer fraction="' . ($i === $q['correct'] ? '100' : '0') . '" format="html">'
                  . '<text>' . self::cdata($html) . '</text>' . self::fileTags($files)
                  . '<feedback format="html"><text></text></feedback></answer>' . "\n";
        }
        return $out . "</question>\n";
    }
}
