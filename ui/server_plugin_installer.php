<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once $enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "plugin_installer.php";

$pluginRepositoryFile = __DIR__ . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "plugin_repository.json";
$pluginRepository = [];
if (file_exists($pluginRepositoryFile)) {
    $repositoryData = json_decode(file_get_contents($pluginRepositoryFile), true);
    if (is_array($repositoryData) && isset($repositoryData["plugins"]) && is_array($repositoryData["plugins"])) {
        $pluginRepository = $repositoryData["plugins"];
    }
}

function chimPluginInstallerEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function chimPluginInstallerFetchUrl($url) {
    $headers = [
        "User-Agent: CHIM Plugin Installer",
        "Accept: application/vnd.github.v3+json, application/json, */*",
    ];
    $githubToken = trim((string)getenv("GITHUB_TOKEN"));
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ""));
    if ($githubToken !== "" && $host === "api.github.com") {
        $headers[] = "Authorization: Bearer " . $githubToken;
    }

    if (!function_exists("curl_init")) {
        $context = stream_context_create([
            "http" => [
                "method" => "GET",
                "header" => implode("\r\n", $headers) . "\r\n",
                "timeout" => 60,
                "follow_location" => 1,
            ],
        ]);
        return @file_get_contents($url, false, $context);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "CHIM Plugin Installer");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return false;
    }
    return $response;
}

function chimPluginInstallerStringEndsWith($value, $suffix) {
    if ($suffix === "") {
        return true;
    }
    return substr($value, -strlen($suffix)) === $suffix;
}

function chimPluginInstallerFindRepositoryEntry($pluginRepository, $pluginId, $packageName, $githubRepo) {
    return chimPluginInstallerFindTrustedCatalogEntry(
        $pluginRepository,
        $pluginId,
        $packageName,
        $githubRepo,
        false
    );
}

function chimPluginInstallerReplaceTokens($value, $packageName, $githubRepo, $channelId, $branch) {
    return strtr($value, [
        "<package>" => $packageName,
        "<repo>" => $githubRepo,
        "<channel>" => $channelId,
        "<branch>" => $branch,
    ]);
}

function chimPluginInstallerNormalizeChannels($entry, $packageName, $githubRepo) {
    $channels = [];
    $rawChannels = $entry["channels"] ?? [];

    if (is_array($rawChannels) && !empty($rawChannels)) {
        foreach ($rawChannels as $channelId => $channelConfig) {
            if (is_string($channelConfig)) {
                $channelConfig = ["branch" => $channelConfig];
            }
            if (!is_array($channelConfig)) {
                continue;
            }

            $branch = (string)($channelConfig["branch"] ?? $channelId);
            $label = (string)($channelConfig["label"] ?? ucfirst((string)$channelId));
            $packageSource = (string)($channelConfig["package_source"] ?? "");
            $manifestUrl = (string)($channelConfig["manifest_url"] ?? "");
            $releaseApiUrl = (string)($channelConfig["release_api_url"] ?? "");
            if ($manifestUrl === "" && $branch !== "") {
                $manifestUrl = "https://raw.githubusercontent.com/" . $githubRepo . "/" . $branch . "/manifest.json";
            }

            $packageUrls = [];
            if (isset($channelConfig["package_urls"]) && is_array($channelConfig["package_urls"])) {
                $packageUrls = $channelConfig["package_urls"];
            } elseif (isset($channelConfig["package_url"])) {
                $packageUrls = [$channelConfig["package_url"]];
            }

            if (empty($packageUrls)) {
                if ($packageSource === "branch" || (!in_array($channelId, ["main", "live", "stable"], true) && $branch !== "")) {
                    $packageSource = "branch";
                    $packageUrls = ["https://github.com/" . $githubRepo . "/archive/refs/heads/" . $branch . ".tar.gz"];
                } else {
                    $packageSource = $packageSource !== "" ? $packageSource : "release";
                    $packageUrls = [
                        "https://github.com/" . $githubRepo . "/releases/latest/download/" . $packageName . ".tar.gz",
                        "https://github.com/" . $githubRepo . "/releases/latest/download/" . $packageName . ".tar",
                    ];
                }
            }

            $packageUrls = array_map(function ($url) use ($packageName, $githubRepo, $channelId, $branch) {
                return chimPluginInstallerReplaceTokens((string)$url, $packageName, $githubRepo, (string)$channelId, $branch);
            }, $packageUrls);

            $channels[$channelId] = [
                "id" => (string)$channelId,
                "label" => $label,
                "branch" => $branch,
                "package_source" => $packageSource,
                "manifest_url" => chimPluginInstallerReplaceTokens($manifestUrl, $packageName, $githubRepo, (string)$channelId, $branch),
                "release_api_url" => chimPluginInstallerReplaceTokens($releaseApiUrl, $packageName, $githubRepo, (string)$channelId, $branch),
                "package_urls" => $packageUrls,
                "archive_strip_components" => (int)($channelConfig["archive_strip_components"] ?? 1),
                "allow_force" => (bool)($channelConfig["allow_force"] ?? ($channelId !== "main")),
            ];
        }
    }

    if (empty($channels)) {
        $channels["main"] = [
            "id" => "main",
            "label" => "Live",
            "branch" => "",
            "package_source" => "release",
            "manifest_url" => "https://api.github.com/repos/" . $githubRepo . "/contents/manifest.json",
            "release_api_url" => "https://api.github.com/repos/" . $githubRepo . "/releases/latest",
            "package_urls" => [
                "https://github.com/" . $githubRepo . "/releases/latest/download/" . $packageName . ".tar.gz",
                "https://github.com/" . $githubRepo . "/releases/latest/download/" . $packageName . ".tar",
            ],
            "archive_strip_components" => 1,
            "allow_force" => false,
        ];
    }

    return $channels;
}

