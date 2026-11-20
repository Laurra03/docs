<?php
// Compile a document to PDF with a persistent `typst watch` process per
// document (incremental compiles are much faster than cold ones).
//
// Workspace layout under /tmp/typst_proj_{id}/:
//   src/            mirror of the project: symlinks to data/{id}/ plus the
//                   unsaved editor buffers POSTed with this request
//   out.pdf, watch.{pid,log,state}, lock, last_used
// Idle workspaces are reaped by docker/reaper.sh.
require __DIR__ . '/../lib.php';
apiInit();
$doc = requireDoc((int)($_POST['id'] ?? 0), 'view');
$id  = (int)$doc['id'];

$content = is_string($_POST['content'] ?? null) ? $_POST['content'] : '';
$entry   = safeRelPath($_POST['entry'] ?? 'main.typ') ?? 'main.typ';
$extra   = json_decode(is_string($_POST['files'] ?? null) ? $_POST['files'] : '[]', true);
if (!is_array($extra)) $extra = [];

$root = compileRoot($id);
$src  = "$root/src";
if (!is_dir($src) && !mkdir($src, 0700, true) && !is_dir($src)) fail('Could not create workspace', 500);

// One compile at a time per document: concurrent requests would race on the
// source mirror and on the watcher's log.
// 'e' = close-on-exec: otherwise the watcher inherits the descriptor and holds
// the lock for as long as it lives.
$lock = fopen("$root/lock", 'ce');
if (!$lock || !flock($lock, LOCK_EX)) fail('Could not lock workspace', 500);
touch("$root/last_used");

// ── Plan the mirror ──────────────────────────────────────────────────────────
// $buffers: files whose content comes from the request (unsaved editor state).
$buffers = [$entry => $content];
foreach ($extra as $f) {
    if (!is_array($f)) continue;
    $name = safeRelPath($f['filename'] ?? '');
    if ($name === null || $name === $entry) continue;
    $buffers[$name] = is_string($f['content'] ?? null) ? $f['content'] : '';
}
$projectFiles = listProjectFiles($doc['dir']);
$fontDirs = [];
foreach ($projectFiles as $rel) {
    if (in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), ['ttf', 'otf', 'ttc', 'otc'], true)) {
        $fontDirs[dirname("$src/$rel")] = true;
    }
}
ksort($fontDirs);

// ── Watcher state ────────────────────────────────────────────────────────────
$inFile    = "$src/$entry";
$outFile   = "$root/out.pdf";
$pidFile   = "$root/watch.pid";
$logFile   = "$root/watch.log";
$stateFile = "$root/watch.state";

$fontArgs = '';
foreach (array_keys($fontDirs) as $d) $fontArgs .= ' --font-path ' . escapeshellarg($d);
$watchCmd = '/bin/typst watch --root ' . escapeshellarg($src) . $fontArgs
          . ' ' . escapeshellarg($inFile) . ' ' . escapeshellarg($outFile);

$watchPid   = is_file($pidFile) ? (int)trim(file_get_contents($pidFile)) : 0;
$watchAlive = watcherAlive($watchPid);
$restart    = !$watchAlive
           || !is_file($stateFile) || file_get_contents($stateFile) !== $watchCmd
           || !is_file($logFile) || filesize($logFile) > 5 * 1024 * 1024; // keep the log bounded

if ($restart) {
    // Stop the old watcher before touching the mirror so it cannot race us.
    if ($watchAlive) killWatcher($watchPid);
    $logPos = 0;
} else {
    // Remember the log position BEFORE changing any file: everything the
    // watcher prints from here on belongs to this request.
    $logPos = filesize($logFile);
}

