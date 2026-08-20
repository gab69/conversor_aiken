<?php
/**
 * Conversor de banco de preguntas (Word/PDF) a formato AIKEN para Moodle
 * ------------------------------------------------------------------
 * Archivo único: sube tu banco de preguntas (.docx o .pdf) y descarga un
 * .txt en formato AIKEN, listo para importar en Moodle
 * (Banco de preguntas > Importar > formato Aiken).
 *
 * No modifica el contenido de enunciados ni alternativas: solo cambia
 * el FORMATO al que exige AIKEN (una línea por enunciado/alternativa,
 * letras A-F en mayúscula, línea ANSWER: con la letra correcta).
 *
 * Detección de la alternativa correcta:
 *   - En DOCX: se basa en NEGRITA y/o RESALTADO aplicados a la alternativa
 *     correcta (patrón típico de bancos de preguntas en Word).
 *   - En PDF: al perderse el formato durante la extracción de texto, se
 *     basa en marcadores explícitos (*alternativa, "(correcta)", ✓/√) o en
 *     un bloque "Clave de respuestas" al final del documento.
 *   - Si no puede determinarse, la pregunta se incluye igual (con "A" por
 *     defecto) y se lista en las advertencias para que la revises a mano.
 *
 * Requisitos: PHP 7.4+ con extensiones zip, dom, mbstring (vienen por
 * defecto). Para PDF: utilidad "pdftotext" (paquete poppler-utils).
 */

session_start();

const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 MB

/* =====================================================================
 * CLASE: MiniZipReader
 * Lector de archivos ZIP en PHP puro, usado como respaldo cuando el
 * servidor no tiene habilitada la extensión "zip" (clase ZipArchive).
 * Solo necesita la extensión "zlib" (función gzinflate), presente en
 * prácticamente cualquier instalación de PHP. Un .docx es un .zip, así
 * que esto basta para leer "word/document.xml" de dentro de él.
 * ===================================================================== */
class MiniZipReader
{
    /** @return string|false */
    public static function extract(string $zipPath, string $entryName)
    {
        $data = file_get_contents($zipPath);
        if ($data === false) {
            return false;
        }

        $eocdPos = strrpos($data, "\x50\x4b\x05\x06");
        if ($eocdPos === false) {
            return false;
        }

        $eocd = substr($data, $eocdPos, 22);
        if (strlen($eocd) < 22) {
            return false;
        }
        $fields = unpack('Vsig/vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset/vcommentLen', $eocd);
        $cdOffset = $fields['cdOffset'];
        $entries = $fields['entries'];

        $pos = $cdOffset;
        for ($i = 0; $i < $entries; $i++) {
            if (substr($data, $pos, 4) !== "\x50\x4b\x01\x02") {
                break;
            }
            $header = substr($data, $pos, 46);
            $cd = unpack(
                'Vsig/vverMade/vverNeed/vflag/vmethod/vmtime/vmdate/Vcrc/VcompSize/VuncompSize/' .
                'vnameLen/vextraLen/vcommentLen/vdiskStart/vintAttr/VextAttr/VlocalHeaderOffset',
                $header
            );

            $nameLen = $cd['nameLen'];
            $extraLen = $cd['extraLen'];
            $commentLen = $cd['commentLen'];
            $name = substr($data, $pos + 46, $nameLen);

            if ($name === $entryName) {
                return self::readLocalEntry($data, $cd['localHeaderOffset'], $cd['method'], $cd['compSize'], $cd['uncompSize']);
            }

            $pos += 46 + $nameLen + $extraLen + $commentLen;
        }

        return false;
    }

    /** @return string|false */
    private static function readLocalEntry(string $data, int $localOffset, int $method, int $compSize, int $uncompSize)
    {
        if (substr($data, $localOffset, 4) !== "\x50\x4b\x03\x04") {
            return false;
        }
        $localHeader = substr($data, $localOffset, 30);
        $lf = unpack('Vsig/vverNeed/vflag/vmethod/vmtime/vmdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen', $localHeader);

        $dataStart = $localOffset + 30 + $lf['nameLen'] + $lf['extraLen'];
        $useCompSize = $lf['compSize'] > 0 ? $lf['compSize'] : $compSize;
        $compressedData = substr($data, $dataStart, $useCompSize);

        if ($method === 0) {
            return $compressedData; // STORED, sin compresión
        }
        if ($method === 8) {
            $raw = @gzinflate($compressedData); // DEFLATE
            return $raw === false ? false : $raw;
        }

        return false; // método de compresión no soportado (raro en .docx)
    }
}