function chimPluginInstallerGetRemoteManifest($channel, $githubRepo, $resolvedCommit = "") {
    if (
        ($channel["package_source"] ?? "") === "branch"
        && preg_match('/^[0-9a-f]{40}$/', (string)$resolvedCommit)
    ) {
        $manifestUrl = "https://raw.githubusercontent.com/" . $githubRepo . "/" . $resolvedCommit . "/manifest.json";
    } else {
        $manifestUrl = $channel["manifest_url"] ?? "";
    }
    if ($manifestUrl === "" && !empty($channel["branch"])) {
        $manifestUrl = "https://api.github.com/repos/" . $githubRepo . "/contents/manifest.json?ref=" . rawurlencode($channel["branch"]);
    }
    if ($manifestUrl === "") {
        $manifestUrl = "https://api.github.com/repos/" . $githubRepo . "/contents/manifest.json";
    }

    $response = chimPluginInstallerFetchUrl($manifestUrl);
    if ($response === false) {
        return false;
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }

    if (isset($data["content"])) {
        $content = base64_decode($data["content"]);
        $manifest = json_decode($content, true);
        return json_last_error() === JSON_ERROR_NONE ? $manifest : false;
    }

    return is_array($data) ? $data : false;
}

function chimPluginInstallerGetRemoteCommit($channel, $githubRepo) {
    if (($channel["package_source"] ?? "") !== "branch") {
        return "";
    }

    $branch = trim((string)($channel["branch"] ?? ""));
    if ($branch === "") {
        return false;
    }

    $response = chimPluginInstallerFetchUrl(
        "https://api.github.com/repos/" . $githubRepo . "/commits/" . rawurlencode($branch)
    );
    if ($response === false) {
        return false;
    }

    $data = json_decode($response, true);
    $sha = is_array($data) ? strtolower((string)($data["sha"] ?? "")) : "";
    return preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : false;
}

function chimPluginInstallerGetRemoteRelease($channel, $githubRepo, $packageName) {
    if (($channel["package_source"] ?? "") !== "release") {
        return [];
    }

    $releaseApiUrl = (string)($channel["release_api_url"] ?? "");
    if ($releaseApiUrl === "") {
        $releaseApiUrl = "https://api.github.com/repos/" . $githubRepo . "/releases/latest";
    }
    $response = chimPluginInstallerFetchUrl($releaseApiUrl);
    if ($response === false) {
        return false;
    }
    $release = json_decode($response, true);
    if (!is_array($release) || empty($release["id"]) || empty($release["tag_name"]) || !isset($release["assets"])) {
        return false;
    }

    $configuredAssetNames = [];
    foreach (($channel["package_urls"] ?? []) as $packageUrl) {
        $resolvedPackageUrl = strtr((string)$packageUrl, [
            "<version>" => chimPluginInstallerNormalizeReleaseVersion($release["tag_name"]),
        ]);
        $path = (string)(parse_url($resolvedPackageUrl, PHP_URL_PATH) ?? "");
        $assetName = rawurldecode(basename($path));
        if ($assetName !== "") {
            $configuredAssetNames[] = $assetName;
        }
    }
    if (empty($configuredAssetNames)) {
        $configuredAssetNames = [$packageName . ".tar.gz", $packageName . ".tar"];
    }

    $assetsByName = [];
    foreach ((array)$release["assets"] as $asset) {
        if (is_array($asset) && !empty($asset["name"]) && !empty($asset["browser_download_url"])) {
            $assetsByName[(string)$asset["name"]] = $asset;
        }
    }
    $selectedAsset = null;
    foreach ($configuredAssetNames as $assetName) {
        if (isset($assetsByName[$assetName])) {
            $selectedAsset = $assetsByName[$assetName];
            break;
        }
    }
    if (!is_array($selectedAsset)) {
        return false;
    }

    return [
        "id" => (int)$release["id"],
        "tag" => (string)$release["tag_name"],
        "version" => chimPluginInstallerNormalizeReleaseVersion($release["tag_name"]),
        "asset_id" => (int)($selectedAsset["id"] ?? 0),
        "asset_name" => (string)$selectedAsset["name"],
        "asset_url" => (string)$selectedAsset["browser_download_url"],
    ];
}

function chimPluginInstallerPinBranchPackage($channel, $githubRepo, $remoteCommit) {
    if (($channel["package_source"] ?? "") !== "branch") {
        return $channel;
    }
    if (!preg_match('/^[0-9a-f]{40}$/', (string)$remoteCommit)) {
        throw new RuntimeException("Could not resolve the selected branch to an immutable commit.");
    }

    $channel["package_urls"] = [
        "https://github.com/" . $githubRepo . "/archive/" . $remoteCommit . ".tar.gz",
    ];
    return $channel;
}

function chimPluginInstallerPinReleasePackage($channel, $remoteRelease) {
    if (($channel["package_source"] ?? "") !== "release") {
        return $channel;
    }
    if (!is_array($remoteRelease)
        || empty($remoteRelease["id"])
        || empty($remoteRelease["asset_id"])
        || empty($remoteRelease["asset_url"])) {
        throw new RuntimeException("Could not resolve the release to an exact GitHub asset.");
    }
    $channel["package_urls"] = [(string)$remoteRelease["asset_url"]];
    return $channel;
}

