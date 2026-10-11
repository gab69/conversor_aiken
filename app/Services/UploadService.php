<?php

/** Lee archivos subidos (completos o por fragmentos) y los convierte en unidades de preguntas. */
final class UploadService
{
    private $errors = [];

    public function errors(): array { return $this->errors; }

    /** Archivo recibido por el formulario normal ($_FILES['docs'][...]) */
    public function fromUpload(array $files, int $i): ?array
    {
        $orig = $files['name'][$i];
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            return $this->fail('Error al subir "' . Text::h($orig) . '" (revisa upload_max_filesize / post_max_size en PHP).');
        }
        return $this->process($orig, $files['tmp_name'][$i], (int)$files['size'][$i]);
    }

    /**
     * Subida por fragmentos: el navegador parte cada archivo en trozos pequeños, así que
     * no importa el upload_max_filesize del servidor. Al llegar el último trozo se procesa.
     * @return array ['done' => bool, 'item' => ?array]
     */
    public function receiveChunk(Request $request): array
    {
        $uid   = preg_replace('/[^a-f0-9]/', '', (string)$request->post('uid', ''));
        $idx   = (int)$request->post('index', -1);
        $total = (int)$request->post('total', 0);
        $name  = (string)$request->post('name', '');
        $chunk = $request->file('chunk');

        if (strlen($uid) < 8 || $total < 1 || $idx < 0 || $idx >= $total || !$chunk || $chunk['error'] !== UPLOAD_ERR_OK) {
            $this->fail('Error al subir "' . Text::h($name) . '" (un fragmento no llegó completo; revisa post_max_size en PHP).');
            return ['done' => false, 'item' => null];
        }
        $dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        $part = $dir . 'moodle_' . md5(session_id()) . '_' . $uid . '.part';
        if ($idx === 0) { $this->purgeOldParts($dir); @unlink($part); }

        if (@file_put_contents($part, file_get_contents($chunk['tmp_name']), FILE_APPEND | LOCK_EX) === false
            || filesize($part) > Config::MAX_FILE_BYTES) {
            @unlink($part);
            $this->fail('"' . Text::h($name) . '" no se pudo guardar o supera ' . (Config::MAX_FILE_BYTES / 1048576) . ' MB.');
            return ['done' => false, 'item' => null];
        }
        if ($idx < $total - 1) return ['done' => false, 'item' => null];     // faltan fragmentos

        $item = $this->process($name, $part, filesize($part));
        @unlink($part);
        return ['done' => true, 'item' => $item];
    }

    /** @return array|null unidad de examen, documento de claves ('kind' => 'key') o null si falló */
    public function process(string $orig, string $path, int $size): ?array
    {
        $name = Text::h($orig);
        if ($size > Config::MAX_FILE_BYTES) {
            return $this->fail('"' . $name . '" supera el tamaño máximo permitido (' . (Config::MAX_FILE_BYTES / 1048576) . ' MB).');
        }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['docx', 'pdf', 'txt'], true)) {
            return $this->fail($ext === 'doc'
                ? '"' . $name . '" es un .doc antiguo: ábrelo en Word y guárdalo como .docx.'
                : '"' . $name . '" no es un archivo .docx, .pdf o .txt y fue omitido.');
        }
        try {
            $result = DocumentParser::parse($path, $ext);
        } catch (Throwable $e) {
            return $this->fail('Error procesando "' . $name . '": ' . Text::h($e->getMessage()));
        }
        if (empty($result['questions']) && !empty($result['key'])) {
            return ['kind' => 'key', 'filename' => $orig, 'map' => $result['key']];
        }
        if (empty($result['questions'])) {
            return $this->fail('No se detectaron preguntas en "' . $name . '". Verifica que el documento tenga texto seleccionable (no imágenes).');
        }
        return [
            'filename' => $orig, 'label' => ExportUtil::unitLabel($result['meta'], $orig),
            'meta' => $result['meta'], 'questions' => $result['questions'], 'info' => $result['info'],
            'images' => $result['images'], 'imgFailed' => $result['imgFailed'],
        ];
    }

    private function purgeOldParts(string $dir): void
    {
        foreach (glob($dir . 'moodle_*.part') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
    }

    private function fail(string $msg): ?array
    {
        $this->errors[] = $msg;
        return null;
    }
}
