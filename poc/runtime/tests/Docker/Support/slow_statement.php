<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): a stand-in for a build or a measurement that starts a statement the
// server will take far longer than any limit to finish, then waits for it. It takes the shape of the real children:
//   php slow_statement.php --scale=N [...]        (the database is named from PEAK_MEASURE_DB_TAG and the scale)
// SLOW_KIND=sleep       INSERT INTO slow_t SELECT SLEEP(300)           (one row after five minutes)
// SLOW_KIND=crossjoin   INSERT INTO slow_t SELECT ... CROSS JOIN x3    (3000^3 rows: the shape of the runaway INSERT)
// Neither can end by itself within a test.

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Guard\MeasureDb;

require dirname(__DIR__, 3) . '/bootstrap.php';

$scale = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--scale=(\d+)$/', $arg, $m) === 1) {
        $scale = (int) $m[1];
    }
}
$config = MeasureConfig::fromEnv(getenv());
$name = $config->dbNameForScale($scale);
$measureDb = MeasureDb::connect($config);
$measureDb->drop($name);
$measureDb->create($name);

$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->dbHost, $config->dbPort, $name), $config->dbUser, $config->dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE slow_t (v BIGINT NOT NULL)');
$pdo->exec('CREATE TABLE seq (n INT NOT NULL PRIMARY KEY)');
$pdo->exec('SET SESSION cte_max_recursion_depth = 5000');
$pdo->exec('INSERT INTO seq (n) WITH RECURSIVE s (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 3000) SELECT n FROM s');

fwrite(STDERR, "starting the slow statement\n");
if ((getenv('SLOW_KIND') ?: 'sleep') === 'crossjoin') {
    $pdo->exec('INSERT INTO slow_t (v) SELECT a.n + b.n + c.n FROM seq a CROSS JOIN seq b CROSS JOIN seq c');
} else {
    $pdo->exec('INSERT INTO slow_t (v) SELECT SLEEP(300)');
}
echo "finished\n";
