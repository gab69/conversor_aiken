<?php

/** Descarga del XML de Moodle (todo junto, uno por unidad en .zip, o una sola unidad). */
final class ExportController extends BaseController
{
    /** XML de una sola unidad */
    public function unit(): void
    {
        $this->applyEdits();
        $all = $this->repo->batches();
        $idx = (int)$this->request->post('batch_index', -1);
        if (!isset($all[$idx])) { $this->page(); return; }
        $this->deliver([$all[$idx]], $all[$idx]['filename'], Text::safeFilename($all[$idx]['filename']) . '_moodle.zip');
    }

    /** Un XML por unidad dentro de un .zip */
    public function zip(): void
    {
        $this->applyEdits();
        $this->deliver($this->repo->batches(), null, 'preguntas_por_unidad_xml.zip');
    }

    /** Todas las unidades en un solo XML */
    public function combined(): void
    {
        $this->applyEdits();
        $this->deliver($this->repo->batches(), 'preguntas_conjunto', 'preguntas_conjunto_moodle.zip');
    }

    /**
     * Revisa que todo esté completo y envía el archivo. $baseName = null genera un XML por unidad.
     * Si algo falla vuelve a la revisión con el aviso.
     */
    private function deliver(array $batches, ?string $baseName, string $zipName): void
    {
        $issues = ExportService::incomplete($batches);
        if ($issues) {
            $this->errors[] = 'Completa estas preguntas antes de exportar:<br>' . implode('<br>', array_slice($issues, 0, 6))
                            . (count($issues) > 6 ? '<br>… y ' . (count($issues) - 6) . ' más.' : '');
            $this->page();
            return;
        }
        $files = [];
        if ($baseName === null) foreach ($batches as $b) $files = array_merge($files, ExportService::build([$b], $b['filename']));
        else $files = ExportService::build($batches, $baseName);

        if (!$files) $this->errors[] = 'No hay preguntas para exportar.';
        elseif (!Downloader::send($files, $zipName)) $this->errors[] = 'No se pudo generar el archivo ZIP.';
        $this->page();
    }
}
