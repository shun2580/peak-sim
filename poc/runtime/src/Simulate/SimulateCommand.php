<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate;

use PeakSim\Runtime\Simulate\Load\LoadedInput;
use PeakSim\Runtime\Simulate\Load\LoadFailure;
use PeakSim\Runtime\Simulate\Load\NativeIo;
use PeakSim\Runtime\Simulate\Load\SimulateIo;
use PeakSim\Runtime\Simulate\Load\TwoStage;

/**
 * bin/simulate --estimate=<dir> --scenario=<scenario.yaml> --out=<dir>
 *
 * Reads the estimate and the scenario (the first stage, {@see TwoStage::stageOne}), writes `<out>/loaded.json`, then hands the loaded input to
 * `$next` when one is given (calibration and prediction, T2/T3: `callable(TwoStage, LoadedInput, string $outDir): int`, its return value is the
 * exit code; it writes its outputs with {@see TwoStage::writeOutput}). Without `$next` the command stops after the first stage.
 *
 * Exit codes: 0 done; 2 wrong usage; 20..25 the input stops the run ({@see LoadFailure}); 1 an output cannot be written.
 */
final class SimulateCommand
{
    public const USAGE = 'usage: bin/simulate --estimate=<estimate dir> --scenario=<scenario.yaml> --out=<output dir>';

    /**
     * @param resource                                                  $stdout
     * @param resource                                                  $stderr
     * @param (callable(TwoStage, LoadedInput, string): int)|null       $next
     */
    public function __construct(
        private readonly mixed $stdout,
        private readonly mixed $stderr,
        private readonly ?SimulateIo $io = null,
        private readonly mixed $next = null,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $opts = ['estimate' => null, 'scenario' => null, 'out' => null];
        foreach ($args as $arg) {
            $matched = false;
            foreach (array_keys($opts) as $name) {
                if (str_starts_with($arg, '--' . $name . '=')) {
                    $opts[$name] = substr($arg, strlen($name) + 3);
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return $this->usage('unknown argument: ' . $arg);
            }
        }
        foreach ($opts as $name => $v) {
            if ($v === null || $v === '') {
                return $this->usage('--' . $name . ' is required');
            }
        }
        $stage = new TwoStage($this->io ?? new NativeIo());
        try {
            $input = $stage->stageOne((string) $opts['estimate'], (string) $opts['scenario'], (string) $opts['out']);
            fwrite($this->stdout, self::summary($input));
            if ($this->next !== null) {
                return (int) ($this->next)($stage, $input, (string) $opts['out']);
            }
        } catch (LoadFailure $e) {
            fwrite($this->stderr, 'bin/simulate: ' . $e->getMessage() . "\n");

            return $e->exitCode;
        } catch (\RuntimeException $e) {
            fwrite($this->stderr, 'bin/simulate: ' . $e->getMessage() . "\n");

            return 1;
        }

        return 0;
    }

    /** What the first stage found, for a person. */
    public static function summary(LoadedInput $input): string
    {
        $out = sprintf("loaded: %d scenario(s), estimate made at N=%d\n", count($input->scenarios), $input->estimateAt);
        foreach ($input->scenarios as $s) {
            $unknown = array_values(array_filter($s['routes'], static fn (array $r): bool => $r['cost'] !== 'ok'));
            $out .= sprintf(
                "  %s (%s): %d route(s) with rps, %d with unknown cost, %d unknown table(s)%s, %d route(s) with rps 0\n",
                $s['id'],
                $s['kind'],
                count($s['routes']),
                count($unknown),
                count($s['unknown_tables']),
                $s['unknown_tables'] === [] ? '' : ' [' . implode(', ', $s['unknown_tables']) . ']',
                count($s['zero_rps_routes']),
            );
        }

        return $out;
    }

    private function usage(string $message): int
    {
        fwrite($this->stderr, "bin/simulate: {$message}\n" . self::USAGE . "\n");

        return 2;
    }
}
