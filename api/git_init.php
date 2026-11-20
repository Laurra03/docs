<?php
require __DIR__ . '/git_helpers.php';
apiInit();
[$doc, $dir] = requireGitDoc(false);
if (is_dir($dir . '/.git')) fail('Git already initialized for this project');

$remoteUrl = trim((string)($_POST['remote_url'] ?? ''));
$branch    = cleanBranch($_POST['branch'] ?? '', 'main');
// Validate before creating anything, so a typo does not leave a half-set-up repo.
if ($remoteUrl !== '' && !validRemoteUrl($remoteUrl)) fail('Remote URL must be an https://, ssh:// or git@host: address');

$env    = gitEnv($doc['user']);
$output = [];

[$code, $out, $err] = runGit(['init', '-b', $branch], $dir);
if ($code !== 0) fail('git init failed: ' . trim($err), 200);
$output[] = trim($out ?: $err);

if ($remoteUrl !== '') {
    [$code,, $err] = runGit(['remote', 'add', 'origin', $remoteUrl], $dir);
    if ($code !== 0) { rrmdir($dir . '/.git'); fail('Could not add remote: ' . trim($err), 200); }

    [$code, $out, $err] = runGit(['fetch', 'origin'], $dir, $env);
    if ($code !== 0) {
        // Remote might be empty or unreachable - not fatal, the repo is still usable
        $output[] = 'Note: fetch failed (remote may be empty): ' . trim($err);
    } else {
        $output[] = trim($out ?: $err);
        [$code, $out, $err] = runGit(['checkout', '-b', $branch, '--track', 'origin/' . $branch], $dir);
        if ($code !== 0) [$code, $out, $err] = runGit(['checkout', $branch, '--'], $dir);
        if ($code !== 0) {
            $output[] = 'Note: could not checkout branch, staying on default: ' . trim($err);
        } else {
            $output[] = trim($out ?: $err);
            [, $smOut, $smErr] = runGit(['submodule', 'update', '--init', '--recursive'], $dir, $env);
            $output[] = trim($smOut . "\n" . $smErr);
        }
    }
}
touchDoc((int)$doc['id']);

jsonOut(['ok' => true, 'output' => implode("\n", array_filter($output))]);
