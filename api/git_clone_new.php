<?php
require __DIR__ . '/git_helpers.php';
apiInit();
$user = requireUser();

$remoteUrl = trim((string)($_POST['remote_url'] ?? ''));
$branch    = cleanBranch($_POST['branch'] ?? '', 'main');
if ($remoteUrl === '') fail('Remote URL required');
if (!validRemoteUrl($remoteUrl)) fail('Remote URL must be an https://, ssh:// or git@host: address');

// Derive a title from the URL if not provided
$guess = preg_replace('/\.git$/i', '', basename(preg_replace('#^.*[:/]#', '', rtrim($remoteUrl, '/'))));
$title = cleanTitle($_POST['title'] ?? '', $guess !== '' ? $guess : 'Git Project');

dbExec("INSERT INTO typst_documents (owner, title) VALUES (?, ?)", "ss", $user['sub'], $title);
$id = (int)db()->insert_id;
if (!$id) fail('Could not create document', 500);

$dir = docDir($id);
mkdir($dir, 0775, true);
$env = gitEnv($user);

// A failed clone must not leave an empty document behind in the library.
$abort = function (string $msg) use ($id, $dir): never {
    rrmdir($dir);
    dbExec("DELETE FROM typst_documents WHERE id=?", "i", $id);
    fail($msg, 200);
};

[$code,, $err] = runGit(['init'], $dir);
if ($code !== 0) $abort('git init failed: ' . trim($err));

[$code,, $err] = runGit(['remote', 'add', 'origin', $remoteUrl], $dir);
if ($code !== 0) $abort('Invalid remote URL: ' . trim($err));

[$code, $out, $err] = runGit(['fetch', '--depth=50', 'origin'], $dir, $env);
if ($code !== 0) $abort('Could not fetch from remote: ' . trim($err ?: $out));

[$code,, $err] = runGit(['checkout', '-b', $branch, '--track', 'origin/' . $branch], $dir);
if ($code !== 0) {
    $alt = $branch === 'main' ? 'master' : 'main';
    [$code2] = runGit(['checkout', '-b', $alt, '--track', 'origin/' . $alt], $dir);
    if ($code2 !== 0) $abort("Branch '$branch' not found. " . trim($err));
}

runGit(['submodule', 'update', '--init', '--recursive'], $dir, $env);

if (!file_exists($dir . '/main.typ')) file_put_contents($dir . '/main.typ', '');

jsonOut(['ok' => true, 'id' => $id]);
