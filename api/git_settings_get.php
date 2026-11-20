<?php
require __DIR__ . '/git_helpers.php';
apiInit();
$user = requireUser();

$author = getGitAuthor($user);
jsonOut(['ok' => true, 'commit_name' => $author['name'], 'commit_email' => $author['email']]);
