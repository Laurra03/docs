<?php
// Shared helpers: DB access, JSON responses, document access control and
// safe path handling. Every API endpoint should go through requireDoc() or
// requireUser() rather than doing its own ownership query.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth/session.php';

// escapeshellarg() silently drops non-ASCII bytes under the C locale.
setlocale(LC_CTYPE, 'C.UTF-8', 'en_US.UTF-8');

const DATA_ROOT        = __DIR__ . '/data';
const JSON_FLAGS       = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE;
const MAX_PROJECT_BYTES = 256 * 1024 * 1024; // uncompressed size cap for ZIP imports
const MAX_PROJECT_FILES = 5000;

// ── Responses ────────────────────────────────────────────────────────────────

function jsonOut(array $data, int $status = 200): never {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_FLAGS);
    exit;
}

function fail(string $error, int $status = 400, array $extra = []): never {
    jsonOut(['ok' => false, 'error' => $error] + $extra, $status);
}

// Call at the top of JSON endpoints: uncaught errors become a JSON 500 instead
// of an HTML stack trace, and cross-site POSTs are rejected.
function apiInit(bool $postOnly = true): void {
    header('Content-Type: application/json');
    set_exception_handler(function (Throwable $e) {
        error_log('API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        fail('Server error', 500);
    });
    if ($postOnly && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('POST required', 405);
    checkOrigin();
}

// Defence in depth on top of SameSite=Lax: a browser-sent Origin header must
// match either the host we were reached on or the configured public origin.
function checkOrigin(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $originHost = parse_url($origin, PHP_URL_HOST);
    $allowed = array_filter([
        parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST),
        parse_url((string)OIDC_REDIRECT_URI, PHP_URL_HOST),
    ]);
    if (!$originHost || !in_array(strtolower($originHost), array_map('strtolower', $allowed), true)) {
        fail('Cross-origin request rejected', 403);
    }
}

// ── Database ─────────────────────────────────────────────────────────────────

function db(): mysqli {
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $db->set_charset('utf8mb4');
    }
    return $db;
}

function dbRow(string $sql, string $types = '', ...$params): ?array {
    $stmt = db()->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function dbExec(string $sql, string $types = '', ...$params): int {
    $stmt = db()->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

// ── Access control ───────────────────────────────────────────────────────────

function requestShareToken(): string {
    $t = $_POST['share_token'] ?? $_GET['share_token'] ?? '';
    return is_string($t) ? preg_replace('/[^a-f0-9]/', '', $t) : '';
}

function docDir(int $id): string {
    return DATA_ROOT . '/' . $id;
}

// Resolve what the current request may do with document $id.
// Returns null if the document does not exist or the caller has no access,
// otherwise ['id', 'title', 'owner', 'access' => 'owner'|'edit'|'view',
//            'user' => session user or null, 'token' => share token or null].
// A logged-in non-owner falls back to the share token, so share links also
// work for people who happen to have an account.
function resolveDocAccess(int $id, string $shareToken = '', bool $allowTrashed = false): ?array {
    if ($id <= 0) return null;
    $doc = dbRow("SELECT id, title, owner, deleted_at FROM typst_documents WHERE id=?", "i", $id);
    if (!$doc) return null;
    if ($doc['deleted_at'] !== null && !$allowTrashed) return null; // trashed docs are invisible to everyone

    $user = currentUser();
    if ($user && hash_equals($doc['owner'], (string)$user['sub'])) {
        return $doc + ['access' => 'owner', 'user' => $user, 'token' => null];
    }
    if ($shareToken !== '') {
        $share = dbRow("SELECT token, access FROM document_shares WHERE document_id=?", "i", $id);
        if ($share && hash_equals($share['token'], $shareToken)) {
            return $doc + ['access' => $share['access'] === 'edit' ? 'edit' : 'view', 'user' => $user, 'token' => $shareToken];
        }
    }
    return null;
}

// $need: 'view' (owner or any share link), 'edit' (owner or edit link),
// 'owner' (owner only — sharing, git, delete, duplicate, rename).
function requireDoc(int $id, string $need = 'view', bool $allowTrashed = false): array {
    $token = requestShareToken();
    $doc   = resolveDocAccess($id, $token, $allowTrashed);
    if (!$doc) {
        if (!currentUser() && $token === '') fail('unauthenticated', 401);
        fail('Not found', 404);
    }
    $rank = ['view' => 1, 'edit' => 2, 'owner' => 3];
    if ($rank[$doc['access']] < $rank[$need]) {
        fail($need === 'owner' ? 'Only the document owner can do this' : 'This link is view-only', 403);
    }
    $doc['dir'] = docDir((int)$doc['id']);
    if (!is_dir($doc['dir'])) mkdir($doc['dir'], 0775, true);
    return $doc;
}

// Session-only auth for endpoints that are not tied to an existing document.
function requireUser(): array {
    $user = currentUser();
    if (!$user) fail('unauthenticated', 401);
    return $user;
}

// ── Paths ────────────────────────────────────────────────────────────────────

// Normalise a user-supplied project-relative path. Returns null when the path
// is unsafe: empty, absolute, containing "."/".." segments, control characters
// or backslashes, or touching the project's .git directory (writing there
// would mean arbitrary command execution through git config and hooks).
// $strict additionally limits names to [A-Za-z0-9._-] for newly created files.
function safeRelPath($path, bool $strict = false): ?string {
    if (!is_string($path)) return null;
    $path = trim($path);
    $path = trim($path, '/');
    if ($path === '' || strlen($path) > 1024) return null;
    if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) return null;
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') return null;
        if (strcasecmp($part, '.git') === 0) return null;
        if (strlen($part) > 255) return null;
        if ($strict && !preg_match('/^[a-zA-Z0-9._\-]+$/', $part)) return null;
    }
    return $path;
}

