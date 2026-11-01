<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

/**
 * Safety conditions 1, 2, 4 and 6 against the real sealed stack. Each test saves a transcript as evidence.
 */
final class SealedStackTest extends StackTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::bringUp('-t', 'measure.compose.yaml');
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownStack();
    }

    public function testNetworkIsInternalAndNoPortIsPublished(): void
    {
        [$code, $out] = self::exec(['docker', 'network', 'ls', '--filter', 'label=com.docker.compose.project=' . self::$project, '--format', '{{.Name}}']);
        $this->assertSame(0, $code);
        $names = array_values(array_filter(explode("\n", trim($out))));
        $this->assertCount(1, $names, 'the stack must have exactly one network');

        $inspect = self::exec(['docker', 'network', 'inspect', $names[0], '--format', 'internal={{.Internal}} driver={{.Driver}}']);
        $this->assertSame('internal=true driver=bridge', trim($inspect[1]));

        $published = self::exec(['docker', 'ps', '--filter', 'label=com.docker.compose.project=' . self::$project, '--format', '{{.Names}} ports=[{{.Ports}}]']);
        $this->assertSame(0, $published[0]);
        foreach (array_filter(explode("\n", trim($published[1]))) as $line) {
            // EXPOSEd ports (3306/tcp) are listed without an arrow; a published port would read 0.0.0.0:N->3306/tcp.
            $this->assertStringNotContainsString('->', $line, 'no port may be published: ' . $line);
        }
        self::evidence('egress', 'network-internal.txt', self::transcript('docker network inspect <stack network>', $inspect) . "\n" . self::transcript('docker ps (ports of the stack)', $published));
    }

    public function testPreflightPassesOnTheSealedStack(): void
    {
        $result = self::bin('preflight');
        self::evidence('egress', 'preflight-sealed-pass.txt', self::transcript('bin/preflight', $result));

        $this->assertSame(0, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString('PREFLIGHT OK', $result[1]);
        $this->assertStringContainsString('internal=true', $result[1]);
        $this->assertSame(0, substr_count($result[1], 'FAIL '));
        $this->assertStringContainsString('PASS negative dns:example.com', $result[1]);
        $this->assertStringContainsString('PASS positive tcp:measure-db:3306', $result[1]);
        $this->assertStringContainsString('PASS positive login:measure', $result[1]);
    }

    public function testEveryOutboundAttemptFromInsideFailsAndIsRecorded(): void
    {
        $result = self::bin('canary-egress');
        self::evidence('egress', 'canary-egress-blocked.txt', self::transcript('bin/canary-egress', $result));

        $this->assertSame(0, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString('leaked=0', $result[1]);
        $this->assertSame(0, substr_count($result[1], 'LEAK '));
        $this->assertGreaterThanOrEqual(4, substr_count($result[1], 'BLOCKED '));
    }

    public function testMeasureUserCannotTouchAnyOtherDatabase(): void
    {
        $this->assertSame(0, self::rootSql('CREATE DATABASE IF NOT EXISTS app; CREATE TABLE IF NOT EXISTS app.sentinel (id INT); INSERT INTO app.sentinel VALUES (1)')[0]);

        $code = <<<'PHP'
            use PeakSim\Runtime\Config\MeasureConfig;
            $c = MeasureConfig::fromEnv(getenv());
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d', $c->dbHost, $c->dbPort), $c->dbUser, $c->dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach (['DROP DATABASE app', 'CREATE DATABASE other_db', 'SELECT * FROM app.sentinel', 'DROP DATABASE mysql', 'CREATE USER x@y IDENTIFIED BY \'z\''] as $sql) {
                try { $pdo->exec($sql); echo "ALLOWED $sql\n"; }
                catch (PDOException $e) { echo "DENIED  $sql => " . ($e->errorInfo[1] ?? '?') . "\n"; }
            }
            PHP;
        $result = self::phpCode($code);
        $check = self::rootSql('SELECT COUNT(*) FROM app.sentinel');
        self::evidence('db', 'measure-user-denied.txt', self::transcript('measure user: DROP DATABASE app, CREATE DATABASE other_db, ...', $result) . "\n" . self::transcript('root: SELECT COUNT(*) FROM app.sentinel (after the attempts)', $check));

        $this->assertSame(0, $result[0], $result[1] . $result[2]);
        $this->assertStringNotContainsString('ALLOWED', $result[1]);
        $this->assertSame(5, substr_count($result[1], 'DENIED'));
        $this->assertStringContainsString('DENIED  DROP DATABASE app => 1044', $result[1]);
        $this->assertStringContainsString('DENIED  CREATE DATABASE other_db => 1044', $result[1]);
        $this->assertSame('1', trim($check[1]), 'the other database must be untouched');
    }

    public function testMeasureUserCanCreateAndDropAMeasurementDatabaseOnly(): void
    {
        $name = 'peak_sim_measure_' . self::$stackEnv['PEAK_MEASURE_RUN_ID'] . '_100';
        $code = <<<'PHP'
            use PeakSim\Runtime\Config\MeasureConfig;
            use PeakSim\Runtime\Config\MeasureRefused;
            use PeakSim\Runtime\Guard\MeasureDb;
            $c = MeasureConfig::fromEnv(getenv());
            $db = MeasureDb::connect($c);
            $name = $c->dbNameForScale(100);
            $db->create($name);
            echo "created $name\n";
            echo "listed " . implode(',', $db->listMeasureDatabases()) . "\n";
            $db->drop($name);
            echo "dropped $name\n";
            foreach (['app', 'peak_sim_measure_', "peak_sim_measure_x'; DROP DATABASE app; --"] as $bad) {
                try { $db->drop($bad); echo "ALLOWED drop $bad\n"; }
                catch (MeasureRefused $e) { echo "REFUSED drop => " . $e->getMessage() . "\n"; }
            }
            PHP;
        $result = self::phpCode($code);
        self::evidence('db', 'measure-db-name-refusals.txt', self::transcript('MeasureDb create/drop and refused names', $result));

        $this->assertSame(0, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString("created $name", $result[1]);
        $this->assertStringContainsString("listed $name", $result[1]);
        $this->assertStringContainsString("dropped $name", $result[1]);
        $this->assertSame(3, substr_count($result[1], 'REFUSED drop'));
        $this->assertStringNotContainsString('ALLOWED', $result[1]);
    }

    public function testStartIsRefusedWhenTheHostIsNotTheDbService(): void
    {
        $result = self::inPhp('/peak-sim/poc/runtime/bin/inner/preflight.php', ['-e', 'PEAK_MEASURE_DB_HOST=db.example.com']);
        self::evidence('db', 'refused-host.txt', self::transcript('PEAK_MEASURE_DB_HOST=db.example.com bin/inner/preflight.php', $result));

        $this->assertSame(10, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString('refused: database host', $result[2]);
    }

    public function testStartIsRefusedWhenTheInputIsMissing(): void
    {
        $result = self::inPhp('/peak-sim/poc/runtime/bin/inner/preflight.php', ['-e', 'PEAK_MEASURE_DB_HOST=']);
        self::evidence('db', 'refused-missing-host.txt', self::transcript('PEAK_MEASURE_DB_HOST= (unset) bin/inner/preflight.php', $result));

        $this->assertSame(10, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString('PEAK_MEASURE_DB_HOST is not set', $result[2]);
    }

    public function testAnAppsOwnDatabaseVariablesChangeNothing(): void
    {
        $result = self::inPhp('/peak-sim/poc/runtime/bin/inner/preflight.php', ['-e', 'DB_HOST=127.0.0.1', '-e', 'DB_DATABASE=app', '-e', 'DATABASE_URL=mysql://root@127.0.0.1/app']);
        self::evidence('db', 'app-env-ignored.txt', self::transcript('DB_HOST=127.0.0.1 DB_DATABASE=app DATABASE_URL=... bin/inner/preflight.php', $result));

        $this->assertSame(0, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString('PASS positive tcp:measure-db:3306', $result[1]);
    }

    public function testCleanupDropsMeasurementDatabasesLeftByAKilledRunAndNothingElse(): void
    {
        $this->assertSame(0, self::rootSql('CREATE DATABASE IF NOT EXISTS app; CREATE TABLE IF NOT EXISTS app.sentinel (id INT)')[0]);

        $killed = self::phpCode(<<<'PHP'
            use PeakSim\Runtime\Config\MeasureConfig;
            use PeakSim\Runtime\Guard\MeasureDb;
            $c = MeasureConfig::fromEnv(getenv());
            $db = MeasureDb::connect($c);
            $db->create($c->dbNameForScale(100));
            $db->create($c->dbNameForScale(1000));
            echo "created two databases, now dying abnormally\n";
            posix_kill(posix_getpid(), SIGKILL);
            PHP);
        $before = self::rootSql("SHOW DATABASES LIKE 'peak\\_sim\\_measure\\_%'");
        $cleanup = self::bin('cleanup');
        $after = self::rootSql("SHOW DATABASES LIKE 'peak\\_sim\\_measure\\_%'");
        $other = self::rootSql('SHOW DATABASES LIKE \'app\'');
        self::evidence('db', 'cleanup-after-abnormal-exit.txt',
            self::transcript('run that dies by SIGKILL after creating two databases', $killed)
            . "\n" . self::transcript("root: SHOW DATABASES LIKE 'peak_sim_measure_%' (before cleanup)", $before)
            . "\n" . self::transcript('bin/cleanup', $cleanup)
            . "\n" . self::transcript("root: SHOW DATABASES LIKE 'peak_sim_measure_%' (after cleanup)", $after)
            . "\n" . self::transcript("root: SHOW DATABASES LIKE 'app' (must still exist)", $other));

        $this->assertSame(137, $killed[0], 'the run must have died by SIGKILL');
        $this->assertCount(2, array_filter(explode("\n", trim($before[1]))), 'two measurement databases are left behind');
        $this->assertSame(0, $cleanup[0], $cleanup[1] . $cleanup[2]);
        $this->assertStringContainsString('dropped=2 remaining=0', $cleanup[1]);
        $this->assertSame('', trim($after[1]), 'no measurement database may remain');
        $this->assertSame('app', trim($other[1]), 'cleanup must not touch any other database');
    }

    public function testTheAppAndTheContainerRootAreReadOnlyAndTmpIsWritable(): void
    {
        $result = self::phpCode(<<<'PHP'
            foreach (['/app/zz-write-test', '/peak-sim/zz-write-test', '/zz-write-test'] as $path) {
                echo (@file_put_contents($path, 'x') === false ? 'READONLY ' : 'WRITABLE ') . $path . "\n";
            }
            echo (@file_put_contents('/tmp/zz-write-test', 'x') === false ? 'READONLY ' : 'WRITABLE ') . "/tmp/zz-write-test\n";
            echo (@file_put_contents('/out/zz-write-test', 'x') === false ? 'READONLY ' : 'WRITABLE ') . "/out/zz-write-test\n";
            @unlink('/out/zz-write-test');
            PHP);
        self::evidence('app', 'read-only-mounts.txt', self::transcript('write attempts from the PHP container', $result));

        $this->assertSame(0, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString('READONLY /app/zz-write-test', $result[1]);
        $this->assertStringContainsString('READONLY /peak-sim/zz-write-test', $result[1]);
        $this->assertStringContainsString('READONLY /zz-write-test', $result[1]);
        $this->assertStringContainsString('WRITABLE /tmp/zz-write-test', $result[1]);
        $this->assertStringContainsString('WRITABLE /out/zz-write-test', $result[1]);
    }

    public function testThePhpContainerNeverHoldsTheRootPassword(): void
    {
        $root = self::$stackEnv['PEAK_MEASURE_DB_ROOT_PASSWORD'];
        $env = self::exec(['docker', 'compose', '-p', self::$project, '-f', self::runtimeDir() . '/compose/' . self::$composeFile, 'exec', '-T', 'measure-php', 'env']);
        $this->assertSame(0, $env[0]);
        $this->assertStringNotContainsString($root, $env[1]);
        $this->assertStringContainsString('PEAK_MEASURE_DB_USER=measure', $env[1]);

        $inspect = self::exec(['docker', 'compose', '-p', self::$project, '-f', self::runtimeDir() . '/compose/' . self::$composeFile, 'ps', '-q', 'measure-php']);
        $details = self::exec(['docker', 'inspect', trim($inspect[1]), '--format', '{{json .Config.Env}}']);
        $this->assertStringNotContainsString($root, $details[1]);
    }
}
