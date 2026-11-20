<?php
require __DIR__ . '/git_helpers.php';
apiInit();
$user = requireUser();

$name  = cleanTitle($_POST['commit_name'] ?? '', '');
$email = trim((string)($_POST['commit_email'] ?? ''));
if ($name === '' || $email === '') fail('Name and email are required');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[<>]/', $name)) fail('Invalid name or email');

dbExec(
    "INSERT INTO git_user_settings (user_sub, commit_name, commit_email) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE commit_name=VALUES(commit_name), commit_email=VALUES(commit_email)",
    "sss", $user['sub'], $name, $email
);

jsonOut(['ok' => true]);
