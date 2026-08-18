<?php

/**
 * Security and filesystem helpers for the CHIM plugin installer.
 */

function chimPluginInstallerReadJson($path)
{
    if (!is_file($path) || is_link($path)) {
        return false;
    }
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return false;
    }
    $data = json_decode($contents, true);
    return json_last_error() === JSON_ERROR_NONE ? $data : false;
}

function chimPluginInstallerIsValidPackageName($name)
{
    $name = (string)$name;
    return $name !== ''
        && $name !== '.'
        && $name !== '..'
        && strpos($name, '/') === false
        && strpos($name, '\\') === false
        && preg_match('/^[A-Za-z0-9_.-]+$/', $name) === 1;
}

function chimPluginInstallerIsValidPluginId($pluginId)
{
    return chimPluginInstallerIsValidPackageName($pluginId);
}

function chimPluginInstallerIsValidGithubRepo($githubRepo)
{
    return preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', (string)$githubRepo) === 1;
}

/**
 * Resolve an installer identity exclusively from the trusted repository file.
 * A legacy URL may identify a plugin by its unique package name, but every
 * returned field comes from the catalog, never from the URL or old manifest.
 */
function chimPluginInstallerFindTrustedCatalogEntry(
    $pluginRepository,
    $pluginId,
    $packageName = '',
    $githubRepo = '',
    $requirePluginId = false
) {
    if (!is_array($pluginRepository)) {
        return false;
    }

    $pluginId = (string)$pluginId;
    if ($pluginId !== '') {
        if (!chimPluginInstallerIsValidPluginId($pluginId)
            || !isset($pluginRepository[$pluginId])
            || !is_array($pluginRepository[$pluginId])) {
            return false;
        }
        $entry = $pluginRepository[$pluginId];
        $entry['_plugin_id'] = $pluginId;
        return $entry;
    }

    if ($requirePluginId) {
        return false;
    }

    $matches = [];
    foreach ($pluginRepository as $id => $entry) {
        if (!chimPluginInstallerIsValidPluginId($id) || !is_array($entry)) {
            continue;
        }
        $entryName = (string)($entry['name'] ?? '');
        $entryRepo = (string)($entry['git_repo'] ?? '');
        $nameMatches = $packageName !== '' && hash_equals($entryName, (string)$packageName);
        $repoMatches = $githubRepo !== '' && hash_equals($entryRepo, (string)$githubRepo);
        if ($nameMatches || ($packageName === '' && $repoMatches)) {
            $entry['_plugin_id'] = (string)$id;
            $matches[] = $entry;
        }
    }

    return count($matches) === 1 ? $matches[0] : false;
}

function chimPluginInstallerResolveDirectChild($parentDir, $packageName)
{
    if (!chimPluginInstallerIsValidPackageName($packageName)) {
        throw new RuntimeException('Invalid plugin package name.');
    }
    if (!is_dir($parentDir) || is_link($parentDir)) {
        throw new RuntimeException('Plugin root is missing or unsafe.');
    }

    $canonicalParent = realpath($parentDir);
    if ($canonicalParent === false) {
        throw new RuntimeException('Could not resolve the plugin root.');
    }
    $target = $canonicalParent . DIRECTORY_SEPARATOR . $packageName;
    if (dirname($target) !== $canonicalParent || basename($target) !== $packageName) {
        throw new RuntimeException('Plugin target must be a direct child of the plugin root.');
    }
    if (is_link($target)) {
        throw new RuntimeException('Plugin target cannot be a symlink.');
    }
    if (file_exists($target)) {
        $canonicalTarget = realpath($target);
        if ($canonicalTarget === false || dirname($canonicalTarget) !== $canonicalParent) {
            throw new RuntimeException('Existing plugin target escapes the plugin root.');
        }
    }
    return $target;
}

