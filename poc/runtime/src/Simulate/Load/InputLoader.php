<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Load;

/**
 * The first stage: the estimate directory and the scenario file in, a {@see LoadedInput} out. Nothing is written here (see {@see TwoStage}).
 *
 * Every scenario puts its own table sizes into the formulas of the estimate ({@see EstimateFormulas::evaluate}): a route whose table the scenario
 * does not give has an unknown cost, never 0, and its table is listed as unknown. Only routes with an rps above 0 are carried; the routes the
 * estimate knows that have none are listed by name in `zero_rps_routes`.
 */
final class InputLoader
{
    public function __construct(private readonly SimulateIo $io)
    {
    }

    /** @throws LoadFailure */
    public function load(string $estimateDir, string $scenarioPath): LoadedInput
    {
        try {
            $text = $this->io->read($scenarioPath);
        } catch (\RuntimeException $e) {
            throw new LoadFailure(LoadFailure::SCENARIO_INVALID, 'the scenario file cannot be read: ' . $scenarioPath);
        }
        $file = ScenarioReader::parseStageOne($text);
        $estimate = EstimateFormulas::load($this->io, $estimateDir);

        $scenarios = [];
        foreach ($file['scenarios'] as $s) {
            $unknownRoutes = array_values(array_filter(array_keys($s['rps']), static fn (string $r): bool => !$estimate->knows($r)));
            if ($unknownRoutes !== []) {
                sort($unknownRoutes);
                throw new LoadFailure(LoadFailure::UNKNOWN_ROUTE, sprintf('scenario "%s" names a route that the estimate does not have: %s', $s['id'], implode(', ', $unknownRoutes)));
            }
            $scenarios[] = self::scenario($s, $estimate);
        }

        return new LoadedInput($estimate->app, $estimate->at, $file['resources'], $scenarios);
    }

    /**
     * @param array{id: string, kind: string, tables: array<string, int>, rps: array<string, float>, observed?: float} $s
     *
     * @return array<string, mixed>
     */
    private static function scenario(array $s, EstimateFormulas $estimate): array
    {
        $routes = [];
        $unknownTables = [];
        $names = array_keys(array_filter($s['rps'], static fn (float $v): bool => $v > 0.0));
        sort($names);
        foreach ($names as $name) {
            $table = $estimate->tableOf($name);
            $n = $table !== null && isset($s['tables'][$table]) ? $s['tables'][$table] : null;
            if ($table !== null && $n === null) {
                $unknownTables[$table] = true;
            }
            $routes[] = array_merge(['route' => $name, 'rps' => $s['rps'][$name], 'table' => $table, 'n' => $n], $estimate->evaluate($name, $n));
        }
        $zero = array_values(array_filter($estimate->routeNames(), static fn (string $r): bool => ($s['rps'][$r] ?? 0.0) <= 0.0));
        ksort($unknownTables);

        $out = ['id' => $s['id'], 'kind' => $s['kind'], 'tables' => $s['tables']];
        if (isset($s['observed'])) {
            $out['observed'] = ['db_cpu_utilization' => $s['observed']];
        }

        return $out + ['unknown_tables' => array_keys($unknownTables), 'zero_rps_routes' => $zero, 'routes' => $routes];
    }
}
