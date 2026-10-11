<?php

/** Edición de la revisión: autoguardado, agregar y eliminar preguntas. Todo responde en JSON, sin recargar. */
final class ReviewController extends BaseController
{
    /** Guarda las filas modificadas (se llama solo, unos instantes después de cada cambio) */
    public function autosave(): void
    {
        $this->applyEdits();
        $this->json(['ok' => true]);
    }

    /** Aplica los cambios y vuelve a dibujar la pantalla (p. ej. al pasar una pregunta a ensayo) */
    public function save(): void
    {
        $this->applyEdits();
        $this->page();
    }

    public function addQuestion(): void
    {
        $this->applyEdits();
        $bi   = (int)$this->request->post('bi', -1);
        $type = $this->request->post('type') === Config::TYPE_ESSAY ? Config::TYPE_ESSAY : Config::TYPE_MCQ;

        list($batches, $question) = ReviewEditor::addQuestion($this->repo->batches(), $bi, $type);
        if (!$question) $this->json(['ok' => false]);
        $this->repo->saveBatches($batches);

        $html = View::render('partials/question', [
            'bi' => $bi, 'qi' => count($batches[$bi]['questions']) - 1, 'q' => $question, 'images' => $batches[$bi]['images'] ?? [],
        ]);
        $this->json(['ok' => true, 'id' => $question['id'], 'html' => $html]);
    }

    public function deleteQuestion(): void
    {
        $this->applyEdits();
        $this->repo->saveBatches(ReviewEditor::deleteQuestion($this->repo->batches(), (string)$this->request->post('id', '')));
        $this->json(['ok' => true]);
    }
}
