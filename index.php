<?php
/**
 * Conversor de Word (.docx) a formato AIKEN
 * ------------------------------------------
 * Aplicativo web de un solo archivo (index.php), sin dependencias externas
 * (no requiere Composer, ni mbstring, ni librerías de terceros).
 *
 * Flujo:
 *   1) El usuario sube uno o varios archivos .docx (bancos de preguntas / controles de lectura).
 *   2) El sistema analiza cada documento, detecta preguntas, opciones y la respuesta
 *      correcta (usando resaltado, negrita o símbolo ✓, según cómo la haya marcado el docente).
 *   3) Se muestra una pantalla de revisión donde el usuario puede corregir manualmente
 *      cualquier respuesta mal detectada.
 *   4) Se descarga el resultado: por unidad (un .txt por archivo, o un .zip con todos)
 *      o en conjunto (un solo .txt combinado).
 *
 * Requisitos del servidor: PHP 7.4+ con extensiones "zip" y "xml" (ambas muy comunes).
 */

session_start();
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// ============================================================================
//  CONFIGURACIÓN
// ============================================================================
define('APP_TITLE', 'Conversor Word → AIKEN');
define('MAX_FILES', 30);
$OPTION_LETTERS = ['A','B','C','D','E','F','G','H'];

// ============================================================================
//  UTILIDADES DE TEXTO (sin depender de mbstring)
// ============================================================================

function normalize_ws($text) {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n+/', ' ', $text);
    $text = preg_replace('/ +/', ' ', $text);
    return trim($text);
}

function safe_filename($name) {
    $name = preg_replace('/\.[Dd][Oo][Cc][Xx]$/', '', $name);
    $name = preg_replace('/[^A-Za-z0-9_\-\.]+/', '_', $name);
    $name = trim($name, '_');
    if ($name === '') $name = 'documento';
    return $name;
}

// ============================================================================
//  PARSER DE DOCX -> ESTRUCTURA DE PREGUNTAS
// ============================================================================

class DocxAikenParser {

    /** @var DOMXPath */
    private $xpath;
    /** @var array<string,bool> IDs de estilos de carácter que implican negrita */
    private $boldStyleIds = [];
    const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    const NS_MATH = 'http://schemas.openxmlformats.org/officeDocument/2006/math';
    const CHECK_CHARS = ['✓', '✔', '☑', '✅', '☒'];

    /**
     * Lee word/styles.xml y devuelve el conjunto de styleId cuyo rPr define negrita,
     * o cuyo nombre sugiere claramente negrita/énfasis (p.ej. "Textoennegrita", "Strong").
     * Esto es necesario porque muchos documentos aplican la negrita mediante un estilo de
     * carácter (<w:rStyle w:val="Textoennegrita"/>) en vez de un <w:b/> directo en el run.
     */
    private function extractBoldStyleIds($stylesXml) {
        $ids = [];
        if (!$stylesXml) return $ids;
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($stylesXml, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);
        foreach ($xp->query('//w:style[@w:type="character"]') as $style) {
            $styleId = $style->getAttribute('w:styleId');
            if ($styleId === '') continue;
            $isBold = $xp->query('./w:rPr/w:b', $style)->length > 0;
            $nameNode = $xp->query('./w:name', $style)->item(0);
            $name = $nameNode ? strtolower($nameNode->getAttribute('w:val')) : '';
            if ($isBold || strpos(strtolower($styleId), 'negrita') !== false
                || strpos(strtolower($styleId), 'bold') !== false
                || strpos($styleId, 'Strong') !== false
                || strpos($name, 'negrita') !== false
                || strpos($name, 'bold') !== false
                || strpos($name, 'strong') !== false) {
                $ids[$styleId] = true;
            }
        }
        return $ids;
    }

