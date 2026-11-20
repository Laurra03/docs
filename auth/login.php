<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/session.php';
_startSession();

$returnTo = $_GET['return_to'] ?? '';
if (!isLocalPath($returnTo)) $returnTo = '/';

// If already logged in, go straight to the destination
if (!empty($_SESSION['user_sub'])) {
    header('Location: ' . $returnTo);
    exit;
}

$state    = bin2hex(random_bytes(16));
$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$_SESSION['oauth_state']    = $state;
$_SESSION['oauth_verifier'] = $verifier;
$_SESSION['return_to']      = $returnTo;

$params = http_build_query([
    'client_id'             => OIDC_CLIENT_ID,
    'redirect_uri'          => OIDC_REDIRECT_URI,
    'response_type'         => 'code',
    'scope'                 => 'openid profile email',
    'state'                 => $state,
    // PKCE: binds the authorization code to this browser session.
    'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
    'code_challenge_method' => 'S256',
]);

header('Location: ' . OIDC_AUTH_ENDPOINT . '?' . $params);
exit;
