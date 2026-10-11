<?php
/**
 * Controlador frontal: toda petición entra por aquí y el Router la envía al controlador correcto.
 */
require __DIR__ . '/app/bootstrap.php';

(new Router(new Request(), new QuizRepository()))->dispatch();
