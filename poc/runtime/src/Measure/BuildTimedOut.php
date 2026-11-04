<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/**
 * Building a measurement database went over its time limit. The child was stopped and the statement it left running on
 * the server was killed; the Driver records the scale as not run and goes on (any other BuildFailure ends the run).
 */
final class BuildTimedOut extends BuildFailure
{
    /**
     * @param int                                                                            $limitSeconds the limit that was passed
     * @param list<array{id: int, seconds: int, statement: string, table: string|null}>      $killed       the statements killed on the server
     * @param int                                                                            $stillRunning statements that did not end after the kill
     */
    public function __construct(
        public readonly int $limitSeconds,
        public readonly array $killed,
        public readonly int $stillRunning = 0,
    ) {
        parent::__construct(sprintf('the build went over its %d-second limit%s', $limitSeconds, self::describe($killed, $stillRunning)));
    }

    /** @return array{table: string|null, statement: string|null} what the server was running when the build was stopped */
    public function stopped(): array
    {
        return ['table' => $this->killed[0]['table'] ?? null, 'statement' => $this->killed[0]['statement'] ?? null];
    }

    /** @param list<array{id: int, seconds: int, statement: string, table: string|null}> $killed */
    private static function describe(array $killed, int $stillRunning): string
    {
        $text = '';
        if ($killed === []) {
            $text .= '; no statement was running on the server';
        } else {
            $first = $killed[0];
            $text .= sprintf('; killed the statement on table %s (running %d s): %s', $first['table'] ?? '(unknown)', $first['seconds'], mb_substr($first['statement'], 0, 120));
        }
        if ($stillRunning > 0) {
            $text .= sprintf('; %d statement(s) did not end after the kill', $stillRunning);
        }

        return $text;
    }
}
