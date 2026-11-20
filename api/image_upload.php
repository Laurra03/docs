<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['document_id'] ?? 0), 'edit');

$file = $_FILES['file'] ?? null;
if (!$file || is_array($file['error'])) fail('Upload error');
if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
    fail('File is too large (limit ' . ini_get('upload_max_filesize') . ')', 413);
}
if ($file['error'] !== UPLOAD_ERR_OK) fail('Upload error');

// Use the client-supplied relative path when it is safe, otherwise a sanitised basename.
$filename = safeRelPath($_POST['path'] ?? '', true)
    ?? safeRelPath(preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($file['name'])), true);
if ($filename === null) fail('Invalid filename');
[$filename, $target] = docPath($doc, $filename, true);

if (is_dir($target)) fail('A folder with that name already exists');
ensureParentDir($target);
if (!move_uploaded_file($file['tmp_name'], $target)) fail('Failed to save file', 500);
chmod($target, 0644);
touchDoc((int)$doc['id']);

jsonOut(['ok' => true, 'filename' => $filename]);
