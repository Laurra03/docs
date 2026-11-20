<?php
require __DIR__ . '/git_helpers.php';
apiInit();
[, $dir] = requireGitDoc(false);
if (!is_dir($dir . '/.git')) jsonOut(['ok' => true, 'commits' => []]);

[, $out] = runGit(['log', '--pretty=format:%h%x09%an%x09%ar%x09%s', '-30'], $dir);
$commits = [];
foreach (explode("\n", trim($out)) as $line) {
    $p = explode("\t", $line, 4);
    if (count($p) === 4) $commits[] = ['hash' => $p[0], 'author' => $p[1], 'date' => $p[2], 'subject' => $p[3]];
}

jsonOut(['ok' => true, 'commits' => $commits]);
