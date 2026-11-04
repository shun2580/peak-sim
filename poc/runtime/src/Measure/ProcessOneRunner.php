<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Run\Attempt;
use PeakSim\Runtime\Run\Requester;

/** Runs `bin/measure-one` as a child process: one route, one database, one fresh PHP process. */
final class ProcessOneRunner implements OneRunner
{
    /** Seconds a measurement may take on top of its requests: the start of the app (and a slow first query). */
    public const BOOT_ALLOWANCE = 120;

    /** Longest a whole measurement may take: the start of the app and three requests, each within `$requestLimit`. */
    public static function timeoutFor(int $requestLimit): int
    {
        return 3 * $requestLimit + self::BOOT_ALLOWANCE;
    }

    /**
     * @param array<string, string> $env the environment of the children (PEAK_MEASURE_* inputs)
     */
    public function __construct(
        private readonly string $script,
        private readonly array $env,
        private readonly int $timeoutSeconds = 3 * Requester::TIME_LIMIT + self::BOOT_ALLOWANCE,
        private readonly string $php = PHP_BINARY,
    ) {
    }

    public function run(MeasureRequest $request): OneResult
    {
        [$code, $out, $err] = Proc::run(
            [$this->php, $this->script, ...$request->toArguments()],
            ['PEAK_MEASURE_DB_TAG' => $request->tag] + $this->env,
            $this->timeoutSeconds,
        );

        $attempts = [];
        foreach (explode("\n", $out) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $data = json_decode($line, true);
            if (is_array($data) && isset($data['route'], $data['class'])) {
                $attempts[] = Attempt::fromArray($data);
            }
        }

        return new OneResult($code, $attempts, $err);
    }
}
