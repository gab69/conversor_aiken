<?php

/** Identificador corto y único para cada pregunta (las ediciones se aplican por id, no por posición). */
final class Uid
{
    public static function make(): string { return bin2hex(random_bytes(6)); }
}
