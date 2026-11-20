<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['document_id'] ?? 0), 'view');
[, $path] = docPath($doc, $_POST['filename'] ?? '');

if (!is_file($path)) fail('File not found', 404);

jsonOut(['ok' => true, 'content' => file_get_contents($path)]);
