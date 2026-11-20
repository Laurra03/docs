<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['document_id'] ?? 0), 'edit');
[, $path] = docPath($doc, $_POST['filename'] ?? '');

if (!isset($_POST['content']) || !is_string($_POST['content'])) fail('Missing content');

writeFileAtomic($path, $_POST['content']);
touchDoc((int)$doc['id']);

jsonOut(['ok' => true]);