function chimPluginInstallerIsSameOriginMutationRequest($server)
{
    if (strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return false;
    }
    $actionHeader = (string)($server['HTTP_X_CHIM_PLUGIN_ACTION'] ?? '');
    if (!hash_equals('install', $actionHeader)) {
        return false;
    }
    if (isset($server['HTTP_SEC_FETCH_SITE'])
        && !in_array(strtolower((string)$server['HTTP_SEC_FETCH_SITE']), ['same-origin', 'none'], true)) {
        return false;
    }

    $origin = (string)($server['HTTP_ORIGIN'] ?? '');
    $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
    if ($origin === '' || $host === '') {
        return false;
    }
    $originParts = parse_url($origin);
    if (!is_array($originParts) || empty($originParts['scheme']) || empty($originParts['host'])) {
        return false;
    }
    $originHost = strtolower((string)$originParts['host']);
    if (isset($originParts['port'])) {
        $originHost .= ':' . (int)$originParts['port'];
    }
    $requestScheme = (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off') ? 'https' : 'http';

    return hash_equals($host, $originHost)
        && hash_equals($requestScheme, strtolower((string)$originParts['scheme']));
}

function chimPluginInstallerForceAllowed($forceRequested, $channel)
{
    return !$forceRequested || !empty($channel['allow_force']);
}

function chimPluginInstallerShouldExecuteInstall($isAuthorizedMutation, $updateAvailable, $errors)
{
    return $isAuthorizedMutation && $updateAvailable && empty($errors);
}

function chimPluginInstallerUniqueStoragePath($storageDir, $packageName, $purpose)
{
    if (!chimPluginInstallerIsValidPackageName($packageName)
        || !preg_match('/^[a-z-]+$/', (string)$purpose)) {
        throw new RuntimeException('Invalid protected-storage path request.');
    }
    if (!is_dir($storageDir) || is_link($storageDir)) {
        throw new RuntimeException('Protected installer storage is missing or unsafe.');
    }

    for ($attempt = 0; $attempt < 20; $attempt++) {
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $suffix = str_replace('.', '', uniqid('', true));
        }
        $candidate = $storageDir . DIRECTORY_SEPARATOR . $packageName . '-' . $purpose . '-' . $suffix;
        if (!file_exists($candidate) && !is_link($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Could not allocate protected installer storage.');
}

function chimPluginInstallerSetGroup($path, $groupId)
{
    if ($groupId === null) {
        return;
    }
    $currentGroup = @filegroup($path);
    if ($currentGroup === false || (int)$currentGroup !== (int)$groupId) {
        if (!@chgrp($path, (int)$groupId)) {
            throw new RuntimeException('Could not assign the server group to: ' . $path);
        }
    }
}

function chimPluginInstallerPrepareStorage($storageDir, $groupId)
{
    $isAbsolute = isset($storageDir[0]) && ($storageDir[0] === DIRECTORY_SEPARATOR
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $storageDir));
    if (!$isAbsolute) {
        throw new RuntimeException('Protected installer storage must use an absolute path.');
    }
    if (is_link($storageDir)) {
        throw new RuntimeException('Protected installer storage cannot be a symlink.');
    }
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Could not create protected installer storage.');
    }
    if (!@chmod($storageDir, 0770)) {
        throw new RuntimeException('Could not secure protected installer storage permissions.');
    }
    chimPluginInstallerSetGroup($storageDir, $groupId);
    return realpath($storageDir);
}

function chimPluginInstallerDeletePath($path, $throwOnFailure = false)
{
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }

    $ok = true;
    if (is_link($path) || is_file($path)) {
        $ok = @unlink($path);
    } elseif (is_dir($path)) {
        $items = @scandir($path);
        if ($items === false) {
            $ok = false;
        } else {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                if (!chimPluginInstallerDeletePath($path . DIRECTORY_SEPARATOR . $item, $throwOnFailure)) {
                    $ok = false;
                }
            }
            if (!@rmdir($path)) {
                $ok = false;
            }
        }
    } else {
        $ok = false;
    }

    if (!$ok && $throwOnFailure) {
        throw new RuntimeException('Could not remove path: ' . $path);
    }
    return $ok;
}

function chimPluginInstallerRemoveDirectory($dir)
{
    return chimPluginInstallerDeletePath($dir, false);
}

function chimPluginInstallerNormalizeMutablePath($path)
{
    if (!is_string($path)) {
        throw new RuntimeException('Mutable paths must be strings.');
    }
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || $path[0] === '/' || preg_match('/^[A-Za-z]:\//', $path)) {
        throw new RuntimeException('Mutable path must be relative to the plugin directory: ' . $path);
    }

    $normalized = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..' || strpos($segment, "\0") !== false) {
            throw new RuntimeException('Mutable path escapes the plugin directory: ' . $path);
        }
        $normalized[] = $segment;
    }
    if (empty($normalized)) {
        throw new RuntimeException('Mutable path cannot refer to the plugin root.');
    }
    return implode(DIRECTORY_SEPARATOR, $normalized);
}

function chimPluginInstallerAssertNoSymlinkComponents($rootDir, $relativePath)
{
    if (!is_dir($rootDir) || is_link($rootDir)) {
        throw new RuntimeException('Mutable-path root is missing or is a symlink: ' . $rootDir);
    }
    $relativePath = chimPluginInstallerNormalizeMutablePath($relativePath);
    $cursor = $rootDir;
    foreach (explode(DIRECTORY_SEPARATOR, $relativePath) as $segment) {
        $cursor .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($cursor)) {
            throw new RuntimeException('Mutable path contains a symlink: ' . $cursor);
        }
        if (!file_exists($cursor)) {
            break;
        }
    }
}