// ── Mirror the project into src/ ─────────────────────────────────────────────
$expected = [];
foreach ($projectFiles as $rel) {
    $expected[$rel] = true;
    if (isset($buffers[$rel])) continue;
    $dest   = "$src/$rel";
    $target = $doc['dir'] . '/' . $rel;
    if (is_link($dest) && readlink($dest) === $target) continue;
    if (is_dir($dest) && !is_link($dest)) rrmdir($dest); else @unlink($dest);
    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0700, true);
    symlink($target, $dest);
}
foreach ($buffers as $rel => $text) {
    $expected[$rel] = true;
    $dest = "$src/$rel";
    if (is_link($dest)) unlink($dest);
    elseif (is_dir($dest)) rrmdir($dest);
    if (!is_dir(dirname($dest))) {
        // A stale file may sit where a folder is now needed.
        for ($d = dirname($dest); $d !== $src && !is_dir($d); $d = dirname($d)) {
            if (is_file($d) || is_link($d)) unlink($d);
        }
        mkdir(dirname($dest), 0700, true);
    }
    // Skip identical writes so the watcher only re-reads what changed. The
    // entry file is the exception: typst recompiles whenever it is rewritten,
    // even with identical content, which guarantees a result for this request.
    if ($rel !== $entry && (!is_file($dest) || file_get_contents($dest) !== $text)) {
        file_put_contents($dest, $text);
    }
}
// Drop anything left over from files that were since deleted or renamed.
$prune = function (string $abs, string $rel) use (&$prune, $expected): bool {
    $empty = true;
    foreach (scandir($abs) as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = "$abs/$e"; $r = $rel === '' ? $e : "$rel/$e";
        if (is_dir($p) && !is_link($p)) {
            if ($prune($p, $r)) @rmdir($p); else $empty = false;
        } elseif (!isset($expected[$r])) {
            @unlink($p);
        } else {
            $empty = false;
        }
    }
    return $empty;
};
$prune($src, '');

if (!is_dir(dirname($inFile))) mkdir(dirname($inFile), 0700, true); // pruned above if it was empty
if ($restart) {
    file_put_contents($inFile, $content);
    @unlink($outFile);
    file_put_contents($logFile, '');
    // Run from src/ so diagnostics name files relative to the project. The
    // subshell exec()s typst, so $! is the watcher's own pid.
    $watchPid = (int)trim((string)shell_exec('(cd ' . escapeshellarg($src) . ' && exec nohup ' . $watchCmd . ') >' . escapeshellarg($logFile) . ' 2>&1 </dev/null & echo $!'));
    file_put_contents($pidFile, (string)$watchPid);
    file_put_contents($stateFile, $watchCmd);
} else {
    file_put_contents($inFile, $content); // written last: triggers the compile
}

// ── Wait for the result ──────────────────────────────────────────────────────
// First compile after a (re)start may download packages, so allow longer.
$timeout  = $restart ? 60.0 : 20.0;
$deadline = microtime(true) + $timeout;
$status   = null; // 'ok' | 'errors'
$log      = '';
$stripAnsi = fn(string $t): string => preg_replace('/\x1b\[[0-9;]*m/', '', $t);
while (microtime(true) < $deadline) {
    usleep(20000);
    $log = (string)@file_get_contents($logFile, false, null, $logPos);
    if ($log !== '') {
        // Several file writes can cause more than one compile; only the last
        // status line counts, and only once the watcher is idle again.
        $done = strrpos($log, '] compiled ');
        $busy = strrpos($log, '] compiling');
        if ($done !== false && ($busy === false || $busy < $done)) {
            usleep(40000); // let diagnostics flush / a follow-up compile start
            $again = (string)@file_get_contents($logFile, false, null, $logPos);
            if ($again === $log) {
                $status = strpos($log, 'compiled with errors', $done) === $done + 2 ? 'errors' : 'ok';
                break;
            }
            continue;
        }
    }
    if (!watcherAlive($watchPid)) break; // watcher died (e.g. typst missing)
}

// Server paths have no business in messages shown to (possibly anonymous) users.
$clean = fn(string $t): string => trim(str_replace([$src . '/', $src], '', $stripAnsi($t)));

if ($status === null) {
    // Runaway or crashed compile: kill it so it cannot burn CPU forever; the
    // next request starts a fresh watcher.
    $died = !watcherAlive($watchPid);
    killWatcher($watchPid);
    @unlink($pidFile);
    fail($died && $clean($log) !== '' ? $clean($log) : "Compilation timed out after {$timeout}s", 200);
}

$diagnostics = $clean((string)preg_replace('/^[^\n]*\n?/', '', substr($log, $done)));
if ($status !== 'ok') fail($diagnostics ?: 'Compilation failed', 200);
if (!is_file($outFile)) fail('Compiler produced no output', 500);

jsonOut(['ok' => true, 'pdf' => base64_encode(file_get_contents($outFile)), 'warnings' => $diagnostics]);
