<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['document_id'] ?? 0), 'edit');
[$rel, $path] = docPath($doc, $_POST['filename'] ?? '');

if ($rel === 'main.typ') fail('main.typ cannot be deleted');
if (is_file($path)) {
    unlink($path);
    pruneEmptyDirs(dirname($path), $doc['dir']);
    touchDoc((int)$doc['id']);
}

jsonOut(['ok' => true]);
