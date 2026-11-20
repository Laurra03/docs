<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['document_id'] ?? 0), 'edit');

$filename = safeRelPath($_POST['filename'] ?? '', true);
if ($filename === null) fail('Invalid filename - use letters, digits, ".", "_" and "-" only');
if (strpos(basename($filename), '.') === false) $filename .= '.typ';
[$filename, $target] = docPath($doc, $filename, true);

if (is_dir($target)) fail('A folder with that name already exists');
if (!file_exists($target)) writeFileAtomic($target, '');

jsonOut(['ok' => true, 'id' => $filename, 'filename' => $filename]);