    /**
     * Analiza un archivo .docx y devuelve ['meta' => [...], 'questions' => [...]]
     */
    public function parse($filePath) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception('No se pudo abrir el archivo .docx (¿está dañado?).');
        }
        $xml = $zip->getFromName('word/document.xml');
        $stylesXml = $zip->getFromName('word/styles.xml');
        $zip->close();
        if ($xml === false) {
            throw new Exception('El archivo no contiene word/document.xml (¿es un .docx válido?).');
        }

        $this->boldStyleIds = $this->extractBoldStyleIds($stylesXml);

        $dom = new DOMDocument();
        $dom->substituteEntities = false;
        libxml_use_internal_errors(true);
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS);
        $xpath->registerNamespace('m', self::NS_MATH);
        $this->xpath = $xpath;

        $meta = [];
        $questions = [];

        $tables = $xpath->query('//w:tbl');
        foreach ($tables as $tbl) {
            $rows = $xpath->query('./w:tr', $tbl);
            if ($rows->length === 0) continue;

            // Buscar la fila de encabezado real recorriendo TODAS las filas de la tabla,
            // porque algunos formatos insertan una fila fusionada ("Tema 1-2") antes del
            // encabezado verdadero (Nº / Preguntas / Opciones de respuesta).
            $headerRowIdx = null; $colQuestion = null; $colOptions = null; $colNum = null;
            for ($hr = 0; $hr < $rows->length; $hr++) {
                $cells = $xpath->query('./w:tc', $rows->item($hr));
                if ($cells->length < 2) continue;
                $texts = [];
                foreach ($cells as $tc) $texts[] = normalize_ws($this->cellText($tc));
                $cq = null; $co = null; $cn = null;
                foreach ($texts as $i => $t) {
                    if (stripos($t, 'pregunta') !== false) $cq = $i;
                    if (stripos($t, 'opci') !== false || stripos($t, 'respuesta') !== false) $co = $i;
                    if (preg_match('/^n[°ºo]?\.?$/i', trim($t))) $cn = $i;
                }
                if ($cq !== null && $co !== null) {
                    $headerRowIdx = $hr; $colQuestion = $cq; $colOptions = $co; $colNum = $cn;
                    break;
                }
            }

            if ($headerRowIdx !== null) {
                // Es una tabla de preguntas
                for ($r = $headerRowIdx + 1; $r < $rows->length; $r++) {
                    $cells = $xpath->query('./w:tc', $rows->item($r));
                    if ($cells->length <= max($colQuestion, $colOptions)) continue;
                    $qText = normalize_ws($this->cellText($cells->item($colQuestion)));
                    if ($qText === '') continue;
                    $opts = $this->parseOptionsCell($cells->item($colOptions));
                    if (count($opts) < 2) continue;

                    [$correctIdx, $confident] = $this->detectCorrect($opts);

                    $declaredNum = null;
                    if ($colNum !== null && $cells->length > $colNum) {
                        $numText = normalize_ws($this->cellText($cells->item($colNum)));
                        if (preg_match('/(\d{1,3})/', $numText, $mm)) $declaredNum = (int)$mm[1];
                    }

                    $questions[] = [
                        'text' => $qText,
                        'options' => array_map(fn($o) => $o['text'], $opts),
                        'correct' => $correctIdx,
                        'confident' => $confident,
                        'num' => $declaredNum,
                        'source' => $confident ? 'inline' : 'none',
                    ];
                }
            } else {
                // No es una tabla de preguntas: puede ser una tabla de metadatos
                // (Experiencia curricular | valor, Semana | valor, etc.) con filas de 2 columnas.
                foreach ($rows as $row) {
                    $cells = $xpath->query('./w:tc', $row);
                    if ($cells->length === 2) {
                        $label = normalize_ws($this->cellText($cells->item(0)));
                        $value = normalize_ws($this->cellText($cells->item(1)));
                        if ($label !== '' && strlen($label) < 60) $meta[$label] = $value;
                    }
                }
            }
        }

        // ------------------------------------------------------------------
        // Respaldo: algunos docentes no marcan la opción correcta dentro de la
        // tabla, sino que agregan al final del documento una "clave de
        // respuestas" en texto plano, p.ej.:
        //   "CLAVE RÁPIDA DE RESPUESTAS  01: b  02: c  03: c ..."
        // Si existe, se usa para completar automáticamente las preguntas que
        // no tuvieron una marca confiable dentro de la tabla.
        // ------------------------------------------------------------------
        $answerKeyMap = $this->extractAnswerKeyFromText($dom);
        if (!empty($answerKeyMap)) {
            foreach ($questions as $i => &$q) {
                if ($q['confident']) continue;
                $lookupNum = $q['num'] ?? ($i + 1);
                if (isset($answerKeyMap[$lookupNum]) && $answerKeyMap[$lookupNum] < count($q['options'])) {
                    $q['correct'] = $answerKeyMap[$lookupNum];
                    $q['confident'] = true;
                    $q['source'] = 'clave_final';
                }
            }
            unset($q);
        }

        return ['meta' => $meta, 'questions' => $questions];
    }

    /** Quita tildes comunes del español (sin depender de mbstring) */
    private function unaccent($s) {
        $map = [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        ];
        return strtr($s, $map);
    }

    /**
     * Busca en TODO el texto del documento (fuera de las tablas también) una
     * sección tipo "Clave de respuestas" / "Solucionario" / "Hoja de
     * respuestas" y extrae pares número:letra (ej. "01: b", "12) A").
     * Devuelve [numeroPregunta => indiceOpcion(0=A,1=B,...)] o [] si no hay.
     */
    private function extractAnswerKeyFromText($dom) {
        $allText = [];
        foreach ($this->xpath->query('//w:t') as $t) $allText[] = $t->textContent;
        $flat = strtolower($this->unaccent(implode(' ', $allText)));

        if (!preg_match(
            '/(clave\s*(rapida)?\s*de\s*respuestas|respuestas?\s*correctas|hoja\s*de\s*respuestas|solucionario|clave\s*de\s*correccion)/',
            $flat, $m, PREG_OFFSET_CAPTURE
        )) {
            return [];
        }
        $tail = substr($flat, $m[0][1] + strlen($m[0][0]));

        $map = [];
        if (preg_match_all('/\b(\d{1,3})\s*[:\.\-\)]\s*([a-h])\b/', $tail, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $pair) {
                $num = (int)$pair[1];
                $letterIdx = ord($pair[2]) - ord('a');
                $map[$num] = $letterIdx;
            }
        }
        return $map;
    }

    /** Texto plano de una celda (todas las corridas de texto separadas por espacio) */
    private function cellText($cell) {
        $texts = [];
        foreach ($this->xpath->query('.//w:t', $cell) as $t) $texts[] = $t->textContent;
        return implode(' ', $texts);
    }

    /**
     * Convierte los <w:p> (párrafos) de una corrida de texto en una lista de "tokens"
     * ordenados: ['type'=>'text','text'=>..,'bold'=>bool,'highlight'=>bool] o ['type'=>'break']
     */
    private function flattenParagraph($p) {
        $tokens = [];
        foreach ($p->childNodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE) continue;
            $localName = $node->localName;

            if ($localName === 'r') {
                $run = $node;
                $bold = false; $highlight = false;
                $rprList = $this->xpath->query('./w:rPr', $run);
                if ($rprList->length > 0) {
                    $rpr = $rprList->item(0);
                    $bNodes = $this->xpath->query('./w:b', $rpr);
                    if ($bNodes->length > 0) {
                        $val = $bNodes->item(0)->getAttribute('w:val');
                        $bold = ($val === '' || strtolower($val) === 'true' || $val === '1');
                    }
                    // Negrita aplicada mediante un estilo de carácter (w:rStyle) en vez de w:b directo
                    $rStyleNodes = $this->xpath->query('./w:rStyle', $rpr);
                    if ($rStyleNodes->length > 0) {
                        $styleId = $rStyleNodes->item(0)->getAttribute('w:val');
                        if (isset($this->boldStyleIds[$styleId])) $bold = true;
                    }
                    $hlNodes = $this->xpath->query('./w:highlight', $rpr);
                    if ($hlNodes->length > 0) {
                        $hv = strtolower($hlNodes->item(0)->getAttribute('w:val'));
                        $highlight = ($hv !== '' && $hv !== 'none');
                    }
                }
                foreach ($run->childNodes as $child) {
                    if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                    $ln = $child->localName;
                    if ($ln === 't') {
                        $tokens[] = ['type' => 'text', 'text' => $child->textContent, 'bold' => $bold, 'highlight' => $highlight];
                    } elseif ($ln === 'br') {
                        $tokens[] = ['type' => 'break'];
                    } elseif ($ln === 'tab') {
                        $tokens[] = ['type' => 'text', 'text' => ' ', 'bold' => $bold, 'highlight' => $highlight];
                    }
                }
            } elseif ($localName === 'oMath' || $localName === 'oMathPara') {
                // Fórmulas matemáticas (Ecuaciones de Word): se extrae su texto plano (m:t)
                // como contenido normal, sin negrita/resaltado (caso poco común de marcar
                // la respuesta correcta dentro de una fórmula).
                foreach ($this->xpath->query('.//m:t', $node) as $mt) {
                    $tokens[] = ['type' => 'text', 'text' => $mt->textContent, 'bold' => false, 'highlight' => false];
                }
            }
        }
        return $tokens;
    }

    /**
     * Analiza la celda de "Opciones de respuesta" y devuelve una lista ordenada de opciones:
     * [ ['text'=>.., 'highlight'=>bool, 'bold'=>bool, 'check'=>bool], ... ]
     */
    private function parseOptionsCell($cell) {
        $paragraphs = $this->xpath->query('./w:p', $cell);
        $segments = [];
        $markerRegex = '/^\s*[\(\[]?[A-Za-z0-9][\.\)\]]\s+/u';

        $flush = function ($tokens, $fromNumPr = false) use (&$segments, $markerRegex) {
            $text = '';
            foreach ($tokens as $tok) {
                if ($tok['type'] !== 'text') continue;
                $text .= $tok['text'];
            }
            $plain = normalize_ws($text);
            if ($plain === '') return;

            // Determinar a partir de qué desplazamiento (en la cadena SIN normalizar)
            // empieza el contenido real de la opción, saltando el marcador "a) " / "b." / etc.
            $markerLen = 0;
            if (preg_match($markerRegex, $text, $m)) $markerLen = strlen($m[0]);

            $offset = 0; $anyText = false; $allBold = true; $anyHighlight = false;
            foreach ($tokens as $tok) {
                if ($tok['type'] !== 'text') continue;
                $len = strlen($tok['text']);
                $tokEnd = $offset + $len;
                // Sólo se evalúa el formato de la parte del token que cae DESPUÉS del marcador.
                $overlapAfterMarker = $tokEnd - max($offset, $markerLen);
                if ($overlapAfterMarker > 0 && trim($tok['text']) !== '') {
                    $anyText = true;
                    if (empty($tok['bold'])) $allBold = false;
                    if (!empty($tok['highlight'])) $anyHighlight = true;
                }
                $offset = $tokEnd;
            }
            $segments[] = ['raw' => $plain, 'highlight' => $anyHighlight, 'bold' => ($anyText && $allBold), 'fromNumPr' => $fromNumPr];
        };

        foreach ($paragraphs as $p) {
            $pPrList = $this->xpath->query('./w:pPr', $p);
            $hasNumPr = false;
            if ($pPrList->length > 0) {
                $hasNumPr = $this->xpath->query('./w:numPr', $pPrList->item(0))->length > 0;
            }
            $tokens = $this->flattenParagraph($p);
            if (empty($tokens)) continue;

            if ($hasNumPr) {
                // Cada párrafo con numeración automática (a, b, c...) es UNA opción completa
                $flush($tokens, true);
            } else {
                // Puede haber varias opciones separadas por <w:br/> dentro del mismo párrafo.
                // Sólo se corta en el salto de línea si lo que sigue empieza con "a)", "b.", etc.
                $buffer = [];
                $n = count($tokens);
                for ($i = 0; $i < $n; $i++) {
                    $tok = $tokens[$i];
                    if ($tok['type'] === 'break') {
                        $j = $i + 1; $nextText = '';
                        while ($j < $n && $tokens[$j]['type'] !== 'text') $j++;
                        if ($j < $n) $nextText = $tokens[$j]['text'];
                        if (preg_match($markerRegex, $nextText)) {
                            $flush($buffer);
                            $buffer = [];
                        } else {
                            $buffer[] = ['type' => 'text', 'text' => ' ', 'bold' => false, 'highlight' => false];
                        }
                    } else {
                        $buffer[] = $tok;
                    }
                }
                if (!empty($buffer)) $flush($buffer);
            }
        }

        // Limpieza final: quitar marcador de letra ("a)", "B.", "(c)"...) y detectar símbolo de check.
        // Se descartan segmentos sueltos que NO tengan marcador de opción y no vengan de una lista
        // numerada automática (típicamente texto explicativo suelto, p.ej. "Cálculo: ...").
        $options = [];
        foreach ($segments as $seg) {
            if (!$seg['fromNumPr'] && !preg_match($markerRegex, $seg['raw'])) continue;
            $text = $seg['raw'];
            $hasCheck = false;
            foreach (self::CHECK_CHARS as $c) {
                if (strpos($text, $c) !== false) {
                    $hasCheck = true;
                    $text = str_replace($c, '', $text);
                }
            }
            $text = preg_replace($markerRegex, '', $text, 1);
            $text = trim(normalize_ws($text));
            if ($text === '') continue;
            $options[] = ['text' => $text, 'highlight' => $seg['highlight'], 'bold' => $seg['bold'], 'check' => $hasCheck];
        }
        return $options;
    }

    /**
     * Determina qué opción es la correcta.
     * Prioridad: símbolo ✓  >  resaltado (highlight)  >  negrita en contraste  >  indeterminado.
     * Devuelve [indice, confiable(bool)]
     */
    private function detectCorrect($opts) {
        foreach ($opts as $i => $o) if ($o['check']) return [$i, true];
        foreach ($opts as $i => $o) if ($o['highlight']) return [$i, true];

        $boldIdx = [];
        foreach ($opts as $i => $o) if ($o['bold']) $boldIdx[] = $i;
        if (count($boldIdx) >= 1 && count($boldIdx) < count($opts)) {
            return [$boldIdx[0], true];
        }
        return [0, false];
    }
}

