<?php
/**
 * Development router for PHP's built-in server.
 * Run from the Ufuq-Final directory:
 * php -S localhost:8000 router.php
 *
 * PHP's built-in server ignores .htaccess, so block direct requests to
 * private verification documents here as well.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$normalized = str_replace('\\', '/', $path);

if (preg_match('#(?:^|/)uploads/docs(?:/|$)#i', $normalized)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Forbidden');
}

return false;
