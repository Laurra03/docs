<?php
require __DIR__ . '/git_helpers.php';
apiInit();
[$doc, $dir] = requireGitDoc();
$env = gitEnv($doc['user']);

[$code, $out, $err] = runGit(['pull', '--ff-only'], $dir, $env);
$combined = trim($out . "\n" . $err);
if ($code !== 0) {
    // Trim verbose git help text - keep only the first line
    fail(trim(explode("\n", trim($err ?: $out))[0]), 200, ['output' => $combined]);
}

[, $smOut, $smErr] = runGit(['submodule', 'update', '--init', '--recursive'], $dir, $env);
$combined = trim($combined . "\n" . trim($smOut . "\n" . $smErr));
touchDoc((int)$doc['id']);

jsonOut(['ok' => true, 'output' => $combined]);
