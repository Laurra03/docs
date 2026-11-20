<?php
require __DIR__ . '/git_helpers.php';
apiInit();
[$doc, $dir] = requireGitDoc();
$env = gitEnv($doc['user']);

// --autostash stashes any working-tree changes before the rebase and re-applies
// them afterward, so uncommitted edits are preserved across the sync.
[$code, $out, $err] = runGit(['pull', '--rebase', '--autostash'], $dir, $env);
$combined = trim($out . "\n" . $err);
touchDoc((int)$doc['id']);

if ($code !== 0) {
    $conflicts = stripos($combined, 'conflict') !== false;
    $errMsg    = trim($err ?: $out);
    fail($conflicts ? $errMsg : trim(explode("\n", $errMsg)[0]), 200, ['conflicts' => $conflicts, 'output' => $combined]);
}

[, $smOut, $smErr] = runGit(['submodule', 'update', '--init', '--recursive'], $dir, $env);
$combined = trim($combined . "\n" . trim($smOut . "\n" . $smErr));

jsonOut(['ok' => true, 'output' => $combined]);
