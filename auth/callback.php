<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/session.php';
_startSession();

// ── State verification ────────────────────────────────────────────────────────
$expectedState = $_SESSION['oauth_state'] ?? '';
$verifier      = $_SESSION['oauth_verifier'] ?? '';
$returnTo      = $_SESSION['return_to'] ?? '/';
if (!is_string($_GET['state'] ?? null) || $expectedState === '' || !hash_equals($expectedState, $_GET['state'])) {
    http_response_code(400);
    die('OAuth state mismatch. <a href="/auth/login.php">Try again</a>.');
}
unset($_SESSION['oauth_state'], $_SESSION['oauth_verifier'], $_SESSION['return_to']);

if (!empty($_GET['error'])) {
    http_response_code(400);
    die('OIDC error: ' . htmlspecialchars((string)($_GET['error_description'] ?? $_GET['error']))
        . ' <a href="/auth/login.php">Try again</a>.');
}

if (empty($_GET['code']) || !is_string($_GET['code'])) {
    http_response_code(400);
    die('Missing authorization code.');
}

// ── Exchange code for tokens ──────────────────────────────────────────────────
$ch = curl_init(OIDC_TOKEN_ENDPOINT);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $_GET['code'],
        'redirect_uri'  => OIDC_REDIRECT_URI,
        'client_id'     => OIDC_CLIENT_ID,
        'client_secret' => OIDC_CLIENT_SECRET,
        'code_verifier' => $verifier,
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 10,
]);
$tokenRaw = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    die('Token endpoint unreachable: ' . htmlspecialchars($curlErr));
}

$token = json_decode($tokenRaw, true);
if (empty($token['access_token'])) {
    http_response_code(502);
    // Details go to the server log only - the raw response can contain provider internals.
    error_log('OIDC token exchange failed: ' . substr((string)$tokenRaw, 0, 500));
    die('Token exchange failed. <a href="/auth/login.php">Try again</a>.');
}

// ── Fetch user info ───────────────────────────────────────────────────────────
$ch = curl_init(OIDC_USERINFO_ENDPOINT);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['access_token']],
    CURLOPT_TIMEOUT        => 10,
]);
$userRaw = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    die('Userinfo endpoint unreachable: ' . htmlspecialchars($curlErr));
}

$userinfo = json_decode($userRaw, true);
if (empty($userinfo['sub'])) {
    http_response_code(502);
    error_log('OIDC userinfo missing sub: ' . substr((string)$userRaw, 0, 500));
    die('Could not read your profile from the identity provider. <a href="/auth/login.php">Try again</a>.');
}

// ── Persist session ───────────────────────────────────────────────────────────
session_regenerate_id(true);
$_SESSION['user_sub']   = (string)$userinfo['sub'];
$_SESSION['user_name']  = (string)($userinfo['name']
    ?? $userinfo['preferred_username']
    ?? $userinfo['email']
    ?? $userinfo['sub']);
$_SESSION['user_email'] = (string)($userinfo['email'] ?? '');

header('Location: ' . (isLocalPath($returnTo) ? $returnTo : '/'));
exit;