/* =====================================================================
 * CLASE: DocxExtractor
 * Extrae preguntas de un .docx detectando negrita/resaltado como marca
 * de la alternativa correcta.
 * ===================================================================== */
class DocxExtractor
{
    public array $warnings = [];

    public function extract(string $filePath): array
    {
        $xml = $this->readDocumentXml($filePath);

        if ($xml === false || $xml === null) {
            throw new RuntimeException('El .docx no contiene word/document.xml o no se pudo leer (¿está corrupto?).');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        libxml_use_internal_errors(true);
        $dom->loadXML($xml);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $questions = $this->extractFromTables($dom, $xpath);

        if (empty($questions)) {
            $plainText = $this->documentToPlainText($dom, $xpath);
            $questions = TextQuestionParser::parse($plainText, $this->warnings, false);
        }

        return $questions;
    }

    /**
     * Lee word/document.xml del .docx. Usa ZipArchive si la extensión "zip"
     * está disponible; si no, usa el lector ZIP puro en PHP (MiniZipReader).
     * @return string|false|null
     */
    private function readDocumentXml(string $filePath)
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($filePath) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml !== false) {
                    return $xml;
                }
                // Si ZipArchive no pudo leer la entrada, probamos el lector propio abajo.
            }
        }

        if (!function_exists('gzinflate')) {
            throw new RuntimeException(
                'Este servidor no tiene ni la extensión "zip" ni la extensión "zlib" habilitadas, ' .
                'y ambas son necesarias para leer archivos .docx. Pide a tu proveedor de hosting ' .
                'que habilite alguna de las dos (zlib suele venir activada por defecto).'
            );
        }

        return MiniZipReader::extract($filePath, 'word/document.xml');
    }

    private function extractFromTables(DOMDocument $dom, DOMXPath $xpath): array
    {
        $questions = [];
        $tables = $xpath->query('//w:tbl');

        foreach ($tables as $table) {
            $rows = $xpath->query('.//w:tr', $table);

            foreach ($rows as $row) {
                $cells = $xpath->query('./w:tc', $row);
                if ($cells->length < 2) {
                    continue;
                }

                $cellsArr = iterator_to_array($cells);

                foreach ($cellsArr as $ci => $cell) {
                    $optionParas = $this->cellOptionParagraphs($xpath, $cell);
                    if (count($optionParas) < 2) {
                        continue;
                    }

                    $statementCell = null;
                    for ($j = $ci - 1; $j >= 0; $j--) {
                        $text = trim($this->cellPlainText($xpath, $cellsArr[$j]));
                        if ($text !== '') {
                            $statementCell = $cellsArr[$j];
                            break;
                        }
                    }

                    if ($statementCell === null) {
                        continue;
                    }

                    $statement = $this->buildStatementText($xpath, $statementCell);

                    if (mb_strtolower($statement) === 'preguntas' || mb_strlen($statement) < 3) {
                        continue;
                    }

                    [$options, $correct] = $this->buildOptions($optionParas);

                    if (count($options) < 2) {
                        continue;
                    }

                    $questions[] = [
                        'statement' => $this->normalizeSpaces($statement),
                        'options'   => $options,
                        'correct'   => $correct,
                        'source'    => 'tabla',
                    ];

                    break;
                }
            }
        }

        return $questions;
    }

    private function cellOptionParagraphs(DOMXPath $xpath, DOMElement $cell): array
    {
        $paras = $xpath->query('./w:p', $cell);
        $matches = [];
        foreach ($paras as $p) {
            $text = trim($this->paragraphText($xpath, $p));
            if ($text === '') {
                continue;
            }
            if (preg_match('/^[a-dA-D][\.\)]\s*.+/u', $text)) {
                $matches[] = $p;
            }
        }
        return $matches;
    }

    private function buildOptions(array $optionParagraphs): array
    {
        $options = [];
        $highlightedLetters = [];
        $boldLetters = [];

        foreach ($optionParagraphs as $p) {
            $xpath = new DOMXPath($p->ownerDocument);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $text = trim($this->paragraphText($xpath, $p));
            if (!preg_match('/^([a-dA-D])[\.\)]\s*(.+)$/us', $text, $m)) {
                continue;
            }
            $letter = strtoupper($m[1]);
            $optText = trim($m[2]);
            $options[$letter] = $this->normalizeSpaces($optText);

            $runs = $xpath->query('.//w:r', $p);
            $hasHighlight = false;
            $hasBold = false;
            foreach ($runs as $run) {
                $rPr = $xpath->query('./w:rPr', $run)->item(0);
                if ($rPr === null) {
                    continue;
                }
                if ($xpath->query('./w:highlight', $rPr)->length > 0) {
                    $hl = $xpath->query('./w:highlight', $rPr)->item(0);
                    $val = $hl->getAttribute('w:val');
                    if ($val !== '' && $val !== 'none') {
                        $hasHighlight = true;
                    }
                }
                $boldNode = $xpath->query('./w:b', $rPr)->item(0);
                if ($boldNode !== null) {
                    $val = $boldNode->getAttribute('w:val');
                    if ($val === '' || !in_array($val, ['0', 'false'], true)) {
                        $hasBold = true;
                    }
                }
            }

            if ($hasHighlight) {
                $highlightedLetters[] = $letter;
            }
            if ($hasBold) {
                $boldLetters[] = $letter;
            }
        }

        $correct = null;
        if (count($highlightedLetters) === 1) {
            $correct = $highlightedLetters[0];
        } elseif (count($highlightedLetters) === 0 && count($boldLetters) === 1) {
            $correct = $boldLetters[0];
        } else {
            $correct = null;
        }

        return [$options, $correct];
    }

    private function cellPlainText(DOMXPath $xpath, DOMElement $cell): string
    {
        $paras = $xpath->query('./w:p', $cell);
        $lines = [];
        foreach ($paras as $p) {
            $t = trim($this->paragraphText($xpath, $p));
            if ($t !== '') {
                $lines[] = $t;
            }
        }
        return implode("\n", $lines);
    }

    private function cellLines(DOMXPath $xpath, DOMElement $cell): array
    {
        $paras = $xpath->query('./w:p', $cell);
        $lines = [];
        foreach ($paras as $p) {
            foreach ($this->paragraphTextLines($xpath, $p) as $l) {
                if ($l !== '') {
                    $lines[] = $l;
                }
            }
        }
        return $lines;
    }

    /**
     * Arma el texto del enunciado a partir de una celda de "Preguntas",
     * descartando la línea de etiqueta/categoría (p. ej. "Conceptual · LP",
     * "Análisis · LC1", "Aplicación · LC1–LC2") que suele venir en un
     * párrafo separado antes de la pregunta real. No se toca el contenido
     * de la pregunta en sí: solo se excluye esa línea de clasificación
     * interna, que no forma parte del enunciado.
     */
    private function buildStatementText(DOMXPath $xpath, DOMElement $cell): string
    {
        $lines = $this->cellLines($xpath, $cell);
        $lines = $this->stripTagLines($lines);
        return $this->normalizeSpaces(implode(' ', $lines));
    }

    /**
     * Elimina, del inicio del array de líneas, cualquier línea que tenga
     * forma de etiqueta de clasificación en vez de pregunta: texto corto,
     * sin signo de interrogación/punto final de oración, que contiene un
     * separador tipo "·" seguido de un código (LP, LC1, LC2, LC1–LC2...).
     * Solo actúa si hay más líneas después (para no vaciar el enunciado).
     */
    private function stripTagLines(array $lines): array
    {
        while (count($lines) > 1) {
            $first = trim($lines[0]);

            $looksLikeTag =
                mb_strlen($first) <= 60 &&
                (mb_strpos($first, '·') !== false || preg_match('/^\p{L}+\s*[-–]\s*L[PC]\d*/u', $first)) &&
                !preg_match('/[?¿.:!]/u', $first);

            if (!$looksLikeTag) {
                break;
            }

            array_shift($lines);
        }

        return $lines;
    }

    private function paragraphText(DOMXPath $xpath, DOMElement $p): string
    {
        // Incluye <w:t> (texto) y trata <w:br/>/<w:tab/> como espacio, para
        // no pegar palabras cuando el autor usó un salto de línea manual
        // (Shift+Enter) dentro del mismo párrafo en vez de un párrafo nuevo.
        $nodes = $xpath->query('.//w:t | .//w:br | .//w:tab', $p);
        $out = '';
        foreach ($nodes as $node) {
            if ($node->nodeName === 'w:t') {
                $out .= $node->textContent;
            } else {
                $out .= ' ';
            }
        }
        return $out;
    }

    /**
     * Igual que paragraphText, pero devuelve un array de "líneas" separando
     * por los saltos de línea manuales (<w:br/> o <w:cr/>) dentro del
     * párrafo. Se usa para poder detectar y descartar una línea de
     * etiqueta/categoría aunque esté en el MISMO párrafo que la pregunta
     * (autores que usan Shift+Enter en vez de Enter).
     * @return string[]
     */
    private function paragraphTextLines(DOMXPath $xpath, DOMElement $p): array
    {
        $nodes = $xpath->query('.//w:t | .//w:br | .//w:cr', $p);
        $lines = [];
        $current = '';
        foreach ($nodes as $node) {
            if ($node->nodeName === 'w:t') {
                $current .= $node->textContent;
            } else {
                $lines[] = trim($current);
                $current = '';
            }
        }
        $lines[] = trim($current);
        return $lines;
    }

    private function normalizeSpaces(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    private function documentToPlainText(DOMDocument $dom, DOMXPath $xpath): string
    {
        $paras = $xpath->query('//w:body/w:p');
        $lines = [];
        foreach ($paras as $p) {
            $lines[] = trim($this->paragraphText($xpath, $p));
        }
        return implode("\n", $lines);
    }
}