// Absolute path for $rel inside $base, or null if any existing component is a
// symlink (a cloned repository may contain links pointing outside the project).
function pathInDir(string $base, string $rel): ?string {
    $cur = $base;
    foreach (explode('/', $rel) as $part) {
        $cur .= '/' . $part;
        if (is_link($cur)) return null;
    }
    return $cur;
}

// Validate + resolve a request-supplied path inside a document, or fail().
function docPath(array $doc, $rel, bool $strict = false): array {
    $clean = safeRelPath($rel, $strict);
    if ($clean === null) fail('Invalid filename');
    $abs = pathInDir($doc['dir'], $clean);
    if ($abs === null) fail('Invalid filename');
    return [$clean, $abs];
}

function ensureParentDir(string $file): void {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) fail('Could not create folder', 500);
}

// Write atomically so a crash or concurrent compile never sees a half-written file.
function writeFileAtomic(string $target, string $content): void {
    ensureParentDir($target);
    if (is_dir($target)) fail('A folder with that name already exists');
    $tmp = $target . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content) === false || !rename($tmp, $target)) {
        @unlink($tmp);
        fail('Could not write file', 500);
    }
}

// Remove now-empty parent folders up to (not including) $stop.
function pruneEmptyDirs(string $dir, string $stop): void {
    while ($dir !== $stop && strlen($dir) > strlen($stop) && is_dir($dir) && !is_link($dir)) {
        if (count(scandir($dir)) > 2 || !@rmdir($dir)) break;
        $dir = dirname($dir);
    }
}

function rrmdir(string $dir): void {
    if (is_link($dir) || !is_dir($dir)) { @unlink($dir); return; }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        rrmdir($dir . '/' . $entry);
    }
    @rmdir($dir);
}

// Recursive copy that never follows symlinks.
function rcopy(string $src, string $dst): void {
    if (is_link($src)) return;
    if (is_dir($src)) {
        if (!is_dir($dst)) mkdir($dst, 0775, true);
        foreach (scandir($src) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            rcopy($src . '/' . $entry, $dst . '/' . $entry);
        }
    } elseif (is_file($src)) {
        copy($src, $dst);
    }
}

// All regular files in a project as relative paths. Skips .git and symlinks.
function listProjectFiles(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $walk = function (string $abs, string $rel) use (&$walk, &$out) {
        foreach (scandir($abs) as $entry) {
            if ($entry === '.' || $entry === '..' || strcasecmp($entry, '.git') === 0) continue;
            $p = $abs . '/' . $entry;
            $r = $rel === '' ? $entry : $rel . '/' . $entry;
            if (is_link($p)) continue;
            if (is_dir($p)) $walk($p, $r);
            elseif (is_file($p)) $out[] = $r;
        }
    };
    $walk($dir, '');
    sort($out);
    return $out;
}

function touchDoc(int $id): void {
    dbExec("UPDATE typst_documents SET updated_at=NOW() WHERE id=?", "i", $id);
}

function cleanTitle($title, string $default = 'Untitled Document'): string {
    $title = is_string($title) ? trim(preg_replace('/[\x00-\x1f\x7f]/', ' ', $title)) : '';
    if ($title === '') return $default;
    return mb_substr($title, 0, 200);
}

// Permanently remove a document: row, files and compile workspace.
function purgeDocument(int $id): void {
    dbExec("DELETE FROM typst_documents WHERE id=?", "i", $id);
    destroyCompileWorkspace($id);
    rrmdir(docDir($id));
}

// ── Compile workspace ────────────────────────────────────────────────────────

function compileRoot(int $id): string {
    return sys_get_temp_dir() . '/typst_proj_' . $id;
}

// Stop any `typst watch` processes for a document and remove its workspace.
function destroyCompileWorkspace(int $id): void {
    $root = compileRoot($id);
    if (!is_dir($root)) return;
    killWatcher((int)trim((string)@file_get_contents($root . '/watch.pid')));
    rrmdir($root);
}

// True only for a live typst process (a zombie has an empty cmdline).
function watcherAlive(int $pid): bool {
    return $pid > 1 && strpos((string)@file_get_contents("/proc/$pid/cmdline"), 'typst') !== false;
}

// Only ever signal a process that really is a typst watcher.
function killWatcher(int $pid): void {
    if (!watcherAlive($pid)) return;
    if (function_exists('posix_kill')) posix_kill($pid, 9);
    else exec('kill -9 ' . $pid . ' 2>/dev/null');
}
