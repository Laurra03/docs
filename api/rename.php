<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['id'] ?? 0), 'owner');

$title = cleanTitle($_POST['title'] ?? '');
dbExec("UPDATE typst_documents SET title=?, updated_at=NOW() WHERE id=?", "si", $title, $doc['id']);

jsonOut(['ok' => true, 'title' => $title]);
