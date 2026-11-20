<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['document_id'] ?? 0), 'owner');

dbExec("DELETE FROM document_shares WHERE document_id=?", "i", $doc['id']);

jsonOut(['ok' => true]);
