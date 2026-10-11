<?php
/**
 * Arranque de la aplicación: configuración de PHP, autocarga de clases y sesión.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
@ini_set('pcre.backtrack_limit', '10000000');
@set_time_limit(120);

define('APP_ROOT', dirname(__DIR__));

/** Cada clase vive en app/<Capa>/<Clase>.php */
spl_autoload_register(function ($class) {
    foreach (['Core', 'Models', 'Services', 'Readers', 'Parsing', 'Images', 'Export', 'Controllers'] as $layer) {
        $file = __DIR__ . '/' . $layer . '/' . $class . '.php';
        if (is_file($file)) { require $file; return; }
    }
});

session_start();
