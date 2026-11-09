<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Ladder;

use PeakSim\Runtime\Simulate\Calibrate\CalibrationResult;
use PeakSim\Runtime\Simulate\Calibrate\Calibrator;
use PeakSim\Runtime\Simulate\Load\EstimateFormulas;
use PeakSim\Runtime\Simulate\Load\LoadedInput;
use PeakSim\Runtime\Simulate\Load\LoadFailure;
use PeakSim\Runtime\Simulate\Load\NativeIo;
use PeakSim\Runtime\Simulate\Load\SimulateIo;
use PeakSim\Runtime\Simulate\Load\TwoStage;
use PeakSim\Runtime\Simulate\Predict\CalibrationWidth;
use PeakSim\Runtime\Simulate\Predict\PredictStage;

/**
 * The step after the first stage of `bin/simulate`'s command (the `$next` of SimulateCommand) that makes the ladder prediction of the lab
 * backtest instead of `prediction.json`: calibrate on the calibration scenarios (the same {@see Calibrator} and
 * {@see PredictStage::points} as `bin/simulate`), predict every target scenario at every stage of the ladder, write
 * `<out>/ladder-prediction.json` through {@see TwoStage::writeOutput}, and print a short summary.
 *
 * It does not read a target's `observed`. It does not call git: the commits of the estimates are given by the caller.
 *
 * It stops, and writes no prediction, when (simulator-spec.md sections 5 and 7.2):
 *  - a calibration coefficient was cut to 0 (the main fit, or any fit of the width: the two-coefficient form does not hold in this lab),
 *  - a calibration scenario is one that is a record only (`cal-3-orig`),
 *  - with `requireMarksAtCount`, a target scenario reads a count of rows that no estimate was made at (its safe side would be another
 *    count's marks).
 *
 * Exit codes: 0 done; 30 the calibration cannot be made (the same code as `bin/simulate`); 32 the scenario file has no target scenario;
 * 33 a calibration coefficient was cut; 34 a record-only scenario is a calibration scenario; 35 a target scenario has no marks at its count;
 * 36 the estimates disagree on a point or cannot be used together. An output that cannot be written is the usual 1 of the command.
 */
final class LadderStage
{
    public const OUTPUT_FILE = 'ladder-prediction.json';

    public const EXIT_CALIBRATION = PredictStage::EXIT_CALIBRATION;

    public const EXIT_NO_TARGET = 32;

    public const EXIT_CUT_COEFFICIENT = 33;

    public const EXIT_RECORD_ONLY_IN_CALIBRATION = 34;

    public const EXIT_NO_MARKS_AT_COUNT = 35;

    public const EXIT_ESTIMATES = 36;

    /**
     * The scenario ids that are a record only and are never a calibration scenario (the same list as the tool that writes the scenario file:
     * poc/lab/scenarios/CalibrationScenario.php, which this runtime does not load).
     */
    public const RECORD_ONLY = ['cal-3-orig'];

    /**
     * @param resource    $stdout
     * @param resource    $stderr
     * @param string|null $estimateCommit  the commit of the estimate directory as the caller found it (null: not known; it is written as null)
     * @param string|null $commitSource    how it was found ("git" or "argument"); null when the commit is null
     * @param list<array{dir: string, commit: string|null, commit_source: string|null}> $safeEstimates estimates made at other counts of rows, whose marks may be used
     *                                                                                                  for the scenarios at their count (the main estimate's marks serve its own count)
     * @param bool        $requireMarksAtCount stop (35) when a target scenario's count has no estimate made at it
     * @param string|null $expectedEstimateCommit the commit (or its start) the main estimate should come from; the output says whether it does
     */
    public function __construct(
        private readonly string $estimateDir,
        private readonly string $scenarioPath,
        private readonly mixed $stdout,
        private readonly mixed $stderr,
        private readonly ?string $estimateCommit = null,
        private readonly ?string $commitSource = null,
        private readonly ?SimulateIo $io = null,
        private readonly array $safeEstimates = [],
        private readonly bool $requireMarksAtCount = false,
        private readonly ?string $expectedEstimateCommit = null,
    ) {
    }

    public function __invoke(TwoStage $stage, LoadedInput $input, string $outDir): int
    {
        $io = $this->io ?? new NativeIo();
        $loaded = $input->toArray();
        foreach ((array) $loaded['scenarios'] as $s) {
            if (($s['kind'] ?? null) === 'calibration' && in_array((string) $s['id'], self::RECORD_ONLY, true)) {
                fwrite($this->stderr, sprintf("predict-ladder: the scenario \"%s\" is a record only and is never a calibration scenario (D155); take it out of the scenario file\n", (string) $s['id']));

                return self::EXIT_RECORD_ONLY_IN_CALIBRATION;
            }
        }
        try {
            $calibration = (new Calibrator())->calibrate(PredictStage::points($loaded));
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, 'predict-ladder: the calibration cannot be made: ' . $e->getMessage() . "\n");

            return self::EXIT_CALIBRATION;
        }
        $width = CalibrationWidth::of(PredictStage::points($loaded), $calibration);
        $cut = self::cutCoefficients($calibration, $width);
        if ($cut !== []) {
            fwrite($this->stderr, "predict-ladder: a calibration coefficient was cut to 0, so no prediction is made (stop and report):\n  " . implode("\n  ", $cut) . "\n");

            return self::EXIT_CUT_COEFFICIENT;
        }

