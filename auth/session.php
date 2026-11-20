<?php
require_once __DIR__ . '/../config.php';
// Session helpers. Pages call requireAuth(); JSON endpoints use requireDoc()
// or requireUser() from lib.php, which build on currentUser().

const SESSION_LIFETIME = 60 * 60 * 24 * 14; // 14 days

function _startSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => true,
        'httponly' => true,
    ]);
    session_start();
}

// Returns the logged-in user or null. The session is read once and then
// released: PHP holds an exclusive lock on the session file for as long as it
// is open, which would otherwise make a slow compile block every other request
// (autosave, file list, …) from the same browser.
function currentUser(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    if (empty($_COOKIE[session_name()])) return $user = null; // don't create sessions for anonymous API hits
    _startSession();
    $user = empty($_SESSION['user_sub']) ? null : [
        'sub'   => (string)$_SESSION['user_sub'],
        'name'  => (string)($_SESSION['user_name'] ?? ''),
        'email' => (string)($_SESSION['user_email'] ?? ''),
    ];
    session_write_close();
    return $user;
}

// For page requests: redirect to login (and come back here afterwards).
function requireAuth(): array {
    $user = currentUser();
    if ($user) return $user;
    redirectToLogin();
}

function redirectToLogin(): never {
    $returnTo = $_SERVER['REQUEST_URI'] ?? '/';
    $url = '/auth/login.php';
    if (isLocalPath($returnTo) && $returnTo !== '/') $url .= '?return_to=' . rawurlencode($returnTo);
    header('Location: ' . $url);
    exit;
}

// Only same-site absolute paths are valid post-login destinations.
function isLocalPath($path): bool {
    return is_string($path)
        && preg_match('#^/(?![/\\\\])[^\x00-\x1f\\\\]*$#', $path) === 1
        && strpos($path, '/auth/') !== 0;
}
