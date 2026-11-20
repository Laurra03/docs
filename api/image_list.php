<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['document_id'] ?? 0), 'view');

$images = [];
foreach (listProjectFiles($doc['dir']) as $rel) {
    if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'typ') continue;
    $images[] = $rel;
}

jsonOut(['ok' => true, 'images' => $images]);