// ============================================================================
//  GENERADOR AIKEN
// ============================================================================

function question_to_aiken($q, $letters) {
    $lines = [];
    $lines[] = $q['text'];
    foreach ($q['options'] as $i => $opt) {
        $letter = $letters[$i] ?? chr(65 + $i);
        $lines[] = $letter . ') ' . $opt;
    }
    $correctLetter = $letters[$q['correct']] ?? 'A';
    $lines[] = 'ANSWER: ' . $correctLetter;
    return implode("\n", $lines);
}

function batch_to_aiken($questions, $letters) {
    $blocks = [];
    foreach ($questions as $q) $blocks[] = question_to_aiken($q, $letters);
    return implode("\n\n", $blocks) . "\n";
}

function guess_unit_label($meta, $fallback) {
    $keys = array_keys($meta);
    $exp = null; $ses = null;
    foreach ($keys as $k) {
        $kl = strtolower($k);
        if (strpos($kl, 'experiencia') !== false) $exp = $meta[$k];
        if (strpos($kl, 'sesi') !== false || strpos($kl, 'semana') !== false) $ses = $meta[$k];
    }
    $parts = array_filter([$exp, $ses ? ('Sesión/Semana ' . $ses) : null]);
    return $parts ? implode(' — ', $parts) : $fallback;
}

