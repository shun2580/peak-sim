<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Guard\MeasureDb;

/**
 * Builds each measurement database in a child process (`bin/inner/build-db.php`), because the child starts the target
 * app and an app can be started once per process. Dropping needs no app, so it is done here, with the same guard.
 */
final class ProcessDatabaseBuilder implements DatabaseBuilder
{
    public const TIMEOUT = 900;

    /** @param array<string, string> $env the PEAK_MEASURE_* inputs */
    public function __construct(
        private readonly string $script,
        private readonly array $env,
        private readonly string $mode,
        private readonly int $timeoutSeconds = self::TIMEOUT,
        private readonly string $php = PHP_BINARY,
        private readonly ?string $profile = null,
    ) {
    }

    public function build(DatabasePoint $point): array
    {
        [$code, $out, $err] = Proc::run(
            [$this->php, $this->script, '--scale=' . $point->scale, '--mode=' . $this->mode, '--recipe-json=' . json_encode($point->recipe, JSON_THROW_ON_ERROR), ...($this->profile === null ? [] : ['--profile=' . $this->profile])],
            ['PEAK_MEASURE_DB_TAG' => $point->tag] + $this->env,
            $this->timeoutSeconds,
        );
        if ($code !== 0) {
            throw new BuildFailure(sprintf('exit %d: %s', $code, substr(trim(str_replace("\n", ' ', $err !== '' ? $err : $out)), -400)));
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $l): bool => $l !== '' && $l[0] === '{'));
        $info = $lines === [] ? null : json_decode($lines[array_key_last($lines)], true);
        if (!is_array($info)) {
            throw new BuildFailure('the build printed no result');
        }

        return $info;
    }

    public function drop(DatabasePoint $point): void
    {
        $config = MeasureConfig::fromEnv(['PEAK_MEASURE_DB_TAG' => $point->tag] + $this->env);
        MeasureDb::connect($config)->drop($config->dbNameForScale($point->scale));
    }
}
