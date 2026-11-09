<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Load;

/**
 * The two stages of reading, and the order between them (section 3.4): a target scenario's `observed` is read only in the second stage,
 * and only after the outputs it must not come before were written through {@see writeOutput}.
 *
 *   stageOne:  reads the estimate and the scenario file with the target `observed` cut out, writes `<out>/loaded.json`.
 *   writeOutput: the only way a stage-one output (the loaded input, T3's prediction) is written; it is remembered.
 *   stageTwo:  refuses ({@see OrderViolation}) unless stageOne ran and every output it is told to wait for was written (and still exists);
 *              then reads the scenario file again, whole, and returns the targets' `observed`.
 *
 * The backtest (T4) is the reader of stageTwo. `bin/simulate` does not call it.
 */
final class TwoStage
{
    public const LOADED_FILE = 'loaded.json';

    private bool $stageOneDone = false;

    /** @var array<string, true> */
    private array $written = [];

    public function __construct(private readonly SimulateIo $io)
    {
    }

    /** @throws LoadFailure */
    public function stageOne(string $estimateDir, string $scenarioPath, string $outDir): LoadedInput
    {
        $input = (new InputLoader($this->io))->load($estimateDir, $scenarioPath);
        $this->writeOutput(rtrim($outDir, '/') . '/' . self::LOADED_FILE, $input->toJson());
        $this->stageOneDone = true;

        return $input;
    }

    public function writeOutput(string $path, string $content): void
    {
        $this->io->write($path, $content);
        $this->written[$path] = true;
    }

    /**
     * @return array<string, float> scenario id => the observed db_cpu_utilization of a target scenario that has one
     *
     * @throws OrderViolation|LoadFailure
     */
    public function stageTwo(string $scenarioPath, string ...$requiredOutputs): array
    {
        if (!$this->stageOneDone) {
            throw new OrderViolation('the second stage was asked for before the first stage ran');
        }
        foreach ($requiredOutputs as $path) {
            if (!isset($this->written[$path]) || !$this->io->exists($path)) {
                throw new OrderViolation('a target\'s observed was asked for before this output was written: ' . $path);
            }
        }
        try {
            $text = $this->io->read($scenarioPath);
        } catch (\RuntimeException $e) {
            throw new LoadFailure(LoadFailure::SCENARIO_INVALID, 'the scenario file cannot be read: ' . $scenarioPath);
        }

        return ScenarioReader::targetObserved($text);
    }
}
