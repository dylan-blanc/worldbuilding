<?php

declare(strict_types=1);

if (!function_exists("envValue")) {
    function envValue(string $name, string $default): string
    {
        $file = getenv($name . "_FILE");

        if ($file !== false && is_readable($file)) {
            return trim(file_get_contents($file));
        }

        $value = getenv($name);

        return $value !== false && $value !== "" ? $value : $default;
    }
}
