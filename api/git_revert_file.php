<?php
require __DIR__ . '/git_helpers.php';
apiInit();
[$doc, $dir] = requireGitDoc();
[$rel, $target] = docPath($doc, $_POST['path'] ?? '');

// Untracked files (??) are simply deleted; tracked ones are restored from HEAD
[, $out] = runGit(['status', '--porcelain', '-z', '--', $rel], $dir);
$xy = substr($out, 0, 2);

if ($xy === '??') {
    if (is_file($target)) unlink($target);
} elseif ($xy !== '') {
    // Unstage first so files that were only `git add`ed are reverted too
    runGit(['reset', '-q', 'HEAD', '--', $rel], $dir);
    [, $after] = runGit(['status', '--porcelain', '-z', '--', $rel], $dir);
    if (substr($after, 0, 2) === '??') {
        if (is_file($target)) unlink($target);
    } else {
        [$code,, $err] = runGit(['checkout', 'HEAD', '--', $rel], $dir);
        if ($code !== 0) fail(trim($err) ?: 'Revert failed', 200);
    }
}
touchDoc((int)$doc['id']);

jsonOut(['ok' => true]);
