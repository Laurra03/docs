<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['id'] ?? 0), 'edit');

if (!isset($_POST['content']) || !is_string($_POST['content'])) fail('Missing content');

// Only the owner may change the title; share-link editors keep the existing one.
$title = $doc['access'] === 'owner' ? cleanTitle($_POST['title'] ?? '', $doc['title']) : $doc['title'];

writeFileAtomic($doc['dir'] . '/main.typ', $_POST['content']);
dbExec("UPDATE typst_documents SET title=?, updated_at=NOW() WHERE id=?", "si", $title, $doc['id']);

jsonOut(['ok' => true]);
