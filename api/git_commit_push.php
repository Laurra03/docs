<?php
require __DIR__ . '/git_helpers.php';
apiInit();
[$doc, $dir] = requireGitDoc();

$message = trim((string)($_POST['message'] ?? ''));
if ($message === '') fail('Commit message required');

$author = getGitAuthor($doc['user']);
$output = [];

// Stage the selected files (JSON array of paths), or everything if none given
$files   = [];
$decoded = json_decode((string)($_POST['files'] ?? ''), true);
if (is_array($decoded)) {
    foreach ($decoded as $f) {
        $rel = safeRelPath($f);
        if ($rel !== null) $files[] = $rel;
    }
}
[$code,, $err] = $files
    ? runGit(array_merge(['add', '-A', '--'], $files), $dir)
    : runGit(['add', '-A'], $dir);
if ($code !== 0) fail('git add failed: ' . trim($err), 200);

[$code, $out, $err] = runGit([
    '-c', 'user.name=' . $author['name'],
    '-c', 'user.email=' . $author['email'],
    'commit', '-m', $message,
], $dir);

if ($code !== 0) {
    $combined = trim($out . "\n" . $err);
    // "nothing to commit" is exit 1 but not a real error
    $msg = strpos($combined, 'nothing to commit') !== false || strpos($combined, 'nothing added to commit') !== false
        ? 'Nothing to commit - working tree is clean.'
        : trim($err ?: $out);
    fail($msg, 200, ['output' => $combined]);
}
$output[] = trim($out);

// Push if remote exists
[, $remote] = runGit(['remote', 'get-url', 'origin'], $dir);
if (trim($remote) !== '') {
    [$code, $out, $err] = runGit(['push', '-u', 'origin', 'HEAD'], $dir, gitEnv($doc['user']));
    $output[] = trim($out . "\n" . $err);
    if ($code !== 0) {
        fail('Commit succeeded but push failed: ' . trim($err), 200, ['output' => implode("\n", array_filter($output))]);
    }
}

jsonOut(['ok' => true, 'output' => implode("\n", array_filter($output))]);
