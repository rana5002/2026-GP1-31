<?php
// Loads Composer packages and the .env file, and gives us env().
// Locally: reads settings from .env at the repo root.
// On Clever Cloud: settings come from real environment variables (no .env needed).

require_once __DIR__ . '/../../vendor/autoload.php';

$repoRoot = dirname(__DIR__, 2);
Dotenv\Dotenv::createImmutable($repoRoot)->safeLoad();

function env(string $key, $default = null)
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}