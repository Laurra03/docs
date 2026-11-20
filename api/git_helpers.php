<?php
// Shared helpers for git API endpoints. All git endpoints are owner-only:
// they act with the owner's SSH key, so share-link guests must never reach them.
require_once __DIR__ . '/../lib.php';

function _gitKeyDir(string $userSub): string {
    return __DIR__ . '/../ssh_keys/' . hash('sha256', $userSub);
}

function _gitKeyPath(string $userSub): string {
    return _gitKeyDir($userSub) . '/id_ed25519';
}

// Generate key if missing; returns public key content.
function ensureSshKey(string $userSub): string {
    $dir = _gitKeyDir($userSub);
    $key = _gitKeyPath($userSub);
    $pub = $key . '.pub';
    if (!file_exists($pub)) {
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        @unlink($key);
        $host = parse_url((string)OIDC_REDIRECT_URI, PHP_URL_HOST) ?: 'docs';
        exec('ssh-keygen -q -t ed25519 -N "" -C ' . escapeshellarg('docs@' . $host) . ' -f ' . escapeshellarg($key) . ' 2>/dev/null');
        if (file_exists($key)) chmod($key, 0600);
    }
    return trim((string)@file_get_contents($pub));
}

// Returns the GIT_SSH_COMMAND env value for this user's key.
function gitSshCommand(string $userSub): string {
    return 'ssh -i ' . escapeshellarg(_gitKeyPath($userSub))
         . ' -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new'
         . ' -o UserKnownHostsFile=' . escapeshellarg(__DIR__ . '/../ssh_keys/known_hosts')
         . ' -o BatchMode=yes -o ConnectTimeout=15';
}

function gitEnv(array $user): array {
    return ['GIT_SSH_COMMAND' => gitSshCommand($user['sub'])];
}

// Accept only https/ssh remotes. Anything else (file paths, ext::, options
// starting with "-") could read local files or run commands.
function validRemoteUrl(string $url): bool {
    return strlen($url) <= 2000
        && !preg_match('/[\s\x00-\x1f\x7f]/', $url)
        && preg_match('#^(https://[^-/@][^/]*/|ssh://[^-/][^/]*/|git@[a-zA-Z0-9][a-zA-Z0-9.\-]*:)#i', $url) === 1;
}

function cleanBranch($branch, string $default = ''): string {
    $branch = is_string($branch) ? trim($branch) : '';
    if ($branch === '' || !preg_match('#^[a-zA-Z0-9_][a-zA-Z0-9_.\-/]*$#', $branch)) return $default;
    if (strpos($branch, '..') !== false || strpos($branch, '//') !== false
        || substr($branch, -1) === '/' || substr($branch, -5) === '.lock') return $default;
    return $branch;
}

// Hide credentials embedded in https remotes (https://user:token@host/...).
function redactRemote(string $url): string {
    return preg_replace('#^(https?://)[^/@]+@#i', '$1', $url);
}

// Run a git command in $cwd; returns [exitCode, stdout, stderr].
// Repository contents and .git/config are untrusted (cloned from anywhere),
// so features that execute configured commands are switched off.
function runGit(array $args, string $cwd, array $extraEnv = [], int $timeout = 240): array {
    $cmdParts = ['timeout', (string)$timeout, 'git',
        '-c', 'core.hooksPath=/dev/null',
        '-c', 'core.symlinks=false',      // check symlinks out as plain files
        '-c', 'core.fsmonitor=false',
        '-c', 'protocol.ext.allow=never',
        '-c', 'protocol.file.allow=never',
        '-c', 'credential.helper=',
        '-c', 'commit.gpgsign=false',
        '-c', 'safe.directory=*',
    ];
    foreach ($args as $a) $cmdParts[] = (string)$a;
    $cmd = implode(' ', array_map('escapeshellarg', $cmdParts));

    $env = array_merge([
        'HOME'                      => '/tmp',
        'GIT_TERMINAL_PROMPT'       => '0',
        'GIT_CONFIG_NOSYSTEM'       => '1',
        'GIT_EDITOR'                => 'true',
        'GIT_PAGER'                 => 'cat',
        'GIT_LITERAL_PATHSPECS'     => '1',
        'GIT_HTTP_LOW_SPEED_LIMIT'  => '1000',
        'GIT_HTTP_LOW_SPEED_TIME'   => '30',
        'LC_ALL'                    => 'C.UTF-8',
        'PATH'                      => '/usr/local/bin:/usr/bin:/bin',
    ], $extraEnv);

    // stderr goes to a file: reading two pipes one after the other deadlocks
    // as soon as git fills the one we are not reading yet.
    $errFile = tempnam(sys_get_temp_dir(), 'git_err_');
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, $cwd, $env);
    if (!is_resource($proc)) { @unlink($errFile); return [-1, '', 'Failed to start git process']; }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code   = proc_close($proc);
    $stderr = (string)@file_get_contents($errFile);
    @unlink($errFile);
    if ($code === 124) $stderr = trim($stderr . "\nGit timed out after {$timeout}s");
    return [$code, (string)$stdout, $stderr];
}

// Owner-only access to a document's working tree. Returns [$doc, $dir].
function requireGitDoc(bool $needRepo = true): array {
    $doc = requireDoc((int)($_POST['id'] ?? 0), 'owner');
    if ($needRepo && !is_dir($doc['dir'] . '/.git')) fail('Git not initialized');
    return [$doc, $doc['dir']];
}

// Get git user settings from DB, falling back to session user info.
function getGitAuthor(array $user): array {
    $row   = dbRow("SELECT commit_name, commit_email FROM git_user_settings WHERE user_sub=?", "s", $user['sub']);
    $name  = ($row['commit_name']  ?? '') ?: ($user['name']  ?: 'Docs User');
    $email = ($row['commit_email'] ?? '') ?: ($user['email'] ?: '');
    if ($email === '') $email = 'noreply@' . (parse_url((string)OIDC_REDIRECT_URI, PHP_URL_HOST) ?: 'localhost');
    return ['name' => $name, 'email' => $email];
}
