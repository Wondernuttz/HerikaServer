<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

define('CHIM_PLUGIN_INSTALLER_FUNCTIONS_ONLY', true);
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
    . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'server_plugin_installer.php';

final class PluginInstallerTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'chim-plugin-installer-test-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->fixtureRoot, 0755, true));
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureRoot)) {
            chimPluginInstallerDeletePath($this->fixtureRoot, false);
        }
    }

    private function writeJson(string $path, array $value): void
    {
        $parent = dirname($path);
        if (!is_dir($parent)) {
            $this->assertTrue(mkdir($parent, 0755, true));
        }
        $this->assertNotFalse(file_put_contents(
            $path,
            json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        ));
    }

    private function makeStorage(): string
    {
        $storage = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'protected-storage';
        $this->assertTrue(mkdir($storage, 0770, true));
        return $storage;
    }

    public function testPackageNamesAndTargetsCannotEscapeExtRoot(): void
    {
        foreach (['.', '..', 'plugin/child', 'plugin\\child'] as $invalidName) {
            $this->assertFalse(chimPluginInstallerIsValidPackageName($invalidName));
        }
        $ext = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'ext';
        $this->assertTrue(mkdir($ext, 0755, true));
        $this->assertSame(
            $ext . DIRECTORY_SEPARATOR . 'aiagent_nsfw',
            chimPluginInstallerResolveDirectChild($ext, 'aiagent_nsfw')
        );
        $this->expectException(RuntimeException::class);
        chimPluginInstallerResolveDirectChild($ext, '..');
    }

    public function testOnlySameOriginCustomHeaderPostIsAMutation(): void
    {
        $valid = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_CHIM_PLUGIN_ACTION' => 'install',
            'HTTP_ORIGIN' => 'http://172.25.137.232:8081',
            'HTTP_HOST' => '172.25.137.232:8081',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ];
        $this->assertTrue(chimPluginInstallerIsSameOriginMutationRequest($valid));
        $this->assertFalse(chimPluginInstallerIsSameOriginMutationRequest(array_merge($valid, ['REQUEST_METHOD' => 'GET'])));
        $this->assertFalse(chimPluginInstallerIsSameOriginMutationRequest(array_merge($valid, ['HTTP_X_CHIM_PLUGIN_ACTION' => ''])));
        $this->assertFalse(chimPluginInstallerIsSameOriginMutationRequest(array_merge($valid, ['HTTP_ORIGIN' => 'http://evil.example'])));
        $this->assertFalse(chimPluginInstallerIsSameOriginMutationRequest(array_merge($valid, ['HTTP_SEC_FETCH_SITE' => 'cross-site'])));
        $this->assertFalse(chimPluginInstallerShouldExecuteInstall(false, true, []));
        $this->assertFalse(chimPluginInstallerShouldExecuteInstall(true, true, ['catalog error']));
        $this->assertTrue(chimPluginInstallerShouldExecuteInstall(true, true, []));
    }

    public function testTrustedCatalogOverridesAStaleForkAndPostRequiresPluginId(): void
    {
        $catalog = [
            'sharmat' => [
                'name' => 'aiagent_nsfw',
                'git_repo' => 'Wondernuttz/Sharmat-Alpha',
            ],
        ];
        $entry = chimPluginInstallerFindTrustedCatalogEntry(
            $catalog,
            '',
            'aiagent_nsfw',
            'maister-sk/aiagent_nsfw',
            false
        );
        $this->assertSame('sharmat', $entry['_plugin_id']);
        $this->assertSame('Wondernuttz/Sharmat-Alpha', $entry['git_repo']);
        $this->assertFalse(chimPluginInstallerFindTrustedCatalogEntry(
            $catalog,
            '',
            'aiagent_nsfw',
            'maister-sk/aiagent_nsfw',
            true
        ));
        $this->assertFalse(chimPluginInstallerFindTrustedCatalogEntry(
            $catalog,
            'not-in-catalog',
            '',
            '',
            true
        ));
    }

    public function testForceRequiresExplicitChannelPermission(): void
    {
        $this->assertTrue(chimPluginInstallerForceAllowed(false, ['allow_force' => false]));
        $this->assertFalse(chimPluginInstallerForceAllowed(true, ['allow_force' => false]));
        $this->assertTrue(chimPluginInstallerForceAllowed(true, ['allow_force' => true]));
    }

    public function testMutablePathCannotEscapePluginRoot(): void
    {
        $this->expectException(RuntimeException::class);
        chimPluginInstallerNormalizeMutablePath('../outside.php');
    }

    public function testCommitComparisonAcceptsRecordedShortSha(): void
    {
        $this->assertTrue(chimPluginInstallerCommitsMatch(
            'ecfffa4',
            'ecfffa4bf21a8e911fe9e853b7e1335972c3906c'
        ));
        $this->assertFalse(chimPluginInstallerCommitsMatch(
            'deadbee',
            'ecfffa4bf21a8e911fe9e853b7e1335972c3906c'
        ));
    }

    public function testMainBranchPackageIsPinnedToTheResolvedCommit(): void
    {
        $channels = chimPluginInstallerNormalizeChannels([
            'channels' => [
                'main' => [
                    'label' => 'Live',
                    'branch' => 'main',
                    'package_source' => 'branch',
                ],
            ],
        ], 'aiagent_nsfw', 'Wondernuttz/Sharmat-Alpha');

        $this->assertSame('branch', $channels['main']['package_source']);
        $commit = 'ecfffa4bf21a8e911fe9e853b7e1335972c3906c';
        $pinned = chimPluginInstallerPinBranchPackage(
            $channels['main'],
            'Wondernuttz/Sharmat-Alpha',
            $commit
        );
        $this->assertSame([
            'https://github.com/Wondernuttz/Sharmat-Alpha/archive/' . $commit . '.tar.gz',
        ], $pinned['package_urls']);
    }

    public function testReleasePackageIsPinnedToTheExactAsset(): void
    {
        $release = [
            'id' => 42,
            'tag' => 'v1.2.3',
            'version' => '1.2.3',
            'asset_id' => 99,
            'asset_name' => 'plugin.tar.gz',
            'asset_url' => 'https://github.com/example/plugin/releases/download/v1.2.3/plugin.tar.gz',
        ];
        $pinned = chimPluginInstallerPinReleasePackage(['package_source' => 'release'], $release);
        $this->assertSame([$release['asset_url']], $pinned['package_urls']);
        $this->assertSame('1.2.3', chimPluginInstallerNormalizeReleaseVersion('v1.2.3'));
    }

    public function testStagedUpdatePreservesMutableFilesAndRemovesDeletedCode(): void
    {
        $ext = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'ext-update';
        $this->assertTrue(mkdir($ext, 0755, true));
        $target = $ext . DIRECTORY_SEPARATOR . 'aiagent_nsfw';
        $storage = $this->makeStorage();
        $staging = $storage . DIRECTORY_SEPARATOR . 'aiagent_nsfw-install-test';
        $this->assertTrue(mkdir($target . DIRECTORY_SEPARATOR . 'conf', 0755, true));
        $this->assertTrue(mkdir($staging . DIRECTORY_SEPARATOR . 'conf', 0755, true));

        $descriptor = [
            'schema_version' => 4,
            'name' => 'aiagent_nsfw',
            'version' => '3.1.9',
            'server' => ['mutable_paths' => ['conf/conf.php']],
        ];
        $this->writeJson($target . DIRECTORY_SEPARATOR . 'dwemer-package.json', $descriptor);
        $this->writeJson($staging . DIRECTORY_SEPARATOR . 'dwemer-package.json', $descriptor);

        file_put_contents($target . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'conf.php', 'local-user-setting');
        file_put_contents($target . DIRECTORY_SEPARATOR . 'deleted-upstream.php', 'old-code');
        file_put_contents($staging . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'conf.php', 'new-default');
        file_put_contents($staging . DIRECTORY_SEPARATOR . 'new-code.php', 'new-code');

        $preserved = chimPluginInstallerPreserveMutablePaths($target, $staging);
        $this->assertSame(['conf/conf.php'], $preserved);

        $callbackRan = false;
        $activation = chimPluginInstallerActivateStaging(
            $staging,
            $target,
            $storage,
            function (string $activeDir) use (&$callbackRan): void {
                $callbackRan = true;
                $this->assertFileExists($activeDir . DIRECTORY_SEPARATOR . 'new-code.php');
            }
        );

        $this->assertTrue($callbackRan);
        $this->assertTrue($activation['activated']);
        $this->assertSame('', $activation['cleanup_warning']);
        $this->assertSame(
            'local-user-setting',
            file_get_contents($target . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'conf.php')
        );
        $this->assertFileExists($target . DIRECTORY_SEPARATOR . 'new-code.php');
        $this->assertFileDoesNotExist($target . DIRECTORY_SEPARATOR . 'deleted-upstream.php');
        $this->assertDirectoryDoesNotExist($staging);
        $this->assertSame([], glob($storage . DIRECTORY_SEPARATOR . 'aiagent_nsfw-backup-*'));
    }

    public function testPostActivationFailureRestoresPreviousPlugin(): void
    {
        $ext = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'ext-rollback';
        $this->assertTrue(mkdir($ext, 0755, true));
        $target = $ext . DIRECTORY_SEPARATOR . 'plugin';
        $storage = $this->makeStorage();
        $staging = $storage . DIRECTORY_SEPARATOR . 'plugin-install-test';
        $this->assertTrue(mkdir($target, 0755, true));
        $this->assertTrue(mkdir($staging, 0755, true));
        file_put_contents($target . DIRECTORY_SEPARATOR . 'old.php', 'working');
        file_put_contents($staging . DIRECTORY_SEPARATOR . 'new.php', 'broken');

        try {
            chimPluginInstallerActivateStaging(
                $staging,
                $target,
                $storage,
                function (): void {
                    throw new RuntimeException('post-install failed');
                }
            );
            $this->fail('Activation should have failed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('post-install failed', $e->getMessage());
        }

        $this->assertFileExists($target . DIRECTORY_SEPARATOR . 'old.php');
        $this->assertFileDoesNotExist($target . DIRECTORY_SEPARATOR . 'new.php');
        $this->assertSame('working', file_get_contents($target . DIRECTORY_SEPARATOR . 'old.php'));
    }

    public function testActivationRejectsStorageInsideWebServedExt(): void
    {
        $ext = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'ext-storage-check';
        $storage = $ext . DIRECTORY_SEPARATOR . '.installer-storage';
        $staging = $storage . DIRECTORY_SEPARATOR . 'plugin-install-test';
        $this->assertTrue(mkdir($staging, 0755, true));
        $target = $ext . DIRECTORY_SEPARATOR . 'plugin';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside');
        chimPluginInstallerActivateStaging($staging, $target, $storage);
    }

    public function testMutablePathRejectsSymlinkInSourceIntermediateComponent(): void
    {
        $target = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'target';
        $staging = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'staging';
        $outside = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'outside';
        $this->assertTrue(mkdir($target, 0755, true));
        $this->assertTrue(mkdir($staging, 0755, true));
        $this->assertTrue(mkdir($outside, 0755, true));
        file_put_contents($outside . DIRECTORY_SEPARATOR . 'conf.php', 'secret');
        $descriptor = ['server' => ['mutable_paths' => ['conf/conf.php']]];
        $this->writeJson($target . DIRECTORY_SEPARATOR . 'dwemer-package.json', $descriptor);
        $this->writeJson($staging . DIRECTORY_SEPARATOR . 'dwemer-package.json', $descriptor);
        $this->assertTrue(symlink($outside, $target . DIRECTORY_SEPARATOR . 'conf'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink');
        chimPluginInstallerPreserveMutablePaths($target, $staging);
    }

    public function testMutablePathRejectsSymlinkInDestinationIntermediateComponent(): void
    {
        $target = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'target-destination-check';
        $staging = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'staging-destination-check';
        $outside = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'outside-destination-check';
        $this->assertTrue(mkdir($target . DIRECTORY_SEPARATOR . 'conf', 0755, true));
        $this->assertTrue(mkdir($staging, 0755, true));
        $this->assertTrue(mkdir($outside, 0755, true));
        file_put_contents($target . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'conf.php', 'setting');
        $descriptor = ['server' => ['mutable_paths' => ['conf/conf.php']]];
        $this->writeJson($target . DIRECTORY_SEPARATOR . 'dwemer-package.json', $descriptor);
        $this->writeJson($staging . DIRECTORY_SEPARATOR . 'dwemer-package.json', $descriptor);
        $this->assertTrue(symlink($outside, $staging . DIRECTORY_SEPARATOR . 'conf'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink');
        chimPluginInstallerPreserveMutablePaths($target, $staging);
    }

    public function testPermissionPassSetsServerWritableModesAndGroup(): void
    {
        $root = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'permission-fixture';
        $this->assertTrue(mkdir($root . DIRECTORY_SEPARATOR . 'conf', 0700, true));
        $file = $root . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'conf.php';
        file_put_contents($file, 'setting');
        chmod($file, 0600);
        $group = filegroup($this->fixtureRoot);
        $this->assertNotFalse($group);

        chimPluginInstallerApplyTreePermissions($root, $group);

        $this->assertSame(0775, fileperms($root) & 0777);
        $this->assertSame(0775, fileperms($root . DIRECTORY_SEPARATOR . 'conf') & 0777);
        $this->assertSame(0664, fileperms($file) & 0777);
        $this->assertSame($group, filegroup($file));
    }

    public function testMigrationLexerRejectsTransactionControlButIgnoresQuotedText(): void
    {
        $stripped = chimPluginInstallerStripSqlLiteralsAndComments("'COMMIT' SELECT 1; -- ROLLBACK\n " . '$$BEGIN$$');
        $this->assertStringContainsString('SELECT 1', $stripped);
        $this->assertStringNotContainsString('COMMIT', $stripped);
        $this->assertStringNotContainsString('ROLLBACK', $stripped);
        chimPluginInstallerAssertMigrationSqlTransactional("SELECT 'COMMIT'; /* ROLLBACK */ SELECT 1;", 'safe.sql');

        $this->expectException(RuntimeException::class);
        chimPluginInstallerAssertMigrationSqlTransactional('SELECT 1; COMMIT; BEGIN;', 'unsafe.sql');
    }

    public function testMigrationFailureRollsBackEarlierSqlAndHistoryWhenPostgresIsAvailable(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgreSQL extension is unavailable.');
        }
        $connString = 'host=localhost port=5432 dbname=testdb user=dwemer password=dwemer';
        $conn = @pg_connect($connString, PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            $this->markTestSkipped('PostgreSQL test database is unavailable.');
        }

        $suffix = strtolower(bin2hex(random_bytes(5)));
        $package = 'installer_tx_' . $suffix;
        $table = 'installer_tx_table_' . $suffix;
        $target = $this->fixtureRoot . DIRECTORY_SEPARATOR . $package;
        $migrations = $target . DIRECTORY_SEPARATOR . 'migrations';
        $this->assertTrue(mkdir($migrations, 0755, true));
        file_put_contents($migrations . DIRECTORY_SEPARATOR . '001_create.sql', 'CREATE TABLE public.' . $table . ' (id INTEGER);');
        file_put_contents($migrations . DIRECTORY_SEPARATOR . '002_fail.sql', 'THIS IS NOT VALID SQL;');

        $GLOBALS['CHIM_PLUGIN_INSTALLER_DB_CONNSTR'] = $connString;
        try {
            ob_start();
            $result = chimPluginInstallerRunMigrations($target, $package);
            $output = ob_get_clean();
            $this->assertFalse($result);
            $tableResult = pg_query_params($conn, 'SELECT to_regclass($1) AS table_name', ['public.' . $table]);
            $this->assertNotFalse($tableResult);
            $this->assertEmpty(pg_fetch_assoc($tableResult)['table_name']);
            $this->assertStringContainsString('rolled back', strtolower($output));
            $this->assertStringContainsString('002_fail.sql', $output);
            $this->assertStringNotContainsString('Migration committed:', $output);
        } finally {
            unset($GLOBALS['CHIM_PLUGIN_INSTALLER_DB_CONNSTR']);
            @pg_query($conn, 'DROP TABLE IF EXISTS public.' . $table);
            @pg_query_params($conn, 'DELETE FROM plugins.plugin_migrations WHERE plugin_name = $1', [$package]);
            pg_close($conn);
        }
    }

    public function testLegacyMigrationHistoryIsImportedBeforeSqlRunsWhenPostgresIsAvailable(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgreSQL extension is unavailable.');
        }
        $connString = 'host=localhost port=5432 dbname=testdb user=dwemer password=dwemer';
        $conn = @pg_connect($connString, PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            $this->markTestSkipped('PostgreSQL test database is unavailable.');
        }

        $legacyLookup = pg_query($conn, "SELECT to_regclass('public.plugin_migrations') AS table_name");
        $legacyExisted = !empty(pg_fetch_assoc($legacyLookup)['table_name']);
        if (!$legacyExisted) {
            pg_query($conn, 'CREATE TABLE public.plugin_migrations (plugin_name VARCHAR(255), migration_name VARCHAR(255), executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (plugin_name, migration_name))');
        }
        $columns = pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='plugin_migrations'");
        $columnNames = [];
        while ($column = pg_fetch_assoc($columns)) {
            $columnNames[$column['column_name']] = true;
        }
        if (!isset($columnNames['plugin_name'], $columnNames['migration_name'])) {
            pg_close($conn);
            $this->markTestSkipped('Legacy migration table has an incompatible test schema.');
        }

        $suffix = strtolower(bin2hex(random_bytes(5)));
        $package = 'installer_legacy_' . $suffix;
        $table = 'installer_legacy_table_' . $suffix;
        $migrationName = '001_legacy.sql';
        $target = $this->fixtureRoot . DIRECTORY_SEPARATOR . $package;
        $migrations = $target . DIRECTORY_SEPARATOR . 'migrations';
        $this->assertTrue(mkdir($migrations, 0755, true));
        file_put_contents($migrations . DIRECTORY_SEPARATOR . $migrationName, 'CREATE TABLE public.' . $table . ' (id INTEGER);');
        pg_query_params(
            $conn,
            'INSERT INTO public.plugin_migrations (plugin_name, migration_name) VALUES ($1, $2)',
            [$package, $migrationName]
        );

        $GLOBALS['CHIM_PLUGIN_INSTALLER_DB_CONNSTR'] = $connString;
        try {
            ob_start();
            $result = chimPluginInstallerRunMigrations($target, $package);
            $output = ob_get_clean();
            $this->assertTrue($result);
            $tableResult = pg_query_params($conn, 'SELECT to_regclass($1) AS table_name', ['public.' . $table]);
            $this->assertEmpty(pg_fetch_assoc($tableResult)['table_name']);
            $history = pg_query_params(
                $conn,
                'SELECT 1 FROM plugins.plugin_migrations WHERE plugin_name=$1 AND migration_name=$2',
                [$package, $migrationName]
            );
            $this->assertSame(1, pg_num_rows($history));
            $this->assertStringContainsString('Skipping already executed migration', $output);
        } finally {
            unset($GLOBALS['CHIM_PLUGIN_INSTALLER_DB_CONNSTR']);
            @pg_query($conn, 'DROP TABLE IF EXISTS public.' . $table);
            @pg_query_params($conn, 'DELETE FROM plugins.plugin_migrations WHERE plugin_name = $1', [$package]);
            @pg_query_params($conn, 'DELETE FROM public.plugin_migrations WHERE plugin_name = $1', [$package]);
            if (!$legacyExisted) {
                @pg_query($conn, 'DROP TABLE public.plugin_migrations');
            }
            pg_close($conn);
        }
    }

    public function testConcurrentMigrationRunsExecuteEachFileOnceWhenPostgresIsAvailable(): void
    {
        if (!function_exists('pg_connect') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('PostgreSQL or pcntl support is unavailable.');
        }
        $connString = 'host=localhost port=5432 dbname=testdb user=dwemer password=dwemer';
        $probe = @pg_connect($connString, PGSQL_CONNECT_FORCE_NEW);
        if ($probe === false) {
            $this->markTestSkipped('PostgreSQL test database is unavailable.');
        }
        pg_close($probe);

        $suffix = strtolower(bin2hex(random_bytes(5)));
        $package = 'installer_concurrent_' . $suffix;
        $table = 'installer_concurrent_table_' . $suffix;
        $migrationName = '001_concurrent.sql';
        $target = $this->fixtureRoot . DIRECTORY_SEPARATOR . $package;
        $migrations = $target . DIRECTORY_SEPARATOR . 'migrations';
        $this->assertTrue(mkdir($migrations, 0755, true));
        file_put_contents(
            $migrations . DIRECTORY_SEPARATOR . $migrationName,
            'CREATE TABLE public.' . $table . ' (id INTEGER); SELECT pg_sleep(0.5);'
        );

        $GLOBALS['CHIM_PLUGIN_INSTALLER_DB_CONNSTR'] = $connString;
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    ob_start();
                    $ok = chimPluginInstallerRunMigrations($target, $package);
                    ob_end_clean();
                    exit($ok ? 0 : 1);
                }
                $children[] = $pid;
            }

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $conn = pg_connect($connString, PGSQL_CONNECT_FORCE_NEW);
            $history = pg_query_params(
                $conn,
                'SELECT COUNT(*) AS count FROM plugins.plugin_migrations WHERE plugin_name=$1 AND migration_name=$2',
                [$package, $migrationName]
            );
            $this->assertSame('1', pg_fetch_assoc($history)['count']);
            $this->assertNotEmpty(pg_fetch_assoc(pg_query_params(
                $conn,
                'SELECT to_regclass($1) AS table_name',
                ['public.' . $table]
            ))['table_name']);
        } finally {
            unset($GLOBALS['CHIM_PLUGIN_INSTALLER_DB_CONNSTR']);
            $cleanup = @pg_connect($connString, PGSQL_CONNECT_FORCE_NEW);
            if ($cleanup !== false) {
                @pg_query($cleanup, 'DROP TABLE IF EXISTS public.' . $table);
                @pg_query_params($cleanup, 'DELETE FROM plugins.plugin_migrations WHERE plugin_name=$1', [$package]);
                pg_close($cleanup);
            }
            if (isset($conn) && $conn) {
                pg_close($conn);
            }
        }
    }

    public function testPinnedSharmatBranchInstallsEndToEndWhenNetworkTestsAreEnabled(): void
    {
        if (getenv('CHIM_PLUGIN_INSTALLER_NETWORK_TESTS') !== '1') {
            $this->markTestSkipped('Network integration tests are disabled.');
        }
        $channels = chimPluginInstallerNormalizeChannels([
            'channels' => [
                'main' => [
                    'label' => 'Live',
                    'branch' => 'main',
                    'package_source' => 'branch',
                    'allow_force' => true,
                ],
            ],
        ], 'aiagent_nsfw', 'Wondernuttz/Sharmat-Alpha');
        $channel = $channels['main'];
        $commit = chimPluginInstallerGetRemoteCommit($channel, 'Wondernuttz/Sharmat-Alpha');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $commit);
        $manifest = chimPluginInstallerGetRemoteManifest(
            $channel,
            'Wondernuttz/Sharmat-Alpha',
            $commit
        );
        $this->assertIsArray($manifest);

        $ext = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'network-ext';
        $storage = $this->fixtureRoot . DIRECTORY_SEPARATOR . 'network-storage';
        $this->assertTrue(mkdir($ext, 0775, true));
        $target = chimPluginInstallerResolveDirectChild($ext, 'aiagent_nsfw');
        ob_start();
        try {
            $this->assertTrue(chimPluginInstallerInstallPackage(
                $channel,
                $target,
                'aiagent_nsfw',
                'Wondernuttz/Sharmat-Alpha',
                $manifest,
                $commit,
                (string)$manifest['version'],
                [],
                $storage
            ));
        } finally {
            ob_end_clean();
        }

        $installed = chimPluginInstallerReadJson($target . DIRECTORY_SEPARATOR . 'manifest.json');
        $this->assertSame($commit, $installed['installed_commit']);
        $this->assertSame('Wondernuttz/Sharmat-Alpha', $installed['git_repo']);
        $this->assertSame(0775, fileperms($target) & 0777);
        $this->assertSame(0664, fileperms($target . DIRECTORY_SEPARATOR . 'manifest.json') & 0777);
    }
}