function chimPluginInstallerReadMutablePaths($packageDir)
{
    if (!is_dir($packageDir) || is_link($packageDir)) {
        throw new RuntimeException('Plugin package root is missing or unsafe.');
    }
    $descriptorPath = $packageDir . DIRECTORY_SEPARATOR . 'dwemer-package.json';
    if (!file_exists($descriptorPath) && !is_link($descriptorPath)) {
        return [];
    }
    chimPluginInstallerAssertNoSymlinkComponents($packageDir, 'dwemer-package.json');
    $descriptor = chimPluginInstallerReadJson($descriptorPath);
    if (!is_array($descriptor)) {
        throw new RuntimeException('Invalid dwemer-package.json in ' . $packageDir . '.');
    }
    $rawPaths = $descriptor['server']['mutable_paths'] ?? [];
    if (!is_array($rawPaths)) {
        throw new RuntimeException('server.mutable_paths must be an array in dwemer-package.json.');
    }

    $paths = [];
    foreach ($rawPaths as $path) {
        $normalized = chimPluginInstallerNormalizeMutablePath($path);
        $paths[$normalized] = true;
    }
    return array_keys($paths);
}

function chimPluginInstallerCopyPath($source, $destination)
{
    if (is_link($source) || is_link($destination)) {
        throw new RuntimeException('Refusing to copy a mutable path through a symlink.');
    }
    if (is_file($source)) {
        $parent = dirname($destination);
        if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Could not create mutable path parent: ' . $parent);
        }
        if (!@copy($source, $destination)) {
            throw new RuntimeException('Could not preserve mutable file: ' . $source);
        }
        return;
    }
    if (!is_dir($source)) {
        throw new RuntimeException('Mutable path is neither a file nor a directory: ' . $source);
    }
    if (!is_dir($destination) && !@mkdir($destination, 0775, true) && !is_dir($destination)) {
        throw new RuntimeException('Could not create mutable directory: ' . $destination);
    }
    $items = @scandir($source);
    if ($items === false) {
        throw new RuntimeException('Could not read mutable directory: ' . $source);
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        chimPluginInstallerCopyPath(
            $source . DIRECTORY_SEPARATOR . $item,
            $destination . DIRECTORY_SEPARATOR . $item
        );
    }
}

function chimPluginInstallerPreserveMutablePaths($targetDir, $stagingDir)
{
    if (!is_dir($targetDir)) {
        return [];
    }
    if (is_link($targetDir) || !is_dir($stagingDir) || is_link($stagingDir)) {
        throw new RuntimeException('Mutable-path package root is unsafe.');
    }

    $paths = [];
    foreach (array_merge(
        chimPluginInstallerReadMutablePaths($targetDir),
        chimPluginInstallerReadMutablePaths($stagingDir)
    ) as $path) {
        $paths[$path] = true;
    }

    $preserved = [];
    foreach (array_keys($paths) as $relativePath) {
        chimPluginInstallerAssertNoSymlinkComponents($targetDir, $relativePath);
        chimPluginInstallerAssertNoSymlinkComponents($stagingDir, $relativePath);
        $source = $targetDir . DIRECTORY_SEPARATOR . $relativePath;
        if (!file_exists($source)) {
            continue;
        }
        $destination = $stagingDir . DIRECTORY_SEPARATOR . $relativePath;
        if (file_exists($destination)) {
            // Symlinks were rejected above, so this deletion remains inside staging.
            chimPluginInstallerDeletePath($destination, true);
        }
        chimPluginInstallerCopyPath($source, $destination);
        $preserved[] = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
    }
    return $preserved;
}

function chimPluginInstallerApplyTreePermissions($rootDir, $groupId)
{
    if (!is_dir($rootDir) || is_link($rootDir)) {
        throw new RuntimeException('Cannot apply permissions to an unsafe package root.');
    }
    $stack = [$rootDir];
    while (!empty($stack)) {
        $path = array_pop($stack);
        if (is_link($path)) {
            throw new RuntimeException('Plugin package contains a symlink: ' . $path);
        }
        if (is_dir($path)) {
            if (!@chmod($path, 0775)) {
                throw new RuntimeException('Could not set directory permissions: ' . $path);
            }
            chimPluginInstallerSetGroup($path, $groupId);
            $items = @scandir($path);
            if ($items === false) {
                throw new RuntimeException('Could not scan package directory: ' . $path);
            }
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') {
                    $stack[] = $path . DIRECTORY_SEPARATOR . $item;
                }
            }
        } elseif (is_file($path)) {
            if (!@chmod($path, 0664)) {
                throw new RuntimeException('Could not set file permissions: ' . $path);
            }
            chimPluginInstallerSetGroup($path, $groupId);
        } else {
            throw new RuntimeException('Plugin package contains an unsupported filesystem entry: ' . $path);
        }
    }
}