// ============================================================================
//  MANEJO DE ACCIONES (POST)
// ============================================================================

$errors = [];
$action = $_POST['action'] ?? '';

// --- 1) Subida y procesamiento de archivos ---------------------------------
if ($action === 'upload' && !empty($_FILES['docs']['name'][0])) {
    $batches = [];
    $count = count($_FILES['docs']['name']);
    if ($count > MAX_FILES) $count = MAX_FILES;

    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['docs']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = 'Error al subir "' . htmlspecialchars($_FILES['docs']['name'][$i]) . '".';
            continue;
        }
        $origName = $_FILES['docs']['name'][$i];
        $tmpPath = $_FILES['docs']['tmp_name'][$i];
        if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'docx') {
            $errors[] = '"' . htmlspecialchars($origName) . '" no es un archivo .docx y fue omitido.';
            continue;
        }
        try {
            $parser = new DocxAikenParser();
            $result = $parser->parse($tmpPath);
            if (empty($result['questions'])) {
                $errors[] = 'No se detectaron preguntas en "' . htmlspecialchars($origName) . '". Revisa el formato del documento.';
                continue;
            }
            $batches[] = [
                'filename' => $origName,
                'label' => guess_unit_label($result['meta'], $origName),
                'meta' => $result['meta'],
                'questions' => $result['questions'],
            ];
        } catch (Exception $e) {
            $errors[] = 'Error procesando "' . htmlspecialchars($origName) . '": ' . htmlspecialchars($e->getMessage());
        }
    }

    if (!empty($batches)) {
        $_SESSION['batches'] = $batches;
    }
}

