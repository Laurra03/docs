<?php
require __DIR__ . '/../lib.php';

$doc = resolveDocAccess((int)($_GET['document_id'] ?? 0), requestShareToken());
if (!$doc) { http_response_code(currentUser() ? 404 : 403); exit; }
$dir = docDir((int)$doc['id']);

$tmpZip = tempnam(sys_get_temp_dir(), 'typst_zip_');
register_shutdown_function(function () use ($tmpZip) { @unlink($tmpZip); });

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit; }
// listProjectFiles() leaves out .git (it can hold remote credentials) and symlinks.
foreach (listProjectFiles($dir) as $rel) {
    $zip->addFile($dir . '/' . $rel, $rel);
}
if ($zip->numFiles === 0) $zip->addFromString('main.typ', '');
$zip->close();

$name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $doc['title']) ?: 'document';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '.zip"');
header('Content-Length: ' . filesize($tmpZip));
header('X-Content-Type-Options: nosniff');
readfile($tmpZip);
