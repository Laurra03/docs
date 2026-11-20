<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['id'] ?? 0), 'owner');

dbExec("DELETE FROM typst_documents WHERE id=?", "i", $doc['id']);
destroyCompileWorkspace((int)$doc['id']);
rrmdir($doc['dir']);

jsonOut(['ok' => true]);