// --- 2) Reiniciar -----------------------------------------------------------
if ($action === 'reset') {
    unset($_SESSION['batches']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// --- 3) Aplicar ediciones manuales de respuesta correcta --------------------
if (in_array($action, ['save', 'download_unit', 'download_zip', 'download_combined']) && !empty($_SESSION['batches'])) {
    foreach ($_SESSION['batches'] as $bi => &$batch) {
        foreach ($batch['questions'] as $qi => &$q) {
            $field = "ans_{$bi}_{$qi}";
            if (isset($_POST[$field])) {
                $letterIdx = array_search($_POST[$field], $OPTION_LETTERS, true);
                if ($letterIdx !== false && $letterIdx < count($q['options'])) {
                    if ($letterIdx !== ($q['correct'] ?? null) || empty($q['confident'])) {
                        $q['source'] = 'manual';
                    }
                    $q['correct'] = $letterIdx;
                    $q['confident'] = true;
                }
            }
        }
        unset($q);
    }
    unset($batch);
}

// --- 4) Descargas ------------------------------------------------------------
if ($action === 'download_unit' && !empty($_SESSION['batches'])) {
    $idx = (int)($_POST['batch_index'] ?? -1);
    if (isset($_SESSION['batches'][$idx])) {
        $batch = $_SESSION['batches'][$idx];
        $content = batch_to_aiken($batch['questions'], $OPTION_LETTERS);
        $fname = safe_filename($batch['filename']) . '.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }
}

if ($action === 'download_combined' && !empty($_SESSION['batches'])) {
    $allQuestions = [];
    foreach ($_SESSION['batches'] as $batch) {
        foreach ($batch['questions'] as $q) $allQuestions[] = $q;
    }
    $content = batch_to_aiken($allQuestions, $OPTION_LETTERS);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="preguntas_conjunto_aiken.txt"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

if ($action === 'download_zip' && !empty($_SESSION['batches'])) {
    $tmpZip = tempnam(sys_get_temp_dir(), 'aiken_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $used = [];
        foreach ($_SESSION['batches'] as $batch) {
            $content = batch_to_aiken($batch['questions'], $OPTION_LETTERS);
            $base = safe_filename($batch['filename']);
            $fname = $base . '.txt';
            $n = 1;
            while (in_array($fname, $used)) { $fname = $base . '_' . (++$n) . '.txt'; }
            $used[] = $fname;
            $zip->addFromString($fname, $content);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="preguntas_por_unidad_aiken.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        readfile($tmpZip);
        unlink($tmpZip);
        exit;
    } else {
        $errors[] = 'No se pudo generar el archivo ZIP.';
    }
}

$batches = $_SESSION['batches'] ?? [];

// Estadísticas rápidas
$totalQuestions = 0; $totalUnsure = 0;
foreach ($batches as $b) {
    foreach ($b['questions'] as $q) {
        $totalQuestions++;
        if (empty($q['confident'])) $totalUnsure++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo APP_TITLE; ?></title>
<style>
  :root {
    --primary: #2f5496;
    --primary-dark: #1f3a6e;
    --ok: #2e7d32;
    --warn: #b45309;
    --warn-bg: #fff7e6;
    --border: #d8dce3;
    --bg: #f4f6f9;
  }
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    background: var(--bg);
    margin: 0;
    color: #202124;
  }
  header {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff;
    padding: 22px 28px;
  }
  header h1 { margin: 0; font-size: 22px; }
  header p { margin: 4px 0 0; opacity: .9; font-size: 14px; }
  .wrap { max-width: 980px; margin: 0 auto; padding: 24px 16px 60px; }
  .card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 22px;
    margin-bottom: 20px;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
  }
  .card h2 { margin-top: 0; font-size: 18px; color: var(--primary-dark); }
  .drop {
    border: 2px dashed #b7c1d1;
    border-radius: 10px;
    padding: 34px 20px;
    text-align: center;
    background: #fafbfd;
  }
  input[type=file] { margin: 14px 0; }
  button, .btn {
    background: var(--primary);
    color: #fff;
    border: none;
    padding: 11px 20px;
    border-radius: 7px;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
  }
  button:hover, .btn:hover { background: var(--primary-dark); }
  button.secondary { background: #6b7280; }
  button.secondary:hover { background: #4b5563; }
  .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
  .error-box {
    background: #fdecea; border: 1px solid #f5c2c0; color: #7a1a17;
    padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px;
  }
  .stat-row { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 6px; }
  .stat {
    background: #eef2f9; border-radius: 8px; padding: 10px 16px; font-size: 14px;
  }
  .stat b { display: block; font-size: 20px; color: var(--primary-dark); }
  .unit {
    border: 1px solid var(--border); border-radius: 10px; margin-bottom: 18px; overflow: hidden;
  }
  .unit-head {
    background: #eef2f9; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;
  }
  .unit-head h3 { margin: 0; font-size: 15px; color: var(--primary-dark); }
  .unit-head small { color: #555; }
  .qrow {
    padding: 14px 16px; border-top: 1px solid #eee;
  }
  .qrow.unsure { background: var(--warn-bg); }
  .qtext { font-weight: 600; margin-bottom: 8px; font-size: 14.5px; }
  .opt {
    display: flex; align-items: flex-start; gap: 8px; padding: 4px 0; font-size: 14px;
  }
  .opt input { margin-top: 3px; }
  .badge {
    display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 20px; margin-left: 8px; font-weight: 600;
  }
  .badge.ok { background: #e3f3e2; color: var(--ok); }
  .badge.warn { background: #fdecc8; color: var(--warn); }
  .badge.key { background: #e0eefc; color: #1a5fb4; }
  .toolbar {
    position: sticky; bottom: 0; background: #fff; border-top: 1px solid var(--border);
    padding: 14px 22px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
    box-shadow: 0 -2px 6px rgba(0,0,0,.05); border-radius: 0 0 10px 10px;
  }
  .muted { color: #666; font-size: 13px; }
  .example {
    background: #0d1117; color: #d1d5da; font-family: Consolas, monospace; font-size: 12.5px;
    padding: 14px 16px; border-radius: 8px; overflow-x: auto; white-space: pre;
  }
</style>
</head>
<body>
<header>
  <h1>📝 <?php echo APP_TITLE; ?></h1>
  <p>Convierte bancos de preguntas de Word (cualquier formato de tus docentes) al formato AIKEN, listo para importar en Moodle u otras plataformas.</p>
</header>

<div class="wrap">

<?php foreach ($errors as $err): ?>
  <div class="error-box">⚠️ <?php echo $err; ?></div>
<?php endforeach; ?>

<?php if (empty($batches)): ?>

  <div class="card">
    <h2>1. Sube tus documentos Word</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload">
      <div class="drop">
        <p><strong>Selecciona uno o varios archivos .docx</strong><br>
        <span class="muted">Bancos de preguntas, controles de lectura, etc. — cada archivo se tratará como una "unidad".</span></p>
        <input type="file" name="docs[]" accept=".docx" multiple required>
      </div>
      <div class="actions">
        <button type="submit">Procesar documentos</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>¿Cómo detecta la respuesta correcta?</h2>
    <p class="muted">El sistema reconoce automáticamente la respuesta marcada por el docente usando, en este orden de prioridad:</p>
    <ol class="muted">
      <li>Un símbolo de verificación junto a la opción (✓, ✔, ☑).</li>
      <li>Texto resaltado (highlight / marcador de color) sobre la opción correcta.</li>
      <li>Una opción en <strong>negrita</strong> cuando las demás no lo están.</li>
      <li>Si nada de lo anterior aparece, busca automáticamente una <strong>clave de respuestas al final del documento</strong> (ej. "Clave rápida de respuestas: 01: b 02: c ...", "Solucionario", "Hoja de respuestas") y la usa para completar las preguntas correspondientes.</li>
    </ol>
    <p class="muted">Si ninguna de estas señales aparece con claridad, la pregunta se marca como <span class="badge warn">revisar</span> para que la confirmes manualmente antes de descargar — nunca se descarta una pregunta por esto.</p>
    <p class="muted">Formato de salida (AIKEN):</p>
    <div class="example">¿Cuál es la capital de Perú?
A) Bogotá
B) Lima
C) Quito
D) Santiago
ANSWER: B</div>
  </div>

<?php else: ?>

  <form method="post" id="reviewForm">
    <input type="hidden" name="action" value="save" id="formAction">

    <div class="card">
      <h2>2. Revisa las respuestas detectadas</h2>
      <div class="stat-row">
        <div class="stat"><b><?php echo count($batches); ?></b>archivo(s) / unidad(es)</div>
        <div class="stat"><b><?php echo $totalQuestions; ?></b>preguntas totales</div>
        <div class="stat"><b style="color:<?php echo $totalUnsure ? 'var(--warn)' : 'var(--ok)'; ?>"><?php echo $totalUnsure; ?></b>por confirmar manualmente</div>
      </div>
      <p class="muted">Las filas en color ámbar no tuvieron una señal clara de respuesta correcta: verifica y marca la opción correcta con el botón de radio antes de descargar. Todo cambio se guarda al descargar o al presionar "Guardar cambios".</p>
    </div>

    <?php foreach ($batches as $bi => $batch): ?>
      <div class="unit">
        <div class="unit-head">
          <div>
            <h3><?php echo htmlspecialchars($batch['label']); ?></h3>
            <small><?php echo htmlspecialchars($batch['filename']); ?> · <?php echo count($batch['questions']); ?> preguntas</small>
          </div>
          <button type="submit" name="action" value="download_unit" onclick="document.getElementById('batchIndexField').value=<?php echo $bi; ?>;">⬇️ Descargar esta unidad (.txt)</button>
        </div>

        <?php foreach ($batch['questions'] as $qi => $q): ?>
          <div class="qrow <?php echo empty($q['confident']) ? 'unsure' : ''; ?>">
            <div class="qtext">
              <?php echo ($qi + 1) . '. ' . htmlspecialchars($q['text']); ?>
              <?php if (!empty($q['confident']) && ($q['source'] ?? '') === 'clave_final'): ?>
                <span class="badge key">detectada (clave final)</span>
              <?php elseif (!empty($q['confident']) && ($q['source'] ?? '') === 'manual'): ?>
                <span class="badge ok">confirmada manualmente</span>
              <?php elseif (!empty($q['confident'])): ?>
                <span class="badge ok">detectada</span>
              <?php else: ?>
                <span class="badge warn">revisar</span>
              <?php endif; ?>
            </div>
            <?php foreach ($q['options'] as $oi => $optText):
                $letter = $OPTION_LETTERS[$oi];
                $checked = ($oi === $q['correct']) ? 'checked' : '';
            ?>
              <label class="opt">
                <input type="radio" name="ans_<?php echo $bi; ?>_<?php echo $qi; ?>" value="<?php echo $letter; ?>" <?php echo $checked; ?>>
                <span><strong><?php echo $letter; ?>)</strong> <?php echo htmlspecialchars($optText); ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <input type="hidden" name="batch_index" id="batchIndexField" value="0">

    <div class="toolbar">
      <button type="submit" name="action" value="save" class="secondary">💾 Guardar cambios</button>
      <button type="submit" name="action" value="download_zip">📦 Descargar por unidad (.zip)</button>
      <button type="submit" name="action" value="download_combined">📄 Descargar todo en conjunto (.txt)</button>
      <button type="submit" name="action" value="reset" class="secondary" formnovalidate>🔄 Empezar de nuevo</button>
      <span class="muted">Los botones de descarga guardan automáticamente tus correcciones.</span>
    </div>
  </form>

<?php endif; ?>

</div>
</body>
</html>