function chimPluginInstallerValidateStagedPackage($stagingDir, $packageName, $channel, $remoteManifest, $remoteVersion) {
    $manifestPath = $stagingDir . DIRECTORY_SEPARATOR . "manifest.json";
    $manifest = chimPluginInstallerReadJson($manifestPath);
    if (!is_array($manifest)) {
        throw new RuntimeException("Package did not contain a valid manifest.json at its root.");
    }

    $manifestName = (string)($manifest["name"] ?? "");
    if ($manifestName === "" || $manifestName !== $packageName) {
        throw new RuntimeException("Package manifest name does not match the requested plugin: " . $packageName);
    }

    $manifestVersion = (string)($manifest["version"] ?? "");
    if ($manifestVersion === "") {
        throw new RuntimeException("Package manifest does not contain a version.");
    }

    if (($channel["package_source"] ?? "") === "branch" && is_array($remoteManifest)) {
        $remoteVersion = (string)($remoteManifest["version"] ?? "");
        if ($remoteVersion !== "" && $remoteVersion !== $manifestVersion) {
            throw new RuntimeException("Downloaded branch version does not match the version checked before installation.");
        }
    }
    if (($channel["package_source"] ?? "") === "release") {
        if ($remoteVersion === ""
            || version_compare(
                chimPluginInstallerNormalizeReleaseVersion($manifestVersion),
                chimPluginInstallerNormalizeReleaseVersion($remoteVersion),
                "!="
            )) {
            throw new RuntimeException("Downloaded asset manifest version does not match its GitHub release tag.");
        }
    }

    $descriptorPath = $stagingDir . DIRECTORY_SEPARATOR . "dwemer-package.json";
    if (is_file($descriptorPath)) {
        $descriptor = chimPluginInstallerReadJson($descriptorPath);
        if (!is_array($descriptor)) {
            throw new RuntimeException("Package contains an invalid dwemer-package.json.");
        }
        $descriptorName = (string)($descriptor["name"] ?? "");
        if ($descriptorName !== "" && $descriptorName !== $packageName) {
            throw new RuntimeException("dwemer-package.json name does not match the requested plugin.");
        }
        $descriptorVersion = (string)($descriptor["version"] ?? "");
        if ($descriptorVersion !== "" && $descriptorVersion !== $manifestVersion) {
            throw new RuntimeException("manifest.json and dwemer-package.json have different versions.");
        }
        // Validate every declared path before the current installation is touched.
        chimPluginInstallerReadMutablePaths($stagingDir);
    }

    return $manifest;
}

function chimPluginInstallerEnsurePluginName($packageName) {
    return chimPluginInstallerIsValidPackageName($packageName);
}

function chimPluginInstallerEnsureGithubRepo($githubRepo) {
    return chimPluginInstallerIsValidGithubRepo($githubRepo);
}

function chimPluginInstallerConnectToDatabase() {
    $connStr = isset($GLOBALS["CHIM_PLUGIN_INSTALLER_DB_CONNSTR"])
        ? (string)$GLOBALS["CHIM_PLUGIN_INSTALLER_DB_CONNSTR"]
        : "host=localhost port=5432 dbname=dwemer user=dwemer password=dwemer";
    $conn = @pg_connect($connStr, PGSQL_CONNECT_FORCE_NEW);
    if (!$conn) {
        throw new RuntimeException("Failed to connect to the CHIM database.");
    }
    return $conn;
}

function chimPluginInstallerRequireQuery($conn, $sql, $label) {
    $result = @pg_query($conn, $sql);
    if ($result === false) {
        throw new RuntimeException($label . ": " . pg_last_error($conn));
    }
    return $result;
}

function chimPluginInstallerRequireQueryParams($conn, $sql, $params, $label) {
    $result = @pg_query_params($conn, $sql, $params);
    if ($result === false) {
        throw new RuntimeException($label . ": " . pg_last_error($conn));
    }
    return $result;
}

function chimPluginInstallerStripSqlLiteralsAndComments($sql) {
    $length = strlen($sql);
    $output = "";
    for ($i = 0; $i < $length;) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : "";

        if ($char === "-" && $next === "-") {
            $i += 2;
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            $output .= "\n";
            continue;
        }
        if ($char === "/" && $next === "*") {
            $i += 2;
            $depth = 1;
            while ($i < $length && $depth > 0) {
                if ($i + 1 < $length && $sql[$i] === "/" && $sql[$i + 1] === "*") {
                    $depth++;
                    $i += 2;
                } elseif ($i + 1 < $length && $sql[$i] === "*" && $sql[$i + 1] === "/") {
                    $depth--;
                    $i += 2;
                } else {
                    $i++;
                }
            }
            $output .= " ";
            continue;
        }
        if ($char === "'" || $char === '"') {
            $quote = $char;
            $i++;
            while ($i < $length) {
                if ($sql[$i] === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                if ($sql[$i] === "\\" && $quote === "'" && $i + 1 < $length) {
                    $i += 2;
                    continue;
                }
                $i++;
            }
            $output .= " ";
            continue;
        }
        if ($char === '$' && preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*\$|\G\$\$/A', $sql, $match, 0, $i)) {
            $delimiter = $match[0];
            $i += strlen($delimiter);
            $end = strpos($sql, $delimiter, $i);
            $i = $end === false ? $length : $end + strlen($delimiter);
            $output .= " ";
            continue;
        }

        $output .= $char;
        $i++;
    }
    return $output;
}

function chimPluginInstallerAssertMigrationSqlTransactional($sql, $migrationName) {
    $stripped = chimPluginInstallerStripSqlLiteralsAndComments($sql);
    if (preg_match(
        '/(?:^|;)\s*(?:BEGIN\b|START\s+TRANSACTION\b|COMMIT\b|END\b|ROLLBACK\b|ABORT\b|SAVEPOINT\b|RELEASE(?:\s+SAVEPOINT)?\b|PREPARE\s+TRANSACTION\b)/i',
        $stripped
    )) {
        throw new RuntimeException(
            "Migration " . $migrationName . " contains transaction-control SQL. Plugin migrations must leave transaction control to CHIM."
        );
    }
}

