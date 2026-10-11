<?php

/** Dónde se guardan los exámenes cargados mientras el usuario los revisa (sesión). */
final class QuizRepository
{
    public function batches(): array { return $_SESSION['batches'] ?? []; }

    public function keys(): array { return $_SESSION['keys'] ?? []; }

    public function saveBatches(array $batches): void { $_SESSION['batches'] = $batches; }

    public function clear(): void { unset($_SESSION['batches'], $_SESSION['keys']); }

    /** Suma un examen o un documento de claves y vuelve a vincular claves con exámenes */
    public function add(?array $item, bool $append): void
    {
        $batches = $append ? $this->batches() : [];
        $keys    = $append ? $this->keys() : [];
        if ($item) {
            if (($item['kind'] ?? '') === 'key') $keys[] = $item; else $batches[] = $this->withIds($item);
        }
        list($_SESSION['batches'], $_SESSION['keys']) = KeyLinker::link($batches, $keys);
    }

    /** Cada pregunta recibe un id estable para poder editarla, agregarla o eliminarla sin depender de su posición */
    private function withIds(array $batch): array
    {
        foreach ($batch['questions'] as &$q) $q['id'] = $q['id'] ?? Uid::make();
        unset($q);
        return $batch;
    }

    /** La siguiente carga de la página (GET) conservará los datos: sirve tras subir archivos */
    public function keepForNextLoad(): void { $_SESSION['keep_once'] = true; }

    /** Cada ingreso a la página empieza de cero, salvo la recarga posterior a una subida */
    public function startFresh(): void
    {
        if (!empty($_SESSION['keep_once'])) { unset($_SESSION['keep_once']); return; }
        $this->clear();
    }
}
