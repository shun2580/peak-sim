<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Run\Attempt;

/** Runs `bin/measure-one` as a child process: one route, one database, one fresh PHP process. */
final class ProcessOneRunner implements OneRunner
{
    /** Longest a whole measurement (boot and three requests, each limited to 20 seconds) may take. */
    public const TIMEOUT = 150;

    /**
     * @param array<string, string> $env the environment of the children (PEAK_MEASURE_* inputs)
     */
    public function __construct(
        private readonly string $script,
        private readonly array $env,
        private readonly int $timeoutSeconds = self::TIMEOUT,
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