function chimPluginInstallerRunMigrations($targetDir, $packageName) {
    $migrationsDir = $targetDir . DIRECTORY_SEPARATOR . "migrations";
    if (!is_dir($migrationsDir)) {
        echo "<p class='log-info'>No migrations directory found, skipping database migrations.</p>\n";
        return true;
    }
    if (is_link($migrationsDir)) {
        echo "<p class='log-error'>Migration directory is a symlink and was rejected.</p>\n";
        return false;
    }

    $migrationSql = [];
    $migrations = glob($migrationsDir . DIRECTORY_SEPARATOR . "*.sql");
    if ($migrations === false) {
        echo "<p class='log-error'>Could not enumerate database migrations.</p>\n";
        return false;
    }
    if (empty($migrations)) {
        echo "<p class='log-info'>No migration files found.</p>\n";
        return true;
    }
    sort($migrations, SORT_STRING);
    try {
        foreach ($migrations as $migrationFile) {
            if (!is_file($migrationFile) || is_link($migrationFile) || !is_readable($migrationFile)) {
                throw new RuntimeException("Migration file is unreadable or unsafe: " . basename($migrationFile));
            }
            $sql = @file_get_contents($migrationFile);
            if ($sql === false || trim($sql) === "") {
                throw new RuntimeException("Migration file is empty or unreadable: " . basename($migrationFile));
            }
            chimPluginInstallerAssertMigrationSqlTransactional($sql, basename($migrationFile));
            $migrationSql[basename($migrationFile)] = $sql;
        }
    } catch (Throwable $e) {
        echo "<p class='log-error'>Error preparing migrations: " . chimPluginInstallerEscape($e->getMessage()) . "</p>\n";
        return false;
    }

    $conn = null;
    $committedMigrations = [];
    try {
        $conn = chimPluginInstallerConnectToDatabase();
        chimPluginInstallerRequireQuery($conn, "BEGIN", "Could not start migration transaction");
        chimPluginInstallerRequireQuery($conn, "SET LOCAL lock_timeout = '30s'", "Could not set migration lock timeout");
        chimPluginInstallerRequireQueryParams(
            $conn,
            "SELECT pg_advisory_xact_lock(hashtextextended($1, 0))",
            ["chim-plugin-migrations"],
            "Could not acquire global plugin migration lock"
        );
        chimPluginInstallerRequireQuery($conn, "CREATE SCHEMA IF NOT EXISTS plugins", "Could not create plugins schema");
        chimPluginInstallerRequireQuery(
            $conn,
            "CREATE TABLE IF NOT EXISTS plugins.plugin_migrations (plugin_name VARCHAR(255) NOT NULL, migration_name VARCHAR(255) NOT NULL, executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (plugin_name, migration_name))",
            "Could not create plugin migration history"
        );

        $legacyResult = chimPluginInstallerRequireQueryParams(
            $conn,
            "SELECT to_regclass($1) AS table_name",
            ["public.plugin_migrations"],
            "Could not inspect legacy migration history"
        );
        $legacyRow = pg_fetch_assoc($legacyResult);
        if ($legacyRow === false) {
            throw new RuntimeException("Could not read legacy migration history lookup.");
        }
        if (!empty($legacyRow["table_name"])) {
            $columnResult = chimPluginInstallerRequireQueryParams(
                $conn,
                "SELECT column_name FROM information_schema.columns WHERE table_schema = $1 AND table_name = $2",
                ["public", "plugin_migrations"],
                "Could not inspect legacy migration history columns"
            );
            $legacyColumns = [];
            while ($column = pg_fetch_assoc($columnResult)) {
                $legacyColumns[(string)$column["column_name"]] = true;
            }
            if (!isset($legacyColumns["plugin_name"], $legacyColumns["migration_name"])) {
                throw new RuntimeException("Legacy public.plugin_migrations has an incompatible schema.");
            }
            $executedAtExpression = isset($legacyColumns["executed_at"])
                ? "COALESCE(executed_at, CURRENT_TIMESTAMP)"
                : "CURRENT_TIMESTAMP";
            chimPluginInstallerRequireQuery(
                $conn,
                "INSERT INTO plugins.plugin_migrations (plugin_name, migration_name, executed_at) "
                    . "SELECT plugin_name, migration_name, " . $executedAtExpression . " FROM public.plugin_migrations "
                    . "WHERE plugin_name IS NOT NULL AND migration_name IS NOT NULL "
                    . "ON CONFLICT (plugin_name, migration_name) DO NOTHING",
                "Could not import legacy migration history"
            );
        }

        foreach ($migrationSql as $migrationName => $sql) {
            $history = chimPluginInstallerRequireQueryParams(
                $conn,
                "SELECT 1 FROM plugins.plugin_migrations WHERE plugin_name = $1 AND migration_name = $2",
                [$packageName, $migrationName],
                "Could not check migration history for " . $migrationName
            );
            if (pg_num_rows($history) > 0) {
                echo "<p class='log-skipped'>Skipping already executed migration: " . chimPluginInstallerEscape($migrationName) . "</p>\n";
                continue;
            }

            echo "<p class='log-running'>Applying migration inside database transaction: " . chimPluginInstallerEscape($migrationName) . "</p>\n";
            chimPluginInstallerRequireQuery($conn, $sql, "Migration failed: " . $migrationName);
            if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_INTRANS) {
                throw new RuntimeException(
                    "Migration " . $migrationName . " ended the installer transaction; database rollback cannot be guaranteed."
                );
            }
            chimPluginInstallerRequireQueryParams(
                $conn,
                "INSERT INTO plugins.plugin_migrations (plugin_name, migration_name) VALUES ($1, $2)",
                [$packageName, $migrationName],
                "Could not record migration history for " . $migrationName
            );
            $committedMigrations[] = $migrationName;
        }

        chimPluginInstallerRequireQuery($conn, "COMMIT", "Could not commit plugin migrations");
        foreach ($committedMigrations as $migrationName) {
            echo "<p class='log-completed'>Migration committed: " . chimPluginInstallerEscape($migrationName) . "</p>\n";
        }
        return true;
    } catch (Throwable $e) {
        $rollbackNote = "";
        if ($conn !== null) {
            $status = @pg_transaction_status($conn);
            if ($status === PGSQL_TRANSACTION_INTRANS || $status === PGSQL_TRANSACTION_INERROR) {
                $rollbackResult = @pg_query($conn, "ROLLBACK");
                $rollbackNote = $rollbackResult === false
                    ? " Database rollback failed."
                    : " Database transaction rolled back.";
            } elseif ($status === PGSQL_TRANSACTION_IDLE) {
                $rollbackNote = " The transaction was already closed, so database rollback cannot be guaranteed.";
            }
        }
        echo "<p class='log-error'>Error running migrations: "
            . chimPluginInstallerEscape($e->getMessage() . $rollbackNote) . "</p>\n";
        return false;
    } finally {
        if ($conn !== null) {
            @pg_close($conn);
        }
    }
}

