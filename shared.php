<?php
require __DIR__ . '/lib.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$row   = $token === '' ? null : dbRow("SELECT document_id FROM document_shares WHERE token=?", "s", $token);
if (!$row) { http_response_code(404); die("Link not found"); }

header('Referrer-Policy: no-referrer');
header("Location: /editor/" . (int)$row['document_id'] . "?token=" . $token);
exit;