        try {
            $main = EstimateFormulas::load($io, $this->estimateDir);
            $others = [];
            foreach ($this->safeEstimates as $o) {
                $others[] = [
                    'estimate' => EstimateFormulas::load($io, $o['dir']),
                    'commit' => $o['commit'],
                    'commit_source' => $o['commit_source'],
                    'sha256' => hash('sha256', $io->read(rtrim($o['dir'], '/') . '/' . EstimateFormulas::FILE)),
                ];
            }
            $mainSha = hash('sha256', $io->read(rtrim($this->estimateDir, '/') . '/' . EstimateFormulas::FILE));
            $safe = new SafeSideEstimates($main, ['commit' => $this->estimateCommit, 'commit_source' => $this->commitSource, 'sha256' => $mainSha], $others);
            if ($this->requireMarksAtCount) {
                $missing = $safe->missingCounts($loaded);
                if ($missing !== []) {
                    $lines = [];
                    foreach ($missing as $id => $counts) {
                        $lines[] = sprintf('%s reads %s rows', $id, implode(' and ', array_map(static fn (int $n): string => number_format($n), $counts)));
                    }
                    fwrite($this->stderr, sprintf(
                        "predict-ladder: no estimate was made at the count of rows of a target scenario, so its safe side would carry another count's marks (%s); give the estimate made at that count with --safe-estimate\n",
                        implode('; ', $lines),
                    ));

                    return self::EXIT_NO_MARKS_AT_COUNT;
                }
            }
            $loaded = $safe->apply($loaded);
        } catch (\InvalidArgumentException | LoadFailure $e) {
            fwrite($this->stderr, 'predict-ladder: ' . $e->getMessage() . "\n");

            return self::EXIT_ESTIMATES;
        }
        $inputs = [
            'estimate_commit' => $this->estimateCommit,
            'estimate_commit_source' => $this->estimateCommit === null ? null : $this->commitSource,
            'estimate_commit_expected' => $this->expectedEstimateCommit,
            'estimate_commit_is_expected' => $this->expectedEstimateCommit === null ? null : self::isExpected($this->estimateCommit, $this->commitSource, $this->expectedEstimateCommit),
            'estimate_sha256' => $mainSha,
            'estimates_for_marks' => $safe->sources(),
            'scenario_sha256' => hash('sha256', $io->read($this->scenarioPath)),
        ];
        try {
            $report = LadderPrediction::build($loaded, $calibration, $inputs);
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, 'predict-ladder: ' . $e->getMessage() . "\n");

            return self::EXIT_NO_TARGET;
        }
        $stage->writeOutput(
            rtrim($outDir, '/') . '/' . self::OUTPUT_FILE,
            json_encode($report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
        fwrite($this->stdout, "\n" . self::summary($report));

        return 0;
    }

    /**
     * Every coefficient that was cut to 0 or pinned at 0: in the main calibration (its warnings) and in each fit of the width.
     *
     * @return list<string>
     */
    private static function cutCoefficients(CalibrationResult $calibration, CalibrationWidth $width): array
    {
        $cut = [];
        foreach ($calibration->warnings as $w) {
            $cut[] = 'calibration: ' . $w;
        }
        foreach ($calibration->fits as $fit) {
            if ($fit->hasCutCoefficient() && $calibration->warnings === []) {
                $cut[] = 'calibration (' . $fit->kind . '): a coefficient is cut or pinned at 0';
            }
        }
        foreach ($width->fits as $f) {
            if ($f['fit']->hasCutCoefficient()) {
                $cut[] = sprintf('fit %s: c_query %s, c_row %s (a coefficient is cut or pinned at 0)', $f['label'], (string) json_encode($f['fit']->cQuery), (string) json_encode($f['fit']->cRow));
            }
        }

        return $cut;
    }

    /** The commit counts only when it was found in git: an argument given by hand does not show where the estimate came from. */
    private static function isExpected(?string $commit, ?string $source, string $expected): bool
    {
        return $source === 'git' && $commit !== null && $expected !== '' && str_starts_with($commit, $expected);
    }

    /** @param array<string, mixed> $report */
    public static function summary(array $report): string
    {
        $out = sprintf(
            "ladder prediction: %d stage(s) x %d scenario(s), calibration %s\n",
            count($report['stages_rps_total']),
            count($report['scenarios']),
            (string) $report['calibration']['method'],
        );
        $in = $report['inputs'];
        $out .= sprintf(
            "  estimate commit: %s (%s)%s\n",
            $in['estimate_commit'] ?? 'unknown',
            $in['estimate_commit_source'] ?? 'no source',
            $in['estimate_commit_expected'] === null ? '' : ($in['estimate_commit_is_expected'] ? ', as expected (' . $in['estimate_commit_expected'] . ')' : ', NOT the expected ' . $in['estimate_commit_expected']),
        );
        $out .= '  naive basis: ' . (string) $report['naive']['basis_scenario'] . ' among ' . implode(', ', $report['naive']['candidates']) . "\n";
        foreach ($report['scenarios'] as $s) {
            foreach ((array) ($s['safe_side'] ?? []) as $side) {
                $out .= sprintf("  %s safe side: marks of the estimate made at %s rows (%s) for %s rows\n", $s['id'], number_format($side['estimate_at']), $side['source'], number_format($side['count']));
            }
        }
        foreach ($report['scenarios'] as $s) {
            $limit = static fn (array $l): string => $l['rps'] === null ? 'none' : sprintf('%.4f', $l['rps']);
            $out .= sprintf(
                "  %s: limit rps point %s / safe %s / naive %s\n",
                $s['id'],
                $limit($s['limit_rps']['point']),
                $limit($s['limit_rps']['safe']),
                $limit($s['limit_rps']['naive']),
            );
        }

        return $out;
    }
}
