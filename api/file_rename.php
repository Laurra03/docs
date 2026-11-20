<?php
// Rename/move a file (or folder) inside a project. Shared by the .typ file
// list and the asset list; image_move.php is a thin alias with other param names.
require_once __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['document_id'] ?? 0), 'edit');

[$old, $oldPath] = docPath($doc, $_POST['filename'] ?? $_POST['old_filename'] ?? '');
[$new, $newPath] = docPath($doc, $_POST['new_filename'] ?? '', true);

if ($old === $new)          fail('Invalid filename');
if ($old === 'main.typ')    fail('main.typ cannot be renamed');
if (!file_exists($oldPath)) fail('File not found', 404);
if (file_exists($newPath))  fail('A file with that name already exists');
if (is_dir($oldPath) && strpos($new . '/', $old . '/') === 0) fail('Cannot move a folder into itself');

ensureParentDir($newPath);
if (!rename($oldPath, $newPath)) fail('Move failed', 500);
pruneEmptyDirs(dirname($oldPath), $doc['dir']);
touchDoc((int)$doc['id']);

jsonOut(['ok' => true, 'filename' => $new]);
