<?php
require __DIR__ . '/git_helpers.php';
apiInit();
$user = requireUser();

$pub = ensureSshKey($user['sub']);
if (!$pub) fail('Failed to generate SSH key', 500);
jsonOut(['ok' => true, 'public_key' => $pub]);
