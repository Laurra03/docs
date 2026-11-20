<?php
require __DIR__ . '/../lib.php';

$doc = resolveDocAccess((int)($_GET['document_id'] ?? 0), requestShareToken());
if (!$doc) { http_response_code(currentUser() ? 404 : 403); exit; }

$rel  = safeRelPath($_GET['filename'] ?? '');
$path = $rel === null ? null : pathInDir(docDir((int)$doc['id']), $rel);
if ($path === null)   { http_response_code(400); exit; }
if (!is_file($path))  { http_response_code(404); exit; }

// User-controlled files are served from our own origin: never let the browser
// sniff or execute them. Only a few inert types are rendered inline.
$inline = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'avif' => 'image/avif', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
];
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = $inline[$ext] ?? 'text/plain; charset=utf-8';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");
header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($path)) . '"');
readfile($path);
