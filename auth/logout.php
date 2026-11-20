<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/session.php';
_startSession();

$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires'  => time() - 3600,
    'path'     => $p['path'],
    'secure'   => $p['secure'],
    'httponly' => $p['httponly'],
    'samesite' => $p['samesite'] ?: 'Lax',
]);
session_destroy();

// Send the browser to the provider's logout page so the SSO session ends too.
$url = OIDC_LOGOUT_ENDPOINT ?: '/';
if (OIDC_LOGOUT_ENDPOINT && OIDC_POST_LOGOUT_REDIRECT_URI !== '') {
    $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query([
        'post_logout_redirect_uri' => OIDC_POST_LOGOUT_REDIRECT_URI,
        'client_id'                => OIDC_CLIENT_ID,
    ]);
}
header('Location: ' . $url);
exit;