/* =====================================================================
 * CLASE: TextQuestionParser
 * Parser heurístico de preguntas a partir de texto plano (PDF y fallback
 * de DOCX sin tablas).
 * ===================================================================== */
class TextQuestionParser
{
    public static function parse(string $text, array &$warnings, bool $numberedQuestions = true): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $lines = array_map('rtrim', $lines);

        [$bodyLines, $answerKey] = self::splitAnswerKeyBlock($lines);
        $blocks = self::splitIntoQuestionBlocks($bodyLines, $numberedQuestions);

        $questions = [];
        $qNumber = 0;
        foreach ($blocks as $block) {
            $qNumber++;
            $parsed = self::parseBlock($block);
            if ($parsed === null) {
                continue;
            }
            [$statement, $options, $correct] = $parsed;

            if ($correct === null && isset($answerKey[$qNumber])) {
                $letter = strtoupper($answerKey[$qNumber]);
                if (isset($options[$letter])) {
                    $correct = $letter;
                }
            }

            if (count($options) < 2) {
                continue;
            }

            if ($correct === null) {
                $warnings[] = "Pregunta #{$qNumber}: no se detectó la alternativa correcta automáticamente. Revísala manualmente en el archivo generado (quedó marcada como 'A' por defecto).";
            }

            $questions[] = [
                'statement' => $statement,
                'options'   => $options,
                'correct'   => $correct,
                'source'    => 'texto',
            ];
        }

