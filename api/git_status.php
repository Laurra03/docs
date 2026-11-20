<?php
require __DIR__ . '/git_helpers.php';
apiInit();
[$doc, $dir] = requireGitDoc(false);
if (!is_dir($dir . '/.git')) jsonOut(['ok' => true, 'initialized' => false]);

[, $branch] = runGit(['rev-parse', '--abbrev-ref', 'HEAD'], $dir);
$branch = trim($branch) ?: 'HEAD';

[, $remote] = runGit(['remote', 'get-url', 'origin'], $dir);
$remote = trim($remote) !== '' ? redactRemote(trim($remote)) : null;

// -z: NUL-separated and unquoted, so names with spaces or unicode survive.
// A rename/copy entry is followed by a second field holding the old path.
[, $statusOut] = runGit(['status', '--porcelain', '-z', '-uall'], $dir);
$changes = [];
$fields  = explode("\0", $statusOut);
for ($i = 0; $i < count($fields); $i++) {
    $entry = $fields[$i];
    if (strlen($entry) < 4) continue;
    $xy   = substr($entry, 0, 2);
    $path = substr($entry, 3);
    if ($xy[0] === 'R' || $xy[0] === 'C' || $xy[1] === 'R' || $xy[1] === 'C') $i++; // skip the old path
    if ($xy === '??')                             $status = '?';
    elseif ($xy[0] !== ' ' && $xy[0] !== '?')     $status = $xy[0]; // staged
    else                                          $status = $xy[1]; // unstaged
    $changes[] = ['status' => $status, 'path' => $path, 'xy' => $xy];
}

// Ahead/behind relative to tracking branch
$ahead = $behind = null;
[$abCode, $abOut] = runGit(['rev-list', '--left-right', '--count', 'HEAD...@{u}'], $dir);
if ($abCode === 0) {
    $parts  = preg_split('/\s+/', trim($abOut));
    $ahead  = (int)($parts[0] ?? 0);
    $behind = (int)($parts[1] ?? 0);
}

jsonOut([
    'ok'          => true,
    'initialized' => true,
    'branch'      => $branch,
    'remote'      => $remote,
    'changes'     => $changes,
    'ahead'       => $ahead,
    'behind'      => $behind,
]);
