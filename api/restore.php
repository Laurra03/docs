<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['id'] ?? 0), 'owner', true);

dbExec("UPDATE typst_documents SET deleted_at=NULL WHERE id=?", "i", $doc['id']);

jsonOut(['ok' => true]);