        return $questions;
    }

    private static function splitAnswerKeyBlock(array $lines): array
    {
        $keyStart = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(clave\s+de\s+respuestas|clave\s+de\s+resp\.?|respuestas\s+correctas|hoja\s+de\s+respuestas|answer\s+key)\s*:?\s*$/iu', $line)) {
                $keyStart = $i;
                break;
            }
        }

        if ($keyStart === null) {
            return [$lines, []];
        }

        $keyText = implode(' ', array_slice($lines, $keyStart + 1));
        $map = [];
        if (preg_match_all('/(\d{1,3})\s*[\.\)\-:]\s*([a-fA-F])\b/u', $keyText, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $map[(int)$pair[1]] = strtoupper($pair[2]);
            }
        }

        $remaining = array_slice($lines, 0, $keyStart);
        return [$remaining, $map];
    }

    private static function splitIntoQuestionBlocks(array $lines, bool $numberedQuestions): array
    {
        $blocks = [];
        $current = [];

        $isNewQuestionStart = function (string $line) use ($numberedQuestions): bool {
            if (trim($line) === '') {
                return false;
            }
            if ($numberedQuestions && preg_match('/^\s*\d{1,3}\s*[\.\)]\s*\S/u', $line)) {
                return true;
            }
            return false;
        };

        foreach ($lines as $line) {
            if ($isNewQuestionStart($line)) {
                if (!empty($current)) {
                    $blocks[] = $current;
                }
                $current = [$line];
            } else {
                $current[] = $line;
            }
        }
        if (!empty($current)) {
            $blocks[] = $current;
        }

        if (!$numberedQuestions) {
            $blocks = self::splitByBlankLinesWithOptions($lines);
        }

        return $blocks;
    }

    private static function splitByBlankLinesWithOptions(array $lines): array
    {
        $blocks = [];
        $current = [];
        foreach ($lines as $line) {
            if (trim($line) === '' && !empty($current)) {
                $blocks[] = $current;
                $current = [];
                continue;
            }
            if (trim($line) !== '') {
                $current[] = $line;
            }
        }
        if (!empty($current)) {
            $blocks[] = $current;
        }
        return $blocks;
    }

    private static function parseBlock(array $block): ?array
    {
        $statementLines = [];
        $options = [];
        $correct = null;
        $inOptions = false;

        foreach ($block as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (!$inOptions) {
                $trimmed = preg_replace('/^\s*\d{1,3}\s*[\.\)]\s*/u', '', $trimmed);
            }

            if (preg_match('/^(\*?)([a-fA-F])[\.\)]\s*(.+?)(\s*\(correcta\)|\s*\(CORRECTA\)|\s*[✓√]\s*)?$/u', $trimmed, $m)) {
                $inOptions = true;
                $hasAsterisk = $m[1] === '*';
                $letter = strtoupper($m[2]);
                $optText = trim($m[3]);
                $hasMarker = isset($m[4]) && trim($m[4]) !== '';

                $options[$letter] = $optText;
                if ($hasAsterisk || $hasMarker) {
                    $correct = $letter;
                }
                continue;
            }

            if (!$inOptions) {
                $statementLines[] = $trimmed;
            }
        }

        $statement = trim(preg_replace('/\s+/u', ' ', implode(' ', $statementLines)));

        if ($statement === '' || count($options) < 2) {
            return null;
        }

        return [$statement, $options, $correct];
    }
}

