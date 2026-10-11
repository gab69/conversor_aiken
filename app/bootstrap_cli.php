<?php
/** Arranque sin sesión para pruebas por consola. */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    foreach (['Core', 'Models', 'Services', 'Readers', 'Parsing', 'Images', 'Export', 'Controllers'] as $layer) {
        $file = __DIR__ . '/' . $layer . '/' . $class . '.php';
        if (is_file($file)) { require $file; return; }
    }
});