function chimPluginInstallerRunComposer($targetDir) {
    $composerJson = $targetDir . DIRECTORY_SEPARATOR . "composer.json";
    if (!file_exists($composerJson)) {
        echo "<p class='log-info'>No composer.json found, skipping dependency installation.</p>\n";
        return true;
    }

    echo "<p class='log-action'>Installing dependencies with Composer...</p>\n";
    $installCmd = "cd " . escapeshellarg($targetDir) . " && COMPOSER_HOME=" . escapeshellarg(sys_get_temp_dir()) . " /usr/bin/composer --no-ansi -v install";
    ob_start();
    system($installCmd, $installStatus);
    $installOutput = ob_get_clean();
    echo "<div class='system-command-output'>" . nl2br(chimPluginInstallerEscape($installOutput)) . "</div>";

    if ($installStatus !== 0) {
        throw new Exception("Composer install failed with status " . $installStatus);
    }
    return true;
}

function chimPluginInstallerDownloadPackage($channel, $storageDir, $packageName, $groupId) {
    foreach ($channel["package_urls"] as $packageUrl) {
        echo "<p class='log-action'>Trying package URL: " . chimPluginInstallerEscape($packageUrl) . "</p>\n";
        $downloadContent = chimPluginInstallerFetchUrl($packageUrl);
        if ($downloadContent === false) {
            echo "<p class='log-skipped'>Download failed, trying next URL if available.</p>\n";
            continue;
        }

        $packagePath = parse_url($packageUrl, PHP_URL_PATH) ?? "";
        $extension = chimPluginInstallerStringEndsWith($packagePath, ".tar") ? ".tar" : ".tar.gz";
        $archiveFile = chimPluginInstallerUniqueStoragePath($storageDir, $packageName, "download") . $extension;
        if (@file_put_contents($archiveFile, $downloadContent) === false) {
            throw new Exception("Failed to write downloaded archive.");
        }
        if (!@chmod($archiveFile, 0660)) {
            @unlink($archiveFile);
            throw new Exception("Failed to secure downloaded archive permissions.");
        }
        chimPluginInstallerSetGroup($archiveFile, $groupId);
        return [$archiveFile, $packageUrl];
    }

    throw new Exception("Failed to download package from all configured channel URLs.");
}

function chimPluginInstallerValidateArchivePaths($archiveFile, $isGzip, $stripComponents) {
    $listFlags = $isGzip ? "-tzf" : "-tf";
    $command = "tar " . $listFlags . " " . escapeshellarg($archiveFile);
    $entries = [];
    exec($command, $entries, $status);
    if ($status !== 0 || empty($entries)) {
        throw new RuntimeException("Downloaded package is empty or could not be inspected.");
    }

    $usableEntries = 0;
    foreach ($entries as $entry) {
        $entry = str_replace('\\', '/', (string)$entry);
        if ($entry === '' || $entry[0] === '/' || preg_match('/^[A-Za-z]:\//', $entry)) {
            throw new RuntimeException("Downloaded package contains an absolute archive path.");
        }
        $segments = array_values(array_filter(explode('/', $entry), function ($segment) {
            return $segment !== '' && $segment !== '.';
        }));
        foreach ($segments as $segment) {
            if ($segment === '..' || strpos($segment, "\0") !== false) {
                throw new RuntimeException("Downloaded package contains a path traversal entry.");
            }
        }
        if (count($segments) <= $stripComponents) {
            continue;
        }
        $usableEntries++;
    }
    if ($usableEntries === 0) {
        throw new RuntimeException("Downloaded package has no files after archive path stripping.");
    }
}