/* =====================================================================
 * CLASE: PdfExtractor
 * Extrae texto de un PDF vía pdftotext (poppler-utils).
 * ===================================================================== */
class PdfExtractor
{
    public array $warnings = [];

    public function extractPlainText(string $filePath): string
    {
        if (!self::pdftotextAvailable()) {
            throw new RuntimeException(
                'Este servidor no tiene instalada la utilidad "pdftotext" (paquete poppler-utils), ' .
                'necesaria para leer archivos PDF. Instálala con: apt-get install poppler-utils ' .
                '— o bien exporta tu documento a Word (.docx), que sí es totalmente compatible.'
            );
        }

        $out = $filePath . '.txt';
        $cmd = 'pdftotext -layout ' . escapeshellarg($filePath) . ' ' . escapeshellarg($out) . ' 2>&1';
        exec($cmd, $outputLines, $returnCode);

        if ($returnCode !== 0 || !file_exists($out)) {
            throw new RuntimeException('No se pudo extraer texto del PDF: ' . implode(' ', $outputLines));
        }

        $text = file_get_contents($out);
        @unlink($out);

        return $text === false ? '' : $text;
    }

    public function extract(string $filePath): array
    {
        $text = $this->extractPlainText($filePath);
        return TextQuestionParser::parse($text, $this->warnings, true);
    }

    public static function pdftotextAvailable(): bool
    {
        $path = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        return $path !== '';
    }
}

/* =====================================================================
 * CLASE: AikenConverter
 * Convierte preguntas estructuradas al formato AIKEN de Moodle.
 * ===================================================================== */
class AikenConverter
{
    public static function toAiken(array $questions): string
    {
        $out = [];

        foreach ($questions as $q) {
            $statement = self::sanitizeLine($q['statement']);
            if ($statement === '') {
                continue;
            }

            $letters = array_keys($q['options']);
            sort($letters);

            if (count($letters) < 2) {
                continue;
            }

            $out[] = $statement;
            foreach ($letters as $letter) {
                $optText = self::sanitizeLine($q['options'][$letter]);
                $out[] = $letter . '. ' . $optText;
            }

            $correct = $q['correct'];
            if ($correct === null || !in_array($correct, $letters, true)) {
                $correct = $letters[0];
            }

            $out[] = 'ANSWER: ' . $correct;
            $out[] = '';
        }

        return implode("\n", $out);
    }

