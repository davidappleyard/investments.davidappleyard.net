<?php
/**
 * Load environment variables from .env file.
 * Defines each KEY as a PHP constant (using define) if not already defined.
 * Supports KEY=VALUE format; lines starting with # are comments.
 */
function load_env(): void {
    $path = __DIR__ . '/.env';
    if (!file_exists($path)) {
        throw new RuntimeException(
            'Missing .env file. Copy .env.example to .env and configure your database credentials.'
        );
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // Strip surrounding single or double quotes
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if (!defined($key)) {
            define($key, $value);
        }
    }
}
