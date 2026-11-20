<?php
require __DIR__ . '/git_helpers.php';
apiInit();
[$doc, $dir] = requireGitDoc(false);
if (!is_dir($dir . '/.git')) jsonOut(['ok' => true, 'changes' => []]);

$filename = safeRelPath($_POST['filename'] ?? '');
if ($filename === null) fail('Invalid filename');

// --unified=0 gives no context lines - only changed lines appear in the output.
[, $out] = runGit(['diff', '--no-ext-diff', '--no-textconv', '--unified=0', 'HEAD', '--', $filename], $dir);

jsonOut(['ok' => true, 'changes' => parseDiff($out)]);

function parseDiff(string $diff): array {
    $result  = [];
    $newLine = 0;
    $inHunk  = false; // skip file headers (---, +++) before the first @@ line

    foreach (explode("\n", $diff) as $line) {
        if (substr($line, 0, 2) === '@@') {
            $inHunk = true;
            if (preg_match('/\+(\d+)(?:,(\d+))?/', $line, $m)) {
                // A pure deletion reports the line *before* the gap ("+N,0")
                $newLine = (int)$m[1] + (($m[2] ?? '') === '0' ? 1 : 0);
            }
            continue;
        }
        if (!$inHunk || $line === '' || $line[0] === '\\') continue;
        $last = end($result);

        if ($line[0] === '+') {
            if ($last && $last['line'] === $newLine && $last['type'] === 'deleted') {
                // deletion immediately followed by addition at same position → modified
                $result[key($result)]['type'] = 'modified';
            } else {
                $result[] = ['type' => 'added', 'line' => $newLine];
            }
            $newLine++;
        } elseif ($line[0] === '-') {
            // multiple consecutive deletions at the same position → keep one entry
            if (!$last || $last['line'] !== $newLine) $result[] = ['type' => 'deleted', 'line' => $newLine];
        }
    }
    return $result;
}
