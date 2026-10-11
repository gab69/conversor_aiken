<?php


final class DocxReader
{
    const NS      = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    const NS_MATH = 'http://schemas.openxmlformats.org/officeDocument/2006/math';
    const NS_R    = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const NS_A    = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    const NS_V    = 'urn:schemas-microsoft-com:vml';

    public $lines = [];
    public $meta  = [];

    private $xp;
    private $boldStyles   = [];
    private $paraStyleNum = [];
    private $numFmt       = [];
    private $counters     = [];
    public  $images       = [];     // N => ['ext','data'] (archivos de imagen del documento)
    private $rels         = [];     // rId => ruta dentro del .docx
    private $imgByRid     = [];
    private $zipH         = null;

    public function read(string $filePath): void
    {
        list($xml, $styles, $numbering) = $this->extractParts($filePath);
        $this->parseStyles($styles);
        $this->parseNumbering($numbering);

        $dom = $this->loadDom($xml);
        if (!$dom) throw new Exception('No se pudo interpretar el contenido del documento.');
        $this->xp = $this->xpath($dom);

        $body = $this->xp->query('/w:document/w:body')->item(0);
        if (!$body) throw new Exception('El documento no tiene cuerpo de texto.');
        $this->walk($body, false);
        if ($this->zipH) { $this->zipH->close(); $this->zipH = null; }
    }

