<?php
require __DIR__ . '/git_helpers.php';
apiInit();
[$doc, $dir] = requireGitDoc();
$force  = !empty($_POST['force']); // true = discard local changes, take remote
$env    = gitEnv($doc['user']);
$output = [];

$inRebase = is_dir($dir . '/.git/rebase-merge')
         || is_dir($dir . '/.git/rebase-apply');

if ($force) {
    // Discard local changes and align with remote HEAD
    if ($inRebase) {
        [$code,, $err] = runGit(['rebase', '--abort'], $dir, $env);
        if ($code !== 0) {
            fail('Could not abort rebase: ' . trim($err), 200);
        }
    }
    // Detect tracking branch
    [, $trackingBranch] = runGit(['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'], $dir);
    $trackingBranch = trim($trackingBranch);
    if (!$trackingBranch) {
        fail('No remote tracking branch configured — cannot force sync.', 200);
    }
    [$code,, $err] = runGit(['reset', '--hard', $trackingBranch], $dir);
    if ($code !== 0) {
        fail('Could not reset to remote: ' . trim($err), 200);
    }
    $output[] = 'Reset to ' . $trackingBranch;
    // Drop any leftover stash from the failed autostash
    runGit(['stash', 'drop'], $dir);

} else {
    // Abort: restore the state from before the sync started
    if ($inRebase) {
        // git rebase --abort restores both the branch and the autostash
        [$code,, $err] = runGit(['rebase', '--abort'], $dir, $env);
        if ($code !== 0) {
            fail('Could not abort rebase: ' . trim($err), 200);
        }
        $output[] = 'Rebase aborted — your changes are restored.';
    } else {
        // Stash-pop failed after a successful rebase; working tree has conflict markers
        // Discard the conflict markers and restore the stash manually
        [$code,, $err] = runGit(['checkout', '.'], $dir);
        if ($code !== 0) {
            fail('Could not clean working tree: ' . trim($err), 200);
        }
        [$code, $smOut, $smErr] = runGit(['stash', 'pop'], $dir);
        $output[] = trim($smOut . "\n" . $smErr);
    }
}

touchDoc((int)$doc['id']);

jsonOut(['ok' => true, 'output' => implode("\n", array_filter($output))]);
