<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['document_id'] ?? 0), 'view');

$files = [];
foreach (listProjectFiles($doc['dir']) as $rel) {
    if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'typ' || $rel === 'main.typ') continue;
    $files[] = ['id' => $rel, 'filename' => $rel];
}

jsonOut(['ok' => true, 'files' => $files]);
