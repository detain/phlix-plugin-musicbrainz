<?php

/**
 * Transport test fixture: router script for `php -S` used by HttpClientTest.
 *
 * A hermetic, offline stand-in for httpbin.org so the real blocking cURL
 * transport can be exercised against a live socket without ever dialing an
 * external host. Endpoints:
 *
 *   /get          -> JSON {url, args} (query echo)
 *   /headers      -> JSON {headers}   (request-header echo, HTTP_* scan)
 *   /status/NNN   -> HTTP status NNN with a body the client must discard
 *   /delay/S      -> sleep S seconds, then 200 'slow' (client-timeout leg)
 *   /etag         -> ETag '"v1"'; If-None-Match: '"v1"' -> 304, no body
 *   /redirect     -> 302 with Location: /final (fence/REDIR_PROTOCOLS legs)
 *   /final        -> 200 'final-body' (the redirect target)
 *   /bytes/N      -> 200 with an exactly N-byte body (byte-budget leg)
 *
 * This file is test scaffolding only — production code must never route here.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path) || $path === '') {
    $path = '/';
}

if ($path === '/get') {
    header('Content-Type: application/json');
    echo json_encode([
        'url' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'args' => $_GET,
    ]);
    return;
}

if ($path === '/headers') {
    $requestHeaders = [];
    foreach ($_SERVER as $key => $value) {
        $key = (string)$key;
        if (str_starts_with($key, 'HTTP_')) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $requestHeaders[$name] = (string)$value;
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['headers' => $requestHeaders]);
    return;
}

if (preg_match('#^/status/(\d{3})$#', $path, $matches) === 1) {
    http_response_code((int)$matches[1]);
    echo 'status-' . $matches[1];
    return;
}

if (preg_match('#^/delay/([0-9.]+)$#', $path, $matches) === 1) {
    usleep((int)round((float)$matches[1] * 1000000));
    echo 'slow';
    return;
}

if ($path === '/etag') {
    header('ETag: "v1"');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"v1"') {
        http_response_code(304);
        return;
    }

    echo 'etag-body';
    return;
}

if ($path === '/redirect') {
    header('Location: /final');
    http_response_code(302);
    return;
}

if ($path === '/final') {
    echo 'final-body';
    return;
}

if (preg_match('#^/bytes/(\d+)$#', $path, $matches) === 1) {
    echo str_repeat('x', min((int)$matches[1], 10 * 1024 * 1024));
    return;
}

http_response_code(404);
echo 'no-route';
