<?php
require __DIR__ . '/../lib.php';
apiInit();
$user = requireUser();

$title = 'Untitled Document';
dbExec("INSERT INTO typst_documents (owner, title) VALUES (?, ?)", "ss", $user['sub'], $title);
$id = (int)db()->insert_id;
if (!$id) fail('Could not create document', 500);

writeFileAtomic(docDir($id) . '/main.typ', "= Hello, Typst!\n\nStart typing your document here.\n");

jsonOut(['ok' => true, 'id' => $id]);
