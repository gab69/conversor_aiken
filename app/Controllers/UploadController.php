<?php

/** Recepción de documentos: por fragmentos (AJAX, sin límite de tamaño de PHP) o con el formulario clásico. */
final class UploadController extends BaseController
{
    /** Un fragmento de archivo; al llegar el último se procesa y se guarda */
    public function chunk(): void
    {
        $uploads = new UploadService();
        $result  = $uploads->receiveChunk($this->request);
        if ($result['done']) {
            $this->repo->add($result['item'], $this->request->has('append'));
            $this->repo->keepForNextLoad();
        }
        $this->json(['ok' => empty($uploads->errors()), 'errors' => $uploads->errors()]);
    }

    /** Formulario normal (sin JavaScript): archivos completos en una sola petición */
    public function upload(): void
    {
        $files = $this->request->file('docs');
        if ($files && !empty($files['name'][0])) {
            $uploads = new UploadService();
            $append  = false;
            foreach (array_keys($files['name']) as $i) {
                $this->repo->add($uploads->fromUpload($files, $i), $append);
                $append = true;
            }
            $this->errors = $uploads->errors();
        }
        $this->page();
    }
}
