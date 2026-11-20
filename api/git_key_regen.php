<?php
require __DIR__ . '/git_helpers.php';
apiInit();
$user = requireUser();

$key = _gitKeyPath($user['sub']);
@unlink($key);
@unlink($key . '.pub');

$pub = ensureSshKey($user['sub']);
if (!$pub) fail('Key generation failed', 500);
jsonOut(['ok' => true, 'public_key' => $pub]);
