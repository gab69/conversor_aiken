<?php

/** Motor de plantillas mínimo: app/Views/<nombre>.php con sus variables. */
final class View
{
    public static function render(string $template, array $data = []): string
    {
        $h = 'ViewHelper::h';                    // atajo para escapar HTML en las plantillas
        extract($data, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/app/Views/' . $template . '.php';
        return (string)ob_get_clean();
    }

    /** Dibuja una página completa: la plantilla dentro del diseño general */
    public static function page(string $template, array $data): void
    {
        $data['content'] = self::render($template, $data);
        echo self::render('layout', $data);
    }
}
