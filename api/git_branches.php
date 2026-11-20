<?php
require __DIR__ . '/git_helpers.php';
apiInit();
[, $dir] = requireGitDoc();

[, $current] = runGit(['rev-parse', '--abbrev-ref', 'HEAD'], $dir);
$current = trim($current);

// Local branches plus remote-tracking ones with the remote name stripped.
[, $out] = runGit(['for-each-ref', '--format=%(refname)', 'refs/heads', 'refs/remotes'], $dir);
$all = [];
foreach (explode("\n", trim($out)) as $ref) {
    if (preg_match('#^refs/heads/(.+)$#', $ref, $m))            $all[$m[1]] = true;
    elseif (preg_match('#^refs/remotes/[^/]+/(.+)$#', $ref, $m)) { if ($m[1] !== 'HEAD') $all[$m[1]] = true; }
}

jsonOut(['ok' => true, 'current' => $current, 'branches' => array_keys($all)]);