    private function extractParts(string $filePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) throw new Exception('No se pudo abrir el archivo .docx (¿está dañado?).');
        $st = $zip->statName('word/document.xml');
        if (!$st) { $zip->close(); throw new Exception('El archivo no contiene word/document.xml (¿es un .docx válido?).'); }
        if ($st['size'] > 80 * 1024 * 1024) { $zip->close(); throw new Exception('El documento es demasiado grande para procesarlo.'); }
        $parts = [$zip->getFromName('word/document.xml'), $zip->getFromName('word/styles.xml'), $zip->getFromName('word/numbering.xml')];
        $this->parseRels($zip->getFromName('word/_rels/document.xml.rels'));
        $this->zipH = $zip;                                  // se cierra al terminar la lectura (las imágenes se leen bajo demanda)
        return $parts;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);
        $xp->registerNamespace('m', self::NS_MATH);
        $xp->registerNamespace('a', self::NS_A);
        $xp->registerNamespace('v', self::NS_V);
        return $xp;
    }

    /** Carga XML sin resolver entidades externas (evita XXE) */
    private function loadDom($xml): ?DOMDocument
    {
        if ($xml === false || $xml === null || $xml === '') return null;
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new Exception('El documento contiene definiciones XML no permitidas.');
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        return $ok ? $dom : null;
    }

    // ---- estilos y numeración ------------------------------------------------
    private function parseStyles($xml): void
    {
        $dom = $this->loadDom($xml);
        if (!$dom) return;
        $xp = $this->xpath($dom);
        foreach ($xp->query('//w:style') as $style) {
            $type = $style->getAttribute('w:type');
            $id   = $style->getAttribute('w:styleId');
            if ($id === '') continue;
            if ($type === 'character' && $this->isBoldStyle($xp, $style, $id)) {
                $this->boldStyles[$id] = true;
            } elseif ($type === 'paragraph') {
                $n = $xp->query('./w:pPr/w:numPr/w:numId', $style)->item(0);
                if ($n) {
                    $il = $xp->query('./w:pPr/w:numPr/w:ilvl', $style)->item(0);
                    $this->paraStyleNum[$id] = [$n->getAttribute('w:val'), $il ? (int)$il->getAttribute('w:val') : 0];
                }
            }
        }
    }

    private function isBoldStyle(DOMXPath $xp, DOMElement $style, string $id): bool
    {
        if ($xp->query('./w:rPr/w:b', $style)->length > 0) return true;
        $nameNode = $xp->query('./w:name', $style)->item(0);
        $haystack = strtolower($id . ' ' . ($nameNode ? $nameNode->getAttribute('w:val') : ''));
        foreach (['negrita', 'bold', 'strong'] as $word) {
            if (strpos($haystack, $word) !== false) return true;
        }
        return false;
    }

    private function parseNumbering($xml): void
    {
        $dom = $this->loadDom($xml);
        if (!$dom) return;
        $xp = $this->xpath($dom);
        $abs = [];
        foreach ($xp->query('//w:abstractNum') as $a) {
            $aid = $a->getAttribute('w:abstractNumId');
            foreach ($xp->query('./w:lvl', $a) as $lvl) {
                $f = $xp->query('./w:numFmt', $lvl)->item(0);
                $s = $xp->query('./w:start', $lvl)->item(0);
                $abs[$aid][(int)$lvl->getAttribute('w:ilvl')] = [$f ? $f->getAttribute('w:val') : 'decimal', $s ? (int)$s->getAttribute('w:val') : 1];
            }
        }
        foreach ($xp->query('//w:num') as $n) {
            $nid = $n->getAttribute('w:numId');
            $ref = $xp->query('./w:abstractNumId', $n)->item(0);
            if (!$ref) continue;
            $this->numFmt[$nid] = $abs[$ref->getAttribute('w:val')] ?? [];
            foreach ($xp->query('./w:lvlOverride', $n) as $ov) {
                $so = $xp->query('./w:startOverride', $ov)->item(0);
                if (!$so) continue;
                $il  = (int)$ov->getAttribute('w:ilvl');
                $cur = $this->numFmt[$nid][$il] ?? ['decimal', 1];
                $this->numFmt[$nid][$il] = [$cur[0], (int)$so->getAttribute('w:val')];
            }
        }
    }

    /** Numeración automática de Word */
    private function autoInfo(string $numId, int $ilvl): ?array
    {
        if ($numId === '' || $numId === '0') return null;
        $fmt   = $this->numFmt[$numId][$ilvl] ?? null;
        $f     = strtolower($fmt ? $fmt[0] : 'decimal');
        $start = $fmt ? $fmt[1] : 1;
        if (!isset($this->counters[$numId][$ilvl])) $this->counters[$numId][$ilvl] = $start - 1;
        $this->counters[$numId][$ilvl]++;
        foreach (array_keys($this->counters[$numId]) as $l) {
            if ($l > $ilvl) unset($this->counters[$numId][$l]);
        }
        $n = $this->counters[$numId][$ilvl];
        if (strpos($f, 'letter') !== false)  return ['type' => 'letter',  'n' => $n];
        if ($f === 'bullet')                 return ['type' => 'bullet',  'n' => $n];
        if (strpos($f, 'decimal') !== false) return ['type' => 'decimal', 'n' => $n];
        return null;
    }

    // ---- recorrido del cuerpo ------------------------------------------------
    private function walk(DOMNode $node, bool $inCell): void
    {
        foreach ($node->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE) continue;
            switch ($c->localName) {
                case 'p':   $this->addParagraph($c, $inCell); break;
                case 'tbl': $this->addTable($c); break;
                case 'sdt':
                    foreach ($c->childNodes as $sc) if ($sc->localName === 'sdtContent') $this->walk($sc, $inCell);
                    break;
                case 'sdtContent': case 'customXml': case 'ins':
                    $this->walk($c, $inCell);
                    break;
            }
        }
    }

    private function textOf(DOMNode $node): string
    {
        $t = '';
        foreach ($this->xp->query('.//w:t', $node) as $x) $t .= $x->textContent . ' ';
        return $t;
    }

    private function addTable(DOMNode $tbl): void
    {
        $rows = [];
        foreach ($tbl->childNodes as $r) if ($r->localName === 'tr') $rows[] = $r;
        if ($this->tryQuestionTable($rows) || $this->tryMetaTable($rows)) return;
        foreach ($rows as $tr) {
            foreach ($tr->childNodes as $tc) if ($tc->localName === 'tc') $this->walk($tc, true);
        }
    }

    private function cellsOf(DOMNode $tr): array
    {
        $cells = [];
        foreach ($tr->childNodes as $tc) if ($tc->localName === 'tc') $cells[] = $tc;
        return $cells;
    }

    /** Texto de una celda (con sus imágenes) en una sola línea */
    private function cellText(?DOMNode $tc): string
    {
        if (!$tc) return '';
        $saved = $this->lines; $this->lines = [];
        $this->walk($tc, true);
        $text = Text::normalize(implode(' ', array_column($this->lines, 'raw')));
        $this->lines = $saved;
        return $text;
    }

    private function pushLine(string $raw): void
    {
        $this->lines[] = ['raw' => $raw, 'tokens' => null, 'hard' => true, 'br' => false, 'cell' => false, 'auto' => null];
    }

    /** Columnas de una tabla "N.º | Pregunta | A | B | C | D | Respuesta" a partir de su fila de encabezado */
    private function questionColumns(DOMNode $head): ?array
    {
        $cols = ['num' => null, 'q' => null, 'ans' => null, 'opts' => []];
        foreach ($this->cellsOf($head) as $i => $tc) {
            $h = Text::compare($this->textOf($tc));
            if (preg_match('/^(?:n|no|nro|num|numero|item)$/', $h)) $cols['num'] = $i;
            elseif (preg_match('/^(?:pregunta|preguntas|enunciado|ejercicio|reactivo|question)$/', $h)) $cols['q'] = $i;
            elseif (preg_match('/^(?:respuesta|clave|rpta|correcta|answer|alternativa correcta|respuesta correcta)$/', $h)) $cols['ans'] = $i;
            elseif (preg_match('/^(?:opcion |alternativa |option )?([a-h])$/', $h, $m)) $cols['opts'][ord($m[1]) - 97] = $i;
        }
        return ($cols['q'] !== null && count($cols['opts']) >= 2) ? $cols : null;
    }

    /** Tabla con una pregunta por fila: se convierte en las mismas líneas que tendría un examen normal */
    private function tryQuestionTable(array $rows): bool
    {
        if (count($rows) < 2 || ($cols = $this->questionColumns($rows[0])) === null) return false;
        $n = 0;
        foreach (array_slice($rows, 1) as $tr) {
            $cells = $this->cellsOf($tr);
            $stem  = $this->cellText($cells[$cols['q']] ?? null);
            if ($stem === '') continue;
            $n++;
            $num = ($cols['num'] !== null) ? preg_replace('/\D/', '', $this->cellText($cells[$cols['num']] ?? null)) : '';
            $this->pushLine(($num !== '' ? $num : $n) . '. ' . $stem);
            foreach ($cols['opts'] as $idx => $c) $this->pushLine(Config::LETTERS[$idx] . ') ' . $this->cellText($cells[$c] ?? null));
            $ans = ($cols['ans'] !== null) ? $this->cellText($cells[$cols['ans']] ?? null) : '';
            if ($ans !== '') $this->pushLine('Respuesta: ' . $ans);
        }
        return $n > 0;
    }

    /** Tablas de 2 columnas "Experiencia curricular | valor": se guardan como metadatos */
    private function tryMetaTable(array $rows): bool
    {
        if (count($rows) < 1 || count($rows) > 15) return false;
        $pairs = [];
        foreach ($rows as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $tc) if ($tc->localName === 'tc') $cells[] = $tc;
            if (count($cells) !== 2) return false;
            foreach ($cells as $tc) {
                if ($this->xp->query('.//w:numPr', $tc)->length > 0) return false;
                if ($this->xp->query('./w:p', $tc)->length > 2) return false;
            }
            $label = Text::normalize($this->textOf($cells[0]));
            $value = Text::normalize($this->textOf($cells[1]));
            if ($label === '' || strlen($label) > 60) return false;
            if (preg_match('/^\d{1,3}$/', $label)) return false;
            if (preg_match('/pregunta|opci[oó]n|alternativa|respuesta/iu', $label)) return false;
            if (preg_match('/^\s*[\(\[]?[A-Ha-h][\)\]\.]\s/u', $value)) return false;
            $pairs[$label] = $value;
        }
        foreach ($pairs as $k => $v) $this->meta[$k] = $v;
        return true;
    }

    // ---- párrafos y formato --------------------------------------------------
    private function addParagraph(DOMElement $p, bool $inCell): void
    {
        list($numId, $ilvl, $paraHl) = $this->paragraphProps($p);
        $chunks = [[]];
        foreach ($this->collectTokens($p, $paraHl) as $t) {
            if ($t['type'] === 'br') $chunks[] = [];
            else $chunks[count($chunks) - 1][] = $t;
        }
        $auto = ($numId !== '') ? $this->autoInfo($numId, $ilvl) : null;

        foreach ($chunks as $i => $tk) {
            $raw = implode('', array_column($tk, 'text'));
            if (Text::normalize($raw) === '') continue;
            $this->lines[] = [
                'raw' => $raw, 'tokens' => $tk, 'hard' => ($i === 0), 'br' => ($i > 0),
                'cell' => $inCell, 'auto' => ($i === 0) ? $auto : null,
            ];
        }
    }

    private function paragraphProps(DOMElement $p): array
    {
        $numId = ''; $ilvl = 0; $paraHl = false;
        $pPr = $this->xp->query('./w:pPr', $p)->item(0);
        if (!$pPr) return [$numId, $ilvl, $paraHl];

        $n = $this->xp->query('./w:numPr/w:numId', $pPr)->item(0);
        if ($n) {
            $numId = $n->getAttribute('w:val');
            $il    = $this->xp->query('./w:numPr/w:ilvl', $pPr)->item(0);
            $ilvl  = $il ? (int)$il->getAttribute('w:val') : 0;
        } else {
            $ps = $this->xp->query('./w:pStyle', $pPr)->item(0);
            if ($ps && isset($this->paraStyleNum[$ps->getAttribute('w:val')])) {
                list($numId, $ilvl) = $this->paraStyleNum[$ps->getAttribute('w:val')];
            }
        }
        $shd = $this->xp->query('./w:shd', $pPr)->item(0);
        if ($shd) $paraHl = $this->isRealFill($shd->getAttribute('w:fill'));
        return [$numId, $ilvl, $paraHl];
    }

    private function isRealFill(string $fill): bool
    {
        $fill = strtoupper($fill);
        return $fill !== '' && $fill !== 'AUTO' && $fill !== 'FFFFFF';
    }

    private function colorIsVivid(string $hex): bool
    {
        $hex = ltrim(strtolower($hex), '#');
        if (!preg_match('/^[0-9a-f]{6}$/', $hex)) return false;
        $c = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        return (max($c) - min($c)) > 50;
    }

    private function tok(string $text, array $f): array
    {
        return ['type' => 'text', 'text' => $text, 'bold' => $f['b'], 'hl' => $f['h'], 'col' => $f['c'], 'ul' => $f['u']];
    }

    private function runFormat(DOMElement $run, bool $paraHl): array
    {
        $f = ['b' => false, 'h' => $paraHl, 'c' => false, 'u' => false];
        $rpr = $this->xp->query('./w:rPr', $run)->item(0);
        if (!$rpr) return $f;
        foreach ($rpr->childNodes as $x) {
            if ($x->nodeType !== XML_ELEMENT_NODE) continue;
            $val = strtolower($x->getAttribute('w:val'));
            switch ($x->localName) {
                case 'b':         $f['b'] = !in_array($val, ['0', 'false', 'off'], true); break;
                case 'rStyle':    if (isset($this->boldStyles[$x->getAttribute('w:val')])) $f['b'] = true; break;
                case 'highlight': if ($val !== '' && $val !== 'none') $f['h'] = true; break;
                case 'shd':       if ($this->isRealFill($x->getAttribute('w:fill'))) $f['h'] = true; break;
                case 'color':     if ($this->colorIsVivid($val)) $f['c'] = true; break;
                case 'u':         $f['u'] = !in_array($val, ['none', '0', 'false'], true); break;
            }
        }
        return $f;
    }

    private function runTokens(DOMElement $run, bool $paraHl): array
    {
        $out = [];
        $f = $this->runFormat($run, $paraHl);
        foreach ($run->childNodes as $ch) {
            if ($ch->nodeType !== XML_ELEMENT_NODE) continue;
            switch ($ch->localName) {
                case 't':            $out[] = $this->tok($ch->textContent, $f); break;
                case 'tab':          $out[] = $this->tok(' ', $f); break;
                case 'noBreakHyphen':$out[] = $this->tok('-', $f); break;
                case 'br': case 'cr':$out[] = ['type' => 'br']; break;
                case 'sym':
                    $code = strtoupper(substr($ch->getAttribute('w:char'), -2));
                    if (in_array($code, ['FC', 'FE'], true))           $out[] = $this->tok('✓ ', $f);
                    elseif (in_array($code, ['A8', '6F', 'A1'], true)) $out[] = $this->tok('☐ ', $f);
                    break;
                case 'object': case 'drawing': case 'pict': case 'AlternateContent':
                    // Imágenes y ecuaciones (MathType/OLE): se dejan como marcador ⟦img:N⟧ en su posición exacta
                    $out[] = $this->tok($this->imageMarker($ch), $f);
                    break;
                case 'fldChar':
                    $box = $this->checkboxToken($ch, $f);
                    if ($box) $out[] = $box;
                    break;
            }
        }
        return $out;
    }

    private function checkboxToken(DOMElement $fld, array $f): ?array
    {
        $cb = $this->xp->query('./w:ffData/w:checkBox', $fld)->item(0);
        if (!$cb) return null;
        $checked = false;
        $chk = $this->xp->query('./w:checked', $cb)->item(0);
        $def = $this->xp->query('./w:default', $cb)->item(0);
        if ($chk)     $checked = !in_array(strtolower($chk->getAttribute('w:val')), ['0', 'false', 'off'], true);
        elseif ($def) $checked = in_array(strtolower($def->getAttribute('w:val')), ['1', 'true', 'on'], true);
        return $this->tok($checked ? '☑ ' : '☐ ', $f);
    }

    private function parseRels($xml): void
    {
        $dom = $this->loadDom($xml);
        if (!$dom) return;
        foreach ($dom->getElementsByTagName('Relationship') as $r) {
            if (substr($r->getAttribute('Type'), -6) === '/image') $this->rels[$r->getAttribute('Id')] = $r->getAttribute('Target');
        }
    }

    /** Texto que representa las imágenes/ecuaciones de un elemento: ⟦img:N⟧ (o [imagen] si no se puede leer el archivo) */
    private function imageMarker(DOMNode $node): string
    {
        $ids = [];
        foreach ($this->xp->query('.//a:blip', $node) as $b)      $ids[] = $b->getAttributeNS(self::NS_R, 'embed');
        foreach ($this->xp->query('.//v:imagedata', $node) as $b) $ids[] = $b->getAttributeNS(self::NS_R, 'id');
        $out = '';
        foreach ($ids as $rid) $out .= $this->registerImage($rid);
        return $out !== '' ? ' ' . $out . ' ' : ' [imagen] ';
    }

    private function registerImage(string $rid): string
    {
        if (!isset($this->rels[$rid]) || !$this->zipH) return '';
        if (!isset($this->imgByRid[$rid])) {
            $target = ltrim(preg_replace('#^\.\./#', '', $this->rels[$rid]), '/');
            $data = $this->zipH->getFromName(strpos($target, 'word/') === 0 ? $target : 'word/' . $target);
            if ($data === false || $data === '') return '';
            $n = count($this->images) + 1;
            $this->images[$n] = ['ext' => strtolower(pathinfo($target, PATHINFO_EXTENSION)), 'data' => $data];
            $this->imgByRid[$rid] = $n;
        }
        return '⟦img:' . $this->imgByRid[$rid] . '⟧';
    }

    private function collectTokens(DOMNode $node, bool $paraHl): array
    {
        $containers = ['hyperlink', 'ins', 'smartTag', 'sdt', 'sdtContent', 'fldSimple', 'customXml', 'moveTo'];
        $out = [];
        foreach ($node->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE) continue;
            $ln = $c->localName;
            if ($ln === 'r') {
                $out = array_merge($out, $this->runTokens($c, $paraHl));
            } elseif (in_array($ln, $containers, true)) {
                $out = array_merge($out, $this->collectTokens($c, $paraHl));
            } elseif ($ln === 'oMath' || $ln === 'oMathPara') {
                $txt = '';
                foreach ($this->xp->query('.//m:t', $c) as $mt) $txt .= $mt->textContent;
                if ($txt !== '') $out[] = $this->tok($txt, ['b' => false, 'h' => false, 'c' => false, 'u' => false]);
            }
        }
        return $out;
    }
}
