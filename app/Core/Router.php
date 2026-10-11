<?php

/** Relaciona cada acción del formulario con un método de controlador. */
final class Router
{
    const ROUTES = [
        ''                  => ['PageController',   'index'],
        'reset'             => ['PageController',   'reset'],
        'upload'            => ['UploadController', 'upload'],
        'upload_chunk'      => ['UploadController', 'chunk'],
        'autosave'          => ['ReviewController', 'autosave'],
        'save'              => ['ReviewController', 'save'],
        'add_question'      => ['ReviewController', 'addQuestion'],
        'delete_question'   => ['ReviewController', 'deleteQuestion'],
        'download_unit'     => ['ExportController', 'unit'],
        'download_zip'      => ['ExportController', 'zip'],
        'download_combined' => ['ExportController', 'combined'],
    ];

    private $request;
    private $repo;

    public function __construct(Request $request, QuizRepository $repo)
    {
        $this->request = $request;
        $this->repo    = $repo;
    }

    public function dispatch(): void
    {
        list($class, $method) = self::ROUTES[$this->request->action()] ?? self::ROUTES[''];
        (new $class($this->request, $this->repo))->$method();
    }
}
