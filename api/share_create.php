<?php
// Create the share link, change its access level, or (regenerate=1) replace
// the token so the old link stops working.
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['document_id'] ?? 0), 'owner');

$access     = ($_POST['access'] ?? 'view') === 'edit' ? 'edit' : 'view';
$regenerate = !empty($_POST['regenerate']);

$existing = dbRow("SELECT token FROM document_shares WHERE document_id=?", "i", $doc['id']);

if ($existing && !$regenerate) {
    $token = $existing['token'];
    dbExec("UPDATE document_shares SET access=? WHERE document_id=?", "si", $access, $doc['id']);
} else {
    $token = bin2hex(random_bytes(32));
    dbExec("DELETE FROM document_shares WHERE document_id=?", "i", $doc['id']);
    dbExec("INSERT INTO document_shares (document_id, token, access) VALUES (?,?,?)", "iss", $doc['id'], $token, $access);
}

jsonOut(['ok' => true, 'token' => $token, 'access' => $access]);
