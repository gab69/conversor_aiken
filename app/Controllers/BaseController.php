<?php

/** Utilidades comunes de los controladores: dibujar la página, responder JSON, leer opciones. */
abstract class BaseController
{
    protected $request;
    protected $repo;
    protected $errors = [];

    public function __construct(Request $request, QuizRepository $repo)
    {
        $this->request = $request;
        $this->repo    = $repo;
    }

    /** Muestra la pantalla de subida o la de revisión según haya datos cargados */
    protected function page(): void
    {
        $batches = $this->repo->batches();
        View::page($batches ? 'review' : 'upload', [
            'batches'  => $batches,
            'keys'     => $this->repo->keys(),
            'stats'    => ViewHelper::stats($batches),
            'errors'   => $this->errors,
            'onReview' => !empty($batches),
        ]);
    }

    protected function json(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    /** Aplica a los datos guardados las ediciones enviadas desde la pantalla de revisión */
    protected function applyEdits(): void
    {
        $batches = $this->repo->batches();
        if (!$batches) return;
        $batches = ReviewEditor::apply($batches, $this->request->json('payload'));
        $batches = ReviewEditor::applyUnits($batches, $this->request->json('units'));
        $this->repo->saveBatches($batches);
    }
}
