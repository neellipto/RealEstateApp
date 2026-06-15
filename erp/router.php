<?php
/**
 * PHP Built-in Server Router
 * Used only in development / Replit deployment (php -S)
 * Handles health-check probes and static-file pass-through.
 */

$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);

// ── Health-check probe (Cloud Run / autoscale) ──────────────────────────────
// Replit's autoscale deployer probes GET / with GoogleHC/1.0.
// Return 200 immediately so the promote step succeeds.
if ($path === '/' && str_contains($_SERVER['HTTP_USER_AGENT'] ?? '', 'GoogleHC')) {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'OK';
    return true;
}

// ── Static files (css, js, images, fonts) ──────────────────────────────────
// Let the built-in server serve them directly.
$docRoot = __DIR__;
$file    = $docRoot . $path;
if ($path !== '/' && is_file($file)) {
    return false; // built-in server handles it
}

// ── Default entry point ─────────────────────────────────────────────────────
// Redirect bare / to login; everything else falls through to the requested .php
if ($path === '/') {
    header('Location: /login.php', true, 302);
    return true;
}

return false;
