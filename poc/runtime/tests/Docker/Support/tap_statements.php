<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): sends ONE request of the target app the way `bin/measure-one` does
// for its cold attempt (the same boot, the same fakes, the same transaction that is rolled back), but with a tap on the
// statements. For every statement the app sent it prints, as JSON: the SQL text, the plan the server gives for it at
// that moment (EXPLAIN, for a select) and how many rows the database read for it (the growth of the session's
// `Handler_read_*` counters, the same seven counters `Run\RowsExamined` adds up).
//
// This is an OBSERVATION of the statements, run beside the run that is checked: the server's history keeps no text for
// prepared statements, so what a statement says and what it cost on the app's side can be read only from here. The
// figure that the test checks is still the one `bin/measure-one` produced.
//
// The tap's own statements (the counters, the EXPLAIN) go to the PDO directly, so no event fires for them; the reads they
// cause are taken off by re-reading the counters after each one, and by the cost of one reading (measured first).
//   php tap_statements.php <scale> <method> <uri> <csrf: 0|1>

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Boot\AppSupport;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Run\RequestSpec;
use PeakSim\Runtime\Run\Requester;
use PeakSim\Runtime\Run\RowsExamined;

require dirname(__DIR__, 3) . '/bootstrap.php';

$config = MeasureConfig::fromEnv(getenv());
$booted = (new AppBooter())->boot($config, (int) ($argv[1] ?? 0));
AppSupport::apply($booted->app);
$app = $booted->app;
$db = $app['db'];
$pdo = $db->connection()->getPdo();

$read = static function () use ($pdo): array {
    $counters = [];
    foreach ($pdo->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAll(PDO::FETCH_NUM) as [$name, $value]) {
        $counters[$name] = (int) $value;
    }

    return $counters;
};
$growth = static function (array $before, array $after): int {
    $sum = 0;
    foreach (RowsExamined::COUNTERS as $name) {
        $sum += $after[$name] - $before[$name];
    }

    return $sum;
};

// The first reading of a session may cost more than the ones after it: discard it, then measure the cost of one.
$read();
$a = $read();
$b = $read();
$baseline = $growth($a, $b);
$previous = $b;

$statements = [];
$app['events']->listen('Illuminate\\Database\\Events\\QueryExecuted', static function (object $event) use (&$statements, &$previous, $read, $growth, $baseline, $pdo): void {
    $now = $read();
    $delta = $growth($previous, $now) - $baseline;

    $plan = null;
    $sql = (string) $event->sql;
    if (preg_match('/^\s*select\b/i', $sql) === 1) {
        try {
            $explain = $pdo->prepare('EXPLAIN ' . $sql);
            $explain->execute($event->connection->prepareBindings($event->bindings));
            $plan = array_map(
                static fn (array $r): array => ['select_type' => $r['select_type'], 'table' => $r['table'], 'type' => $r['type'], 'key' => $r['key'], 'extra' => $r['Extra']],
                $explain->fetchAll(PDO::FETCH_ASSOC),
            );
        } catch (\Throwable $e) {
            $plan = ['error' => substr($e->getMessage(), 0, 120)];
        }
    }
    $statements[] = ['sql' => $sql, 'plan' => $plan, 'read' => $delta];
    $previous = $read(); // after the EXPLAIN: what it read is not the next statement's
});

$app['cache']->store()->flush();
$db->beginTransaction();
$result = (new Requester($booted))->send(new RequestSpec((string) $argv[2], (string) $argv[3], [], null, true, ($argv[4] ?? '0') === '1', []));
for ($i = 0; $i < 50 && $db->transactionLevel() > 0; $i++) {
    $db->rollBack();
}

echo json_encode(['status' => $result->status, 'baseline' => $baseline, 'statements' => $statements], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
