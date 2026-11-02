<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Boot;

/**
 * Holds a profile's `on_boot` to the two conditions it is allowed under (decision 5 of order 06):
 *  1. it does not touch the database: no statement is sent (the QueryExecuted event of every connection is counted
 *     while the hook runs) and the transaction level of the connection is the same after as before;
 *  2. it does not take the fakes off: what `Fakes::fingerprint()` reads (the objects behind the mail, notification and
 *     queue fakes, and the block on outbound requests) is the same after as before.
 *
 * What it cannot see is a statement sent straight to the PDO object, which no event reports. The hook gets the
 * application, so that is a limit of this check and is said so in the README.
 */
final class OnBootWatch
{
    private const QUERY_EVENT = 'Illuminate\\Database\\Events\\QueryExecuted';

    /** @param (\Closure(): array<string, mixed>)|null $fingerprint */
    public function __construct(private readonly object $app, private readonly ?\Closure $fingerprint = null)
    {
    }

    /** @throws OnBootViolation */
    public function run(callable $hook): void
    {
        $app = $this->app;
        $queries = 0;
        $active = true;
        $app['events']->listen(self::QUERY_EVENT, static function () use (&$queries, &$active): void {
            if ($active) {
                ++$queries;
            }
        });
        $fingerprint = $this->fingerprint ?? static fn (): array => Fakes::fingerprint($app);
        $faked = $fingerprint();
        $level = $app['db']->connection()->transactionLevel();

        try {
            $hook($app);
        } finally {
            $active = false;
        }

        if ($queries > 0 || $app['db']->connection()->transactionLevel() !== $level) {
            throw new OnBootViolation(sprintf('on_boot touched the database (%d statements, transaction level %d to %d): it must not', $queries, $level, $app['db']->connection()->transactionLevel()));
        }
        if ($fingerprint() !== $faked) {
            throw new OnBootViolation('on_boot changed a fake that the tool put in place (' . implode(', ', array_keys(array_diff_assoc($fingerprint(), $faked))) . '): it must not');
        }
    }
}
