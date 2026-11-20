<?php
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['id'] ?? 0), 'owner', true);

if (!empty($_POST['permanent'])) {
    // Only documents already in the trash can be removed for good.
    if ($doc['deleted_at'] === null) fail('Move the document to the trash first');
    purgeDocument((int)$doc['id']);
} else {
    dbExec("UPDATE typst_documents SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL", "i", $doc['id']);
    destroyCompileWorkspace((int)$doc['id']);
}

jsonOut(['ok' => true]);