    private static function sanitizeLine(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}

/* =====================================================================
 * LÓGICA DE LA PÁGINA (subida, procesamiento, descarga)
 * ===================================================================== */
$error = null;
$result = null;

if (isset($_GET['download']) && isset($_SESSION['aiken_file']) && isset($_SESSION['aiken_name'])) {
    $content = $_SESSION['aiken_file'];
    $name = $_SESSION['aiken_name'];
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['banco'])) {
    try {
        $file = $_FILES['banco'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Error al subir el archivo (código ' . $file['error'] . ').');
        }
        if ($file['size'] > MAX_FILE_SIZE) {
            throw new RuntimeException('El archivo supera el tamaño máximo permitido (20 MB).');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $tmpPath = $file['tmp_name'];
        $warnings = [];

        if ($ext === 'docx') {
            $extractor = new DocxExtractor();
            $questions = $extractor->extract($tmpPath);
            $warnings = $extractor->warnings;
        } elseif ($ext === 'pdf') {
            $extractor = new PdfExtractor();
            $questions = $extractor->extract($tmpPath);
            $warnings = $extractor->warnings;
        } elseif ($ext === 'doc') {
            throw new RuntimeException('El formato .doc antiguo no es compatible. Guarda el archivo como .docx desde Word y vuelve a intentarlo.');
        } else {
            throw new RuntimeException('Formato no compatible. Sube un archivo .docx o .pdf.');
        }

        if (empty($questions)) {
            throw new RuntimeException(
                'No se detectó ninguna pregunta con alternativas en el documento. ' .
                'Verifica que las alternativas empiecen con "a.", "b.", "c." ... y que el enunciado esté antes de ellas.'
            );
        }

        foreach ($questions as $i => $q) {
            if ($q['correct'] === null) {
                $num = $i + 1;
                $preview = mb_substr($q['statement'], 0, 80);
                $warnings[] = "Pregunta #{$num}: no se pudo determinar la alternativa correcta automáticamente (\"{$preview}...\"). Se marcó \"A\" por defecto: revísala en el archivo generado.";
            }
        }

        $aikenText = AikenConverter::toAiken($questions);
        $baseName = pathinfo($file['name'], PATHINFO_FILENAME);
        $downloadName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $baseName) . '_AIKEN.txt';

        $_SESSION['aiken_file'] = $aikenText;
        $_SESSION['aiken_name'] = $downloadName;

        $result = [
            'count'    => count($questions),
            'warnings' => $warnings,
            'filename' => $downloadName,
            'preview'  => mb_substr($aikenText, 0, 1500),
        ];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Conversor Word/PDF → AIKEN (Moodle)</title>
<style>
  :root{
    --bg:#0f172a; --card:#ffffff; --accent:#2563eb; --accent-dark:#1d4ed8;
    --text:#1e293b; --muted:#64748b; --ok:#16a34a; --warn:#b45309; --err:#dc2626;
    --border:#e2e8f0;
  }
  *{box-sizing:border-box;}
  body{
    margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    background:linear-gradient(180deg,#0f172a 0%, #1e293b 260px, #f1f5f9 260px);
    color:var(--text); min-height:100vh;
  }
  .wrap{max-width:760px;margin:0 auto;padding:32px 20px 80px;}
  h1{color:#fff;font-size:1.6rem;margin:0 0 6px;}
  .subtitle{color:#cbd5e1;font-size:.95rem;margin:0 0 28px;}
  .card{background:var(--card);border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.12);padding:28px;margin-bottom:20px;}
  label.dropzone{
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    border:2px dashed #cbd5e1;border-radius:12px;padding:36px 20px;cursor:pointer;
    text-align:center;transition:.15s;
  }
  label.dropzone:hover{border-color:var(--accent);background:#f8fafc;}
  label.dropzone strong{font-size:1rem;color:var(--text);}
  label.dropzone span{color:var(--muted);font-size:.85rem;margin-top:4px;}
  input[type=file]{display:none;}
  #filename{margin-top:12px;font-size:.9rem;color:var(--accent-dark);font-weight:600;min-height:1.2em;}
  button{
    margin-top:18px;width:100%;padding:13px 18px;border:none;border-radius:9px;
    background:var(--accent);color:#fff;font-size:1rem;font-weight:600;cursor:pointer;transition:.15s;
  }
  button:hover{background:var(--accent-dark);}
  .msg{padding:14px 16px;border-radius:9px;margin-bottom:18px;font-size:.92rem;line-height:1.5;}
  .msg.error{background:#fef2f2;color:var(--err);border:1px solid #fecaca;}
  .msg.ok{background:#f0fdf4;color:var(--ok);border:1px solid #bbf7d0;}
  .msg.warn{background:#fffbeb;color:var(--warn);border:1px solid #fde68a;}
  .warnings{margin:0;padding-left:18px;}
  .warnings li{margin-bottom:6px;}
  .download-btn{
    display:inline-block;background:var(--ok);color:#fff;text-decoration:none;
    padding:12px 20px;border-radius:9px;font-weight:600;margin-top:6px;
  }
  .download-btn:hover{background:#15803d;}
  pre.preview{
    background:#0f172a;color:#e2e8f0;padding:16px;border-radius:9px;
    font-size:.78rem;overflow-x:auto;max-height:320px;line-height:1.5;
  }
  .steps{color:var(--muted);font-size:.85rem;line-height:1.6;}
  .steps b{color:var(--text);}
  footer{color:#94a3b8;font-size:.78rem;text-align:center;margin-top:40px;}
</style>
</head>
<body>
<div class="wrap">
  <h1>Conversor de banco de preguntas a formato AIKEN</h1>
  <p class="subtitle">Sube tu banco de preguntas en Word (.docx) o PDF y descarga el .txt listo para importar en Moodle.</p>

  <?php if ($error): ?>
    <div class="msg error"><strong>⚠ No se pudo procesar el archivo.</strong><br><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($result): ?>
    <div class="card">
      <div class="msg ok">✅ Se detectaron <strong><?= $result['count'] ?></strong> preguntas y se generó el archivo AIKEN.</div>

      <?php if (!empty($result['warnings'])): ?>
        <div class="msg warn">
          <strong>⚠ <?= count($result['warnings']) ?> pregunta(s) necesitan revisión manual:</strong>
          <ul class="warnings">
            <?php foreach ($result['warnings'] as $w): ?>
              <li><?= htmlspecialchars($w) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <a class="download-btn" href="?download=1">⬇ Descargar <?= htmlspecialchars($result['filename']) ?></a>

      <p style="margin-top:22px;font-weight:600;">Vista previa:</p>
      <pre class="preview"><?= htmlspecialchars($result['preview']) ?><?= mb_strlen($result['preview']) >= 1500 ? "\n..." : '' ?></pre>
    </div>
  <?php endif; ?>

  <div class="card">
    <form action="" method="post" enctype="multipart/form-data" id="uploadForm">
      <label class="dropzone" for="banco">
        <strong>📄 Haz clic para elegir tu archivo</strong>
        <span>Word (.docx) o PDF — máx. 20 MB</span>
      </label>
      <input type="file" name="banco" id="banco" accept=".docx,.pdf" required>
      <div id="filename"></div>
      <button type="submit">Convertir a formato AIKEN</button>
    </form>
  </div>

  <div class="card steps">
    <p><b>Cómo debe estar tu documento para mejores resultados:</b></p>
    <p>
      <b>Word (.docx):</b> cada alternativa (a. b. c. d.) en su propio párrafo, y la
      alternativa correcta en <b>negrita y/o resaltada</b> (el estilo típico de un
      banco de preguntas con clave marcada). Funciona tanto si las preguntas están
      en tablas como en texto corrido.
    </p>
    <p>
      <b>PDF:</b> como el PDF no conserva el resaltado al extraer texto, marca la
      alternativa correcta con un asterisco al inicio (<code>*b. Texto...</code>),
      con <code>(correcta)</code> al final, o incluye al final del documento un
      bloque "<b>Clave de respuestas</b>: 1-b, 2-c, 3-a...".
    </p>
    <p>Las preguntas que no puedan detectarse automáticamente se listarán como advertencia para que las corrijas a mano en el .txt antes de subirlo a Moodle.</p>
  </div>

  <footer>Formato AIKEN estándar de Moodle · el contenido de enunciados y alternativas no se modifica, solo se reformatea.</footer>
</div>
<script>
  document.getElementById('banco').addEventListener('change', function(e){
    const f = e.target.files[0];
    document.getElementById('filename').textContent = f ? ('Seleccionado: ' + f.name) : '';
  });
</script>
</body>
</html>