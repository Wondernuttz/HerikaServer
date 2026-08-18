<?php

// Compatibility entry point for older CHIM pages and schema-2 plugin manifests.
// All installs now go through the channel-aware staged installer so branch
// packages, mutable configuration, and rollback behavior stay consistent.

$packageName = (string)($_GET['PACKAGE_NAME'] ?? '');
$githubRepo = (string)($_GET['GITHUB_REPO'] ?? '');
$channel = (string)($_GET['CHANNEL'] ?? '');
$pluginId = (string)($_GET['PLUGIN_ID'] ?? '');
$force = isset($_GET['FORCE']) && $_GET['FORCE'] !== '0';

$errors = [];
if ($packageName === '.' || $packageName === '..' || !preg_match('/^[A-Za-z0-9_.-]+$/', $packageName)) {
    $errors[] = 'Invalid or missing PACKAGE_NAME.';
}
if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $githubRepo)) {
    $errors[] = 'Invalid or missing GITHUB_REPO.';
}
if ($channel !== '' && !preg_match('/^[A-Za-z0-9_.\/-]+$/', $channel)) {
    $errors[] = 'Invalid CHANNEL.';
}
if ($pluginId !== '' && ($pluginId === '.' || $pluginId === '..' || !preg_match('/^[A-Za-z0-9_.-]+$/', $pluginId))) {
    $errors[] = 'Invalid PLUGIN_ID.';
}

if (empty($errors)) {
    $params = [
        'PACKAGE_NAME' => $packageName,
        'GITHUB_REPO' => $githubRepo,
    ];
    if ($channel !== '') {
        $params['CHANNEL'] = $channel;
    }
    if ($pluginId !== '') {
        $params['PLUGIN_ID'] = $pluginId;
    }
    if ($force) {
        $params['FORCE'] = '1';
    }

    $destination = '/HerikaServer/ui/server_plugin_installer.php?' . http_build_query($params);
    header('Location: ' . $destination, true, 302);
    exit;
}

http_response_code(400);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHIM Plugin Installer</title>
</head>
<body>
    <h1>Plugin installer request rejected</h1>
    <?php foreach ($errors as $error): ?>
        <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endforeach; ?>
</body>
</html>
