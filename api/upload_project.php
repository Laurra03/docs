<?php
require __DIR__ . '/../lib.php';
apiInit();
$user = requireUser();

$zipFile = $_FILES['zip'] ?? null;
if (!$zipFile || is_array($zipFile['error'])) fail('Upload error');
if ($zipFile['error'] === UPLOAD_ERR_INI_SIZE || $zipFile['error'] === UPLOAD_ERR_FORM_SIZE) {
    fail('Archive is too large (limit ' . ini_get('upload_max_filesize') . ')', 413);
}
if ($zipFile['error'] !== UPLOAD_ERR_OK) fail('Upload error');

$title = cleanTitle($_POST['title'] ?? '', 'Imported Project');

$zip = new ZipArchive();
if ($zip->open($zipFile['tmp_name'], ZipArchive::RDONLY) !== true) fail('Invalid zip file');

// First pass: decide what to extract, using only the archive's index so a
// zip bomb is rejected before anything is decompressed.
$entries = []; // zip index => raw name
$total   = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if (!$stat || substr($stat['name'], -1) === '/') continue;
    $entries[$i] = str_replace('\\', '/', $stat['name']);
    $total += $stat['size'];
    if (count($entries) > MAX_PROJECT_FILES) fail('Archive contains too many files (limit ' . MAX_PROJECT_FILES . ')', 413);
    if ($total > MAX_PROJECT_BYTES) fail('Archive is too large when unpacked', 413);
}

// Strip a common top-level folder if every file shares one.
$prefix = '';
if ($entries) {
    $first = reset($entries);
    $slash = strpos($first, '/');
    if ($slash !== false) {
        $candidate = substr($first, 0, $slash + 1);
        $prefix = $candidate;
        foreach ($entries as $name) {
            if (strpos($name, $candidate) !== 0) { $prefix = ''; break; }
        }
    }
}

$files = []; // zip index => safe relative path
foreach ($entries as $i => $name) {
    $rel = safeRelPath(substr($name, strlen($prefix)));
    if ($rel === null) continue;
    // Skip hidden files/dirs and macOS resource forks
    if (preg_match('#(^|/)(\.|__MACOSX/)#', $rel)) continue;
    $files[$i] = $rel;
}
if (!$files) fail('No files found in archive');

dbExec("INSERT INTO typst_documents (owner, title) VALUES (?, ?)", "ss", $user['sub'], $title);
$docId = (int)db()->insert_id;
if (!$docId) fail('Could not create document', 500);
$dir = docDir($docId);
mkdir($dir, 0775, true);

// Second pass: stream each entry to disk, enforcing the cap on real bytes
// (the sizes in the index are attacker-controlled).
$written = 0;
foreach ($files as $i => $rel) {
    $target = $dir . '/' . $rel;
    if (is_dir($target)) continue;
    if (!is_dir(dirname($target))) @mkdir(dirname($target), 0775, true);
    $in  = $zip->getStream($zip->getNameIndex($i));
    $out = $in ? @fopen($target, 'wb') : false;
    if (!$in || !$out) { if ($in) fclose($in); continue; }
    while (!feof($in)) {
        $chunk = fread($in, 1 << 16);
        if ($chunk === false) break;
        $written += strlen($chunk);
        if ($written > MAX_PROJECT_BYTES) {
            fclose($in); fclose($out);
            rrmdir($dir);
            dbExec("DELETE FROM typst_documents WHERE id=?", "i", $docId);
            fail('Archive is too large when unpacked', 413);
        }
        fwrite($out, $chunk);
    }
    fclose($in); fclose($out);
}
$zip->close();

// The editor always opens main.typ. If the archive has none, create one that
// includes the most likely entry point (a root-level .typ file, else the first found).
if (!is_file($dir . '/main.typ')) {
    $typ  = array_values(array_filter(listProjectFiles($dir), fn($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'typ'));
    $root = array_values(array_filter($typ, fn($f) => strpos($f, '/') === false));
    $entry = $root[0] ?? $typ[0] ?? null;
    file_put_contents($dir . '/main.typ', $entry !== null ? '#include "' . $entry . "\"\n" : "= New Document\n");
}

jsonOut(['ok' => true, 'id' => $docId]);