function chimPluginInstallerInstallPackage(
    $channel,
    $targetDir,
    $packageName,
    $githubRepo,
    $remoteManifest,
    $remoteCommit,
    $remoteVersion,
    $remoteRelease,
    $storageDir
) {
    $targetParent = dirname($targetDir);
    if (!is_dir($targetParent) || !is_writable($targetParent)) {
        throw new Exception("Target parent is not writable: " . $targetParent);
    }
    $resolvedTarget = chimPluginInstallerResolveDirectChild($targetParent, $packageName);
    if ($resolvedTarget !== $targetDir) {
        throw new Exception("Plugin target failed direct-child validation.");
    }

    $channel = chimPluginInstallerPinBranchPackage($channel, $githubRepo, $remoteCommit);
    $channel = chimPluginInstallerPinReleasePackage($channel, $remoteRelease);
    $groupId = @filegroup($targetParent);
    if ($groupId === false) {
        throw new Exception("Could not determine the plugin server group.");
    }
    $storageDir = chimPluginInstallerPrepareStorage($storageDir, $groupId);

    $lockPath = $storageDir . DIRECTORY_SEPARATOR . $packageName . "-installer.lock";
    $lockHandle = @fopen($lockPath, "c");
    if ($lockHandle === false || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }
        throw new Exception("Another update for this plugin is already running.");
    }
    @chmod($lockPath, 0660);
    chimPluginInstallerSetGroup($lockPath, $groupId);

    $archiveFile = null;
    $stagingDir = null;

    try {
        [$archiveFile, $downloadedUrl] = chimPluginInstallerDownloadPackage($channel, $storageDir, $packageName, $groupId);
        $stagingDir = chimPluginInstallerUniqueStoragePath($storageDir, $packageName, "install");
        if (!mkdir($stagingDir, 0775, true)) {
            throw new Exception("Failed to create staging directory.");
        }
        @chmod($stagingDir, 0775);
        chimPluginInstallerSetGroup($stagingDir, $groupId);

        $isGzip = !chimPluginInstallerStringEndsWith($archiveFile, ".tar");
        $tarFlags = $isGzip ? "-xzf" : "-xf";
        $stripComponents = max(0, (int)($channel["archive_strip_components"] ?? 1));
        chimPluginInstallerValidateArchivePaths($archiveFile, $isGzip, $stripComponents);
        $extractCmd = "tar " . $tarFlags . " " . escapeshellarg($archiveFile)
            . " -C " . escapeshellarg($stagingDir)
            . " --strip-components=" . $stripComponents
            . " --no-same-owner --no-same-permissions";

        echo "<p class='log-action'>Extracting package into an isolated staging directory...</p>\n";
        ob_start();
        system($extractCmd, $extractStatus);
        $extractOutput = ob_get_clean();
        if ($extractOutput !== "") {
            echo "<div class='system-command-output'>" . nl2br(chimPluginInstallerEscape($extractOutput)) . "</div>";
        }
        if ($extractStatus !== 0) {
            throw new Exception("Failed to extract archive from " . $downloadedUrl);
        }

        $manifest = chimPluginInstallerValidateStagedPackage(
            $stagingDir,
            $packageName,
            $channel,
            $remoteManifest,
            $remoteVersion
        );

        $preservedPaths = chimPluginInstallerPreserveMutablePaths($targetDir, $stagingDir);
        foreach ($preservedPaths as $preservedPath) {
            echo "<p class='log-info'>Preserved local setting: " . chimPluginInstallerEscape($preservedPath) . "</p>\n";
        }

        $manifestPath = $stagingDir . DIRECTORY_SEPARATOR . "manifest.json";
        $manifest["channel"] = $channel["id"];
        $manifest["channel_label"] = $channel["label"];
        $manifest["git_repo"] = $githubRepo;
        if (($channel["package_source"] ?? "") === "branch") {
            $manifest["installed_commit"] = $remoteCommit;
            $manifest["installed_branch"] = (string)($channel["branch"] ?? "");
            unset(
                $manifest["installed_release_id"],
                $manifest["installed_release_tag"],
                $manifest["installed_release_asset_id"],
                $manifest["installed_release_asset"]
            );
        } else {
            unset($manifest["installed_commit"], $manifest["installed_branch"]);
            $manifest["installed_release_id"] = (int)$remoteRelease["id"];
            $manifest["installed_release_tag"] = (string)$remoteRelease["tag"];
            $manifest["installed_release_asset_id"] = (int)$remoteRelease["asset_id"];
            $manifest["installed_release_asset"] = (string)$remoteRelease["asset_name"];
        }
        $encodedManifest = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encodedManifest === false || @file_put_contents($manifestPath, $encodedManifest . "\n") === false) {
            throw new Exception("Failed to write installer metadata to the staged manifest.");
        }

        // Dependencies are built before activation so a Composer failure cannot damage the live plugin.
        chimPluginInstallerRunComposer($stagingDir);
        chimPluginInstallerApplyTreePermissions($stagingDir, $groupId);

        echo "<p class='log-action'>Activating staged files with protected filesystem rollback...</p>\n";
        $activation = chimPluginInstallerActivateStaging(
            $stagingDir,
            $targetDir,
            $storageDir,
            function ($activeDir) use ($packageName) {
                echo "<p class='log-info'>Checking for database migrations...</p>\n";
                if (!chimPluginInstallerRunMigrations($activeDir, $packageName)) {
                    throw new Exception("Failed to run database migrations.");
                }
            }
        );
        $stagingDir = null;

        if (($activation["cleanup_warning"] ?? "") !== "") {
            echo "<p class='log-error'>" . chimPluginInstallerEscape($activation["cleanup_warning"]) . "</p>\n";
        }
        echo "<p class='log-success'>Package successfully installed/updated.</p>\n";
        return true;
    } finally {
        if ($archiveFile !== null && (is_file($archiveFile) || is_link($archiveFile))) {
            @unlink($archiveFile);
        }
        if ($stagingDir !== null && is_dir($stagingDir)) {
            chimPluginInstallerRemoveDirectory($stagingDir);
        }
        @flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

if (defined("CHIM_PLUGIN_INSTALLER_FUNCTIONS_ONLY") && CHIM_PLUGIN_INSTALLER_FUNCTIONS_ONLY) {
    return;
}

$requestMethod = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
$isPostRequest = $requestMethod === "POST";
$requestInput = $isPostRequest ? $_POST : $_GET;
$pluginId = (string)($requestInput["PLUGIN_ID"] ?? "");
$legacyPackageName = (string)($requestInput["PACKAGE_NAME"] ?? "");
$legacyGithubRepo = (string)($requestInput["GITHUB_REPO"] ?? "");
$requestedChannel = (string)($requestInput["CHANNEL"] ?? "");
$forceInstall = isset($requestInput["FORCE"]) && $requestInput["FORCE"] !== "0";
$isAuthorizedMutation = $isPostRequest && chimPluginInstallerIsSameOriginMutationRequest($_SERVER);

$errors = [];
if ($isPostRequest && !$isAuthorizedMutation) {
    http_response_code(403);
    $errors[] = "Installation request rejected. Use the confirmation button from this same CHIM server.";
}

$repositoryEntry = chimPluginInstallerFindTrustedCatalogEntry(
    $pluginRepository,
    $pluginId,
    $legacyPackageName,
    $legacyGithubRepo,
    $isPostRequest
);
$packageName = "";
$githubRepo = "";
if (!is_array($repositoryEntry)) {
    $errors[] = "Plugin is not a trusted entry in the CHIM plugin repository.";
} else {
    $pluginId = (string)$repositoryEntry["_plugin_id"];
    $packageName = (string)($repositoryEntry["name"] ?? "");
    $githubRepo = (string)($repositoryEntry["git_repo"] ?? "");
}
if ($packageName === "" || !chimPluginInstallerEnsurePluginName($packageName)) {
    $errors[] = "Trusted catalog contains an invalid package name.";
}
if ($githubRepo === "" || !chimPluginInstallerEnsureGithubRepo($githubRepo)) {
    $errors[] = "Trusted catalog contains an invalid GitHub repository.";
}

$extRoot = realpath($enginePath . "ext");
$targetDir = "";
if (empty($errors)) {
    try {
        $targetDir = chimPluginInstallerResolveDirectChild($extRoot, $packageName);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
$localManifest = $targetDir !== ""
    ? chimPluginInstallerReadJson($targetDir . DIRECTORY_SEPARATOR . "manifest.json")
    : false;
$channels = empty($errors)
    ? chimPluginInstallerNormalizeChannels($repositoryEntry, $packageName, $githubRepo)
    : [];
$currentChannel = is_array($localManifest) ? (string)($localManifest["channel"] ?? "") : "";
$defaultChannel = (string)((is_array($repositoryEntry) ? ($repositoryEntry["default_channel"] ?? "") : "") ?: ($currentChannel ?: "main"));
$requestedChannel = $requestedChannel !== "" ? $requestedChannel : $defaultChannel;
if (empty($errors) && !isset($channels[$requestedChannel])) {
    $errors[] = "Unknown plugin channel: " . $requestedChannel;
}

$channel = empty($errors) ? $channels[$requestedChannel] : null;
if ($channel && !chimPluginInstallerForceAllowed($forceInstall, $channel)) {
    $errors[] = "Forced reinstall is not allowed for this plugin channel.";
}

$remoteCommit = "";
$remoteManifest = false;
$remoteRelease = [];
$remoteVersion = "";
if ($channel && empty($errors)) {
    if (($channel["package_source"] ?? "") === "branch") {
        $remoteCommit = chimPluginInstallerGetRemoteCommit($channel, $githubRepo);
        $remoteManifest = chimPluginInstallerGetRemoteManifest($channel, $githubRepo, $remoteCommit);
        $remoteVersion = is_array($remoteManifest) ? (string)($remoteManifest["version"] ?? "") : "";
        if (!is_array($remoteManifest) || $remoteVersion === "") {
            $errors[] = "Could not retrieve a valid manifest from the selected branch commit.";
        }
        if ($remoteCommit === false || $remoteCommit === "") {
            $errors[] = "Could not resolve the selected branch commit. GitHub may be unavailable or API-rate-limited.";
        }
    } elseif (($channel["package_source"] ?? "") === "release") {
        $remoteRelease = chimPluginInstallerGetRemoteRelease($channel, $githubRepo, $packageName);
        if (!is_array($remoteRelease) || empty($remoteRelease["version"])) {
            $errors[] = "Could not resolve the latest release to an exact downloadable asset.";
        } else {
            $remoteVersion = (string)$remoteRelease["version"];
        }
    } else {
        $errors[] = "Trusted catalog channel has an unsupported package source.";
    }
}
if ($channel && $remoteVersion !== "") {
    $channel["package_urls"] = array_map(function ($url) use ($remoteVersion, $remoteCommit) {
        return strtr((string)$url, [
            "<version>" => $remoteVersion,
            "<commit>" => (string)$remoteCommit,
        ]);
    }, $channel["package_urls"]);
}

$currentVersion = is_array($localManifest) ? (string)($localManifest["version"] ?? "") : "";
$currentCommit = is_array($localManifest) ? (string)($localManifest["installed_commit"] ?? "") : "";
$currentReleaseAssetId = is_array($localManifest) ? (int)($localManifest["installed_release_asset_id"] ?? 0) : 0;
$installed = is_array($localManifest);
$channelChanged = $installed && $currentChannel !== "" && $currentChannel !== $requestedChannel;
$updateAvailable = !$installed || $channelChanged || $forceInstall;
if (!$updateAvailable && $remoteVersion !== "" && $currentVersion !== "") {
    $updateAvailable = version_compare($remoteVersion, $currentVersion, ">");
}
if (!$updateAvailable && $channel && ($channel["package_source"] ?? "") === "branch") {
    $updateAvailable = !chimPluginInstallerCommitsMatch($currentCommit, $remoteCommit);
}
if (!$updateAvailable && $channel && ($channel["package_source"] ?? "") === "release") {
    $updateAvailable = $currentReleaseAssetId === 0
        || $currentReleaseAssetId !== (int)($remoteRelease["asset_id"] ?? 0);
}

$storageDir = trim((string)getenv("CHIM_PLUGIN_INSTALLER_STORAGE_DIR"));
if ($storageDir === "") {
    $storageDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . "chim-plugin-installer";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHIM Plugin Installer: <?php echo chimPluginInstallerEscape($packageName); ?></title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/main.css">
    <style>
        body { padding: 20px; background-color: #2c2c2c; color: #f8f9fa; font-family: 'Futura CondensedLight', Arial, sans-serif; }
        .installer-container { max-width: 900px; margin: 40px auto; background-color: #1a1a1a; padding: 30px; border-radius: 8px; border: 1px solid #3a3a3a; }
        h1 { color: #fff; text-align: center; }
        h3 { color: rgb(242, 124, 17); margin-top: 30px; border-bottom: 1px solid #3a3a3a; padding-bottom: 8px; }
        .version-info-block { background-color: #2d2d2d; padding: 20px; border-radius: 6px; margin-bottom: 30px; border: 1px solid #4a4a4a; }
        .installer-log { background-color: #111; color: #ccc; padding: 20px; border-radius: 6px; font-family: 'Spline Sans Mono', monospace; font-size: 14px; white-space: pre-wrap; word-wrap: break-word; max-height: 450px; overflow-y: auto; border: 1px solid #333; margin-top: 15px; }
        .installer-log p { margin: 6px 0; padding: 3px 0; line-height: 1.5; }
        .log-info { color: #5bc0de; }
        .log-action, .log-running { color: #f0ad4e; }
        .log-completed, .log-success { color: #28a745; }
        .log-skipped { color: #888; }
        .log-error, .log-failed { color: #d9534f; font-weight: bold; }
        .system-command-output { border-left: 3px solid #444; padding-left: 10px; margin: 8px 0 12px 15px; font-size: 0.85em; color: #aaa; }
        .status-message { padding: 15px 20px; margin-top: 25px; border-radius: 6px; font-weight: bold; text-align: center; }
        .status-success { background-color: #28a745; color: white; border: 1px solid #1e7e34; }
        .status-error { background-color: #d9534f; color: white; border: 1px solid #c9302c; }
    </style>
</head>
<body>
    <div class="installer-container">
        <h1>CHIM Plugin Installer</h1>
        <a href="core/config_hub.php?tab=serverplugins" class="button btn-primary">&laquo; Back to Plugin Manager</a>

        <div class="version-info-block">
            <h3>Version Information</h3>
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <p class="log-error"><?php echo chimPluginInstallerEscape($error); ?></p>
                <?php endforeach; ?>
            <?php else: ?>
                <p><strong>Package:</strong> <?php echo chimPluginInstallerEscape($packageName); ?></p>
                <p><strong>GitHub Repo:</strong> <?php echo chimPluginInstallerEscape($githubRepo); ?></p>
                <p><strong>Selected Channel:</strong> <?php echo chimPluginInstallerEscape($channel["label"]); ?> <span style="color:#aaa;">(<?php echo chimPluginInstallerEscape($channel["id"]); ?>)</span></p>
                <?php if ($installed): ?>
                    <p><strong>Current Version:</strong> <?php echo chimPluginInstallerEscape($currentVersion); ?></p>
                    <p><strong>Current Channel:</strong> <?php echo chimPluginInstallerEscape($currentChannel ?: "legacy"); ?></p>
                    <?php if ($currentCommit !== ""): ?>
                        <p><strong>Installed Commit:</strong> <?php echo chimPluginInstallerEscape(substr($currentCommit, 0, 12)); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($remoteVersion !== ""): ?>
                    <p><strong>Remote Version:</strong> <?php echo chimPluginInstallerEscape($remoteVersion); ?></p>
                <?php else: ?>
                    <p class="log-error">Could not retrieve remote version information for this channel.</p>
                <?php endif; ?>
                <?php if (is_string($remoteCommit) && $remoteCommit !== ""): ?>
                    <p><strong>Remote Commit:</strong> <?php echo chimPluginInstallerEscape(substr($remoteCommit, 0, 12)); ?></p>
                <?php endif; ?>
                <p><strong>Install Needed:</strong> <?php echo $updateAvailable ? "Yes" : "No"; ?></p>
            <?php endif; ?>
        </div>

        <?php
        if (!empty($errors)) {
            echo '<div class="status-message status-error">Could not proceed due to installer configuration errors.</div>';
        } elseif (chimPluginInstallerShouldExecuteInstall($isAuthorizedMutation, $updateAvailable, $errors)) {
            echo '<h3>Installation Log</h3>';
            echo '<div class="installer-log">';
            try {
                chimPluginInstallerInstallPackage(
                    $channel,
                    $targetDir,
                    $packageName,
                    $githubRepo,
                    $remoteManifest,
                    $remoteCommit,
                    $remoteVersion,
                    $remoteRelease,
                    $storageDir
                );
                echo '</div>';
                echo '<div class="status-message status-success">Installation/update process completed.</div>';
            } catch (Throwable $e) {
                echo '<p class="log-error">Error: ' . chimPluginInstallerEscape($e->getMessage()) . '</p>';
                echo '</div>';
                echo '<div class="status-message status-error">Installation/update process failed.</div>';
            }
        } elseif ($updateAvailable) {
            echo '<h3>Ready to Install</h3>';
            echo '<p>This page has only checked the trusted catalog and remote package. No files have been changed.</p>';
            echo '<form id="chim-plugin-install-form" method="post">';
            echo '<input type="hidden" name="PLUGIN_ID" value="' . chimPluginInstallerEscape($pluginId) . '">';
            echo '<input type="hidden" name="CHANNEL" value="' . chimPluginInstallerEscape($requestedChannel) . '">';
            if ($forceInstall) {
                echo '<input type="hidden" name="FORCE" value="1">';
            }
            echo '<button type="submit" class="button btn-primary">Confirm Install/Update</button>';
            echo '</form>';
            echo '<div id="chim-plugin-install-status" class="log-info" style="margin-top:12px;"></div>';
        } else {
            echo '<div class="status-message status-success">Plugin is already installed and up to date for this channel.</div>';
        }
        ?>
    </div>
    <script>
    (() => {
        const form = document.getElementById('chim-plugin-install-form');
        if (!form) return;
        const status = document.getElementById('chim-plugin-install-status');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            status.textContent = 'Installing from the trusted catalog...';
            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CHIM-Plugin-Action': 'install' },
                    body: new FormData(form)
                });
                const html = await response.text();
                document.open();
                document.write(html);
                document.close();
            } catch (error) {
                status.textContent = 'Install request failed: ' + error.message;
                button.disabled = false;
            }
        });
    })();
    </script>
</body>
</html>
