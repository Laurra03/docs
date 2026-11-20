<?php
require __DIR__ . '/git_helpers.php';
apiInit();
[$doc, $dir] = requireGitDoc();

$branch = cleanBranch($_POST['branch'] ?? '');
if ($branch === '') fail('Invalid branch name');

if (!empty($_POST['create'])) {
    [$code,, $err] = runGit(['checkout', '-b', $branch], $dir);
} else {
    // Try local first, then a tracking branch for the remote one
    [$code,, $err] = runGit(['checkout', $branch, '--'], $dir);
    if ($code !== 0) {
        [$code2,, $err2] = runGit(['checkout', '-b', $branch, '--track', 'origin/' . $branch], $dir);
        if ($code2 === 0) $code = 0;
    }
}
if ($code !== 0) fail(trim($err) ?: 'Could not switch branch', 200);
touchDoc((int)$doc['id']);

jsonOut(['ok' => true, 'branch' => $branch]);
