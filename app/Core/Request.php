<?php

/** Acceso ordenado a lo que envía el navegador ($_POST, $_FILES, JSON). */
final class Request
{
    public function isPost(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }

    public function action(): string { return (string)($_POST['action'] ?? ''); }

    public function has(string $key): bool { return isset($_POST[$key]); }

    public function post(string $key, $default = null) { return $_POST[$key] ?? $default; }

    public function file(string $key): ?array { return $_FILES[$key] ?? null; }

    /** Decodifica un campo POST que contiene JSON (p. ej. todas las ediciones de la revisión) */
    public function json(string $key): array
    {
        $data = json_decode((string)($_POST[$key] ?? ''), true);
        return is_array($data) ? $data : [];
    }

    /** PHP descarta todo el POST cuando supera post_max_size */
    public function exceededPostLimit(): bool
    {
        return $this->isPost() && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    }
}
