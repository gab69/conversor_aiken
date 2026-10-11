<?php

/** Pantalla principal y reinicio. */
final class PageController extends BaseController
{
    public function index(): void
    {
        if (!$this->request->isPost()) $this->repo->startFresh();
        if ($this->request->exceededPostLimit()) {
            $this->errors[] = 'El envío superó el límite del servidor (post_max_size = ' . ini_get('post_max_size')
                . ', upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Aumenta esos valores en php.ini o sube menos peso por vez.';
        }
        $this->page();
    }

    public function reset(): void
    {
        $this->repo->clear();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}
