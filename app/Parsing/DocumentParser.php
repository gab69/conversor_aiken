<?php


final class DocumentParser
{
    public static function parse(string $path, string $ext): array
    {
        $meta = []; $rawImages = [];
        if ($ext === 'docx') {
            $reader = new DocxReader();
            $reader->read($path);
            $sets = [$reader->lines];
            $meta = $reader->meta;
            $rawImages = $reader->images;
        } elseif ($ext === 'pdf') {
            $sets = PdfReader::lineSets($path);
        } else {
            $sets = [PlainTextReader::toLines((string)file_get_contents($path), false)];
        }

        $best = null;
        foreach ($sets as $lines) {
            $res = self::extractBest($lines);
            if ($best === null || self::score($res) > self::score($best)) $best = $res;
        }
        $best['meta'] = $meta;
        // Documento que sólo trae la clave (tabla N.º | R): el título suelto no cuenta como pregunta
        $key = AnswerKey::fromRawLines($sets[0] ?? []);
        if ($key && ($best['info']['mcq'] ?? 0) === 0 && count($best['questions']) <= 3) {
            $best['questions'] = [];
            $best['key'] = $key;
        }
        list($best['images'], $best['imgFailed']) = ImageStore::prepare($best['questions'], $rawImages);
        return $best;
    }

    /** Si el modo automático no encuentra nada, prueba el modo contrario (numerado / sin numerar) */
    private static function extractBest(array $lines): array
    {
        $ex  = new QuestionExtractor();
        $res = $ex->extract($lines);
        if (count($res['questions']) === 0) {
            $alt = (new QuestionExtractor())->extract($lines, !$res['info']['numbered']);
            if (count($alt['questions']) > 0) return $alt;
        }
        return $res;
    }

    private static function score(array $res): int
    {
        $conf = count(array_filter($res['questions'], function ($q) { return $q['confident']; }));
        return count($res['questions']) * 1000 + $conf;
    }
}