function chimPluginInstallerCommitsMatch($installedCommit, $remoteCommit)
{
    $installedCommit = strtolower(trim((string)$installedCommit));
    $remoteCommit = strtolower(trim((string)$remoteCommit));
    if (!preg_match('/^[0-9a-f]{7,40}$/', $installedCommit)
        || !preg_match('/^[0-9a-f]{7,40}$/', $remoteCommit)) {
        return false;
    }
    $shorterLength = min(strlen($installedCommit), strlen($remoteCommit));
    return substr($installedCommit, 0, $shorterLength) === substr($remoteCommit, 0, $shorterLength);
}

function chimPluginInstallerNormalizeReleaseVersion($tag)
{
    $tag = trim((string)$tag);
    if (preg_match('/^v(?=\d)/i', $tag)) {
        return substr($tag, 1);
    }
    return $tag;
}

/**
 * Activate prepared code with a filesystem rollback. Database migrations run
 * in the callback and have their own transaction policy; this function does
 * not claim to reverse database work that a plugin makes nontransactional.
 */
function chimPluginInstallerActivateStaging($stagingDir, $targetDir, $storageDir, $postActivate = null)
{
    if (!is_dir($stagingDir) || is_link($stagingDir)
        || realpath(dirname($stagingDir)) !== realpath($storageDir)) {
        throw new RuntimeException('Staging directory is missing or outside protected storage.');
    }
    if (!is_dir($storageDir) || is_link($storageDir)) {
        throw new RuntimeException('Protected installer storage is unsafe.');
    }

    $targetParent = dirname($targetDir);
    if (!is_dir($targetParent) || is_link($targetParent)) {
        throw new RuntimeException('Plugin target parent is unsafe.');
    }
    $canonicalStorage = realpath($storageDir);
    $canonicalTargetParent = realpath($targetParent);
    if ($canonicalStorage === false || $canonicalTargetParent === false
        || $canonicalStorage === $canonicalTargetParent
        || strpos($canonicalStorage, $canonicalTargetParent . DIRECTORY_SEPARATOR) === 0) {
        throw new RuntimeException('Protected installer storage must be outside the web-served plugin root.');
    }
    $storageStat = @stat($storageDir);
    $targetStat = @stat($targetParent);
    if (!is_array($storageStat) || !is_array($targetStat) || $storageStat['dev'] !== $targetStat['dev']) {
        throw new RuntimeException('Protected storage and plugin root must be on the same filesystem.');
    }
    if ((file_exists($targetDir) || is_link($targetDir)) && (!is_dir($targetDir) || is_link($targetDir))) {
        throw new RuntimeException('Plugin target is not a normal directory.');
    }

    $packageName = basename($targetDir);
    $backupDir = null;
    $failedDir = null;
    $activated = false;

    try {
        if (is_dir($targetDir)) {
            $backupDir = chimPluginInstallerUniqueStoragePath($storageDir, $packageName, 'backup');
            if (!@rename($targetDir, $backupDir)) {
                throw new RuntimeException('Could not move the current plugin to protected rollback storage.');
            }
        }
        if (!@rename($stagingDir, $targetDir)) {
            if ($backupDir !== null && !file_exists($targetDir)) {
                @rename($backupDir, $targetDir);
            }
            throw new RuntimeException('Could not activate the staged plugin.');
        }
        $activated = true;

        if (is_callable($postActivate)) {
            $postActivate($targetDir);
        }
    } catch (Throwable $failure) {
        $rollbackErrors = [];
        if ($activated && is_dir($targetDir)) {
            $failedDir = chimPluginInstallerUniqueStoragePath($storageDir, $packageName, 'failed');
            if (!@rename($targetDir, $failedDir)) {
                $rollbackErrors[] = 'could not move the failed package aside';
            }
        }
        if ($backupDir !== null && is_dir($backupDir) && !file_exists($targetDir)) {
            if (!@rename($backupDir, $targetDir)) {
                $rollbackErrors[] = 'could not restore the previous package';
            }
        }
        if ($failedDir !== null && is_dir($failedDir)) {
            chimPluginInstallerDeletePath($failedDir, false);
        }

        $message = $failure->getMessage();
        if (!empty($rollbackErrors)) {
            $message .= ' Filesystem rollback error: ' . implode('; ', $rollbackErrors) . '.';
        }
        throw new RuntimeException($message, 0, $failure);
    }

    $cleanupWarning = '';
    if ($backupDir !== null && is_dir($backupDir) && !chimPluginInstallerDeletePath($backupDir, false)) {
        $cleanupWarning = 'The update is active, but its protected backup could not be removed: ' . $backupDir;
    }
    return ['activated' => true, 'cleanup_warning' => $cleanupWarning];
}
