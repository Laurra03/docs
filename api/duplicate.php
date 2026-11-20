<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc  = requireDoc((int)($_POST['id'] ?? 0), 'owner');

$newTitle = cleanTitle($doc['title'] . ' (copy)');
dbExec("INSERT INTO typst_documents (owner, title) VALUES (?, ?)", "ss", $doc['owner'], $newTitle);
$newId = (int)db()->insert_id;
if (!$newId) fail('Could not create document', 500);

rcopy($doc['dir'], docDir($newId));

jsonOut(['ok' => true, 'id' => $newId]);
