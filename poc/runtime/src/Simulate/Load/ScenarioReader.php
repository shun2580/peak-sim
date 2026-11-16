<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Load;

use PeakSim\Runtime\Simulate\Yaml\MiniYaml;
use PeakSim\Runtime\Simulate\Yaml\YamlError;

/**
 * The scenario file of section 3.4 (`schema_version: "0.1"`), checked for its form. Which routes exist is not decided here ({@see InputLoader}).
 *
 * Known keys only (a typo such as `table:` stops the run, it is not ignored): top `schema_version` `resources` `scenarios`;
 * `resources.db_cpu.cores` (required) `resources.db_cpu.threshold` (default 0.8, above 0 and at most 1) `resources.php_memory_limit` (optional here:
 * bytes, or a number with K/M/G; the tool cannot read the app's php.ini, so it is null when not given and the memory part says so)
 * `resources.app_cpu_time_limit` (optional: the app's execution limit in seconds, a number above 0; there is no default, and with none given the
 * `app_limit_risk` mark is not made, see {@see \PeakSim\Runtime\Simulate\Predict\AppLimitRisk})
 * `resources.app_memory_limit` (optional: php's `memory_limit` of the app as the mark takes it, bytes or a number with K/M/G; there is no default, and with
 * none given the memory side of the mark is not made, see {@see \PeakSim\Runtime\Simulate\Predict\AppMemoryRisk}; it is not `php_memory_limit`, which
 * only sets the line of section 6.2 and may be given with no mark wanted);
 * `resources.db_placeholder_limit` (optional: the most values the database accepts in one prepared statement, a whole number above 0; there is no default, and
 * with none given the placeholder side of the mark is not made, see {@see \PeakSim\Runtime\Simulate\Predict\AppPlaceholderRisk});
 * per scenario `id` `kind` (`calibration`|`target`) `tables` (table => count, a whole number from 0) `rps` (route => number from 0) `observed`.
 *
 * The first stage uses {@see parseStageOne}: the text it parses has the `observed` of the target scenarios already cut out.
 */
final class ScenarioReader
{
    public const SCHEMA_VERSION = '0.1';

    public const DEFAULT_THRESHOLD = 0.8;

    private const TOP_KEYS = ['schema_version', 'resources', 'scenarios'];

    private const SCENARIO_KEYS = ['id', 'kind', 'tables', 'rps', 'observed'];

    /**
     * @return array{resources: array{cores: int|float, threshold: float, php_memory_limit_bytes: int|null}, scenarios: list<array{id: string, kind: string, tables: array<string, int>, rps: array<string, float>, observed?: float}>}
     *
     * @throws LoadFailure
     */
    public static function parseStageOne(string $text): array
    {
        return self::validate(self::parseYaml(StageOneText::withoutTargetObserved($text)), false);
    }

    /**
     * The second stage: the whole file, and from it the `observed` of the target scenarios only.
     *
     * @return array<string, float> scenario id => db_cpu_utilization (a target with no `observed` is not in it)
     *
     * @throws LoadFailure
     */
    public static function targetObserved(string $text): array
    {
        $parsed = self::validate(self::parseYaml($text), true);
        $out = [];
        foreach ($parsed['scenarios'] as $s) {
            if ($s['kind'] === 'target' && isset($s['observed'])) {
                $out[$s['id']] = $s['observed'];
            }
        }

        return $out;
    }

    private static function parseYaml(string $text): mixed
    {
        try {
            return MiniYaml::parse($text);
        } catch (YamlError $e) {
            throw new LoadFailure(LoadFailure::SCENARIO_INVALID, 'the scenario file is not in the form that is read: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LoadFailure
     */
    private static function validate(mixed $doc, bool $withTargetObserved): array
    {
        if (!is_array($doc) || ($doc !== [] && array_is_list($doc))) {
            throw self::invalid('the top of the scenario file must be a mapping');
        }
        self::onlyKeys($doc, self::TOP_KEYS, 'the top of the file');
        if (!array_key_exists('schema_version', $doc) || (!is_string($doc['schema_version']) && !is_float($doc['schema_version'])) || (string) $doc['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LoadFailure(LoadFailure::SCHEMA_VERSION, sprintf('schema_version must be "%s" (the file has %s)', self::SCHEMA_VERSION, array_key_exists('schema_version', $doc) ? json_encode($doc['schema_version']) : 'none'));
        }

        $resources = self::resources($doc['resources'] ?? null);

        $list = $doc['scenarios'] ?? null;
        if (!is_array($list) || $list === [] || !array_is_list($list)) {
            throw self::invalid('scenarios must be a list with at least one scenario');
        }
        $scenarios = [];
        $ids = [];
        foreach ($list as $i => $s) {
            $scenario = self::scenario($s, $i, $withTargetObserved);
            if (isset($ids[$scenario['id']])) {
                throw self::invalid(sprintf('the scenario id "%s" is used twice', $scenario['id']));
            }
            $ids[$scenario['id']] = true;
            $scenarios[] = $scenario;
        }

        return ['resources' => $resources, 'scenarios' => $scenarios];
    }

    /**
     * @return array{cores: int|float, threshold: float, php_memory_limit_bytes: int|null, app_cpu_time_limit_s?: float, app_memory_limit_bytes?: int, db_placeholder_limit?: int}
     *
     * @throws LoadFailure
     */
    private static function resources(mixed $r): array
    {
        if ($r !== null && (!is_array($r) || ($r !== [] && array_is_list($r)))) {
            throw self::invalid('resources must be a mapping');
        }
        $r ??= [];
        self::onlyKeys($r, ['db_cpu', 'php_memory_limit', 'app_cpu_time_limit', 'app_memory_limit', 'db_placeholder_limit'], 'resources');
        $cpu = $r['db_cpu'] ?? null;
        if ($cpu !== null && (!is_array($cpu) || ($cpu !== [] && array_is_list($cpu)))) {
            throw self::invalid('resources.db_cpu must be a mapping');
        }
        $cpu ??= [];
        self::onlyKeys($cpu, ['cores', 'threshold'], 'resources.db_cpu');
        if (!array_key_exists('cores', $cpu) || $cpu['cores'] === null) {
            throw new LoadFailure(LoadFailure::CORES_MISSING, 'resources.db_cpu.cores is required (the number of CPUs given to the database)');
        }
        if ((!is_int($cpu['cores']) && !is_float($cpu['cores'])) || $cpu['cores'] <= 0) {
            throw self::invalid('resources.db_cpu.cores must be a number above 0');
        }
        $threshold = $cpu['threshold'] ?? self::DEFAULT_THRESHOLD;
        if ((!is_int($threshold) && !is_float($threshold)) || $threshold <= 0 || $threshold > 1) {
            throw self::invalid('resources.db_cpu.threshold must be above 0 and at most 1');
        }

        $out = [
            'cores' => $cpu['cores'],
            'threshold' => (float) $threshold,
            'php_memory_limit_bytes' => self::bytes($r['php_memory_limit'] ?? null, 'php_memory_limit'),
        ];
        // only when given: a file with no limit loads to the same array as before the mark existed
        if (array_key_exists('app_cpu_time_limit', $r)) {
            $limit = $r['app_cpu_time_limit'];
            if ((!is_int($limit) && !is_float($limit)) || !($limit > 0)) {
                throw self::invalid('resources.app_cpu_time_limit must be a number of seconds above 0 (leave it out when there is no limit to give)');
            }
            $out['app_cpu_time_limit_s'] = (float) $limit;
        }
        if (array_key_exists('app_memory_limit', $r)) {
            $bytes = self::bytes($r['app_memory_limit'], 'app_memory_limit');
            if ($bytes === null) {
                throw self::invalid('resources.app_memory_limit must be a size such as 512M (leave it out when there is no limit to give)');
            }
            $out['app_memory_limit_bytes'] = $bytes;
        }
        if (array_key_exists('db_placeholder_limit', $r)) {
            $values = $r['db_placeholder_limit'];
            if (!is_int($values) || $values < 1) {
                throw self::invalid('resources.db_placeholder_limit must be a whole number of values above 0 (leave it out when there is no limit to give)');
            }
            $out['db_placeholder_limit'] = $values;
        }

        return $out;
    }

    /** @throws LoadFailure */
    private static function bytes(mixed $v, string $key): ?int
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v) && $v > 0) {
            return $v;
        }
        if (is_string($v) && preg_match('/^([1-9][0-9]*)\s*([KMG])?B?$/i', trim($v), $m) === 1) {
            $mult = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3][strtoupper($m[2] ?? '')];

            return (int) $m[1] * $mult;
        }

        throw self::invalid('resources.' . $key . ' must be a size such as 128M (a number with K, M or G, or bytes)');
    }

    /**
     * @return array{id: string, kind: string, tables: array<string, int>, rps: array<string, float>, observed?: float}
     *
     * @throws LoadFailure
     */
    private static function scenario(mixed $s, int $i, bool $withTargetObserved): array
    {
        $where = 'scenarios[' . $i . ']';
        if (!is_array($s) || ($s !== [] && array_is_list($s))) {
            throw self::invalid($where . ' must be a mapping');
        }
        self::onlyKeys($s, self::SCENARIO_KEYS, $where);
        $id = $s['id'] ?? null;
        if (is_int($id)) {
            $id = (string) $id;
        }
        if (!is_string($id) || $id === '') {
            throw self::invalid($where . '.id must be a non-empty text');
        }
        $where = 'scenario "' . $id . '"';
        $kind = $s['kind'] ?? null;
        if ($kind !== 'calibration' && $kind !== 'target') {
            throw self::invalid($where . ': kind must be "calibration" or "target"');
        }
        $tables = [];
        foreach (self::map($s['tables'] ?? null, $where . '.tables') as $name => $count) {
            if (!is_int($count) || $count < 0) {
                throw self::invalid(sprintf('%s.tables.%s must be a whole number from 0', $where, $name));
            }
            $tables[(string) $name] = $count;
        }
        $rps = [];
        foreach (self::map($s['rps'] ?? null, $where . '.rps') as $route => $v) {
            if ((!is_int($v) && !is_float($v)) || $v < 0) {
                throw self::invalid(sprintf('%s.rps["%s"] must be a number from 0', $where, $route));
            }
            $rps[(string) $route] = (float) $v;
        }
        $out = ['id' => $id, 'kind' => $kind, 'tables' => $tables, 'rps' => $rps];
        if ($kind === 'calibration') {
            $o = $s['observed'] ?? null;
            if (!is_array($o) || !isset($o['db_cpu_utilization']) || (!is_int($o['db_cpu_utilization']) && !is_float($o['db_cpu_utilization']))) {
                throw new LoadFailure(LoadFailure::OBSERVED_MISSING, $where . ': a calibration scenario needs observed.db_cpu_utilization');
            }
            self::onlyKeys($o, ['db_cpu_utilization'], $where . '.observed');
            if ($o['db_cpu_utilization'] < 0) {
                throw self::invalid($where . ': observed.db_cpu_utilization must not be negative');
            }
            $out['observed'] = (float) $o['db_cpu_utilization'];
        } elseif ($withTargetObserved && array_key_exists('observed', $s) && $s['observed'] !== null) {
            $o = $s['observed'];
            if (!is_array($o) || !isset($o['db_cpu_utilization']) || (!is_int($o['db_cpu_utilization']) && !is_float($o['db_cpu_utilization'])) || $o['db_cpu_utilization'] < 0) {
                throw self::invalid($where . ': observed.db_cpu_utilization must be a number from 0');
            }
            self::onlyKeys($o, ['db_cpu_utilization'], $where . '.observed');
            $out['observed'] = (float) $o['db_cpu_utilization'];
        }

        return $out;
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $v, string $where): array
    {
        if ($v === null) {
            return [];
        }
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            throw self::invalid($where . ' must be a mapping');
        }

        return $v;
    }

    /**
     * @param array<array-key, mixed> $m
     * @param list<string>            $allowed
     */
    private static function onlyKeys(array $m, array $allowed, string $where): void
    {
        $extra = array_values(array_diff(array_map('strval', array_keys($m)), $allowed));
        if ($extra !== []) {
            throw self::invalid(sprintf('%s has a key that is not known: %s (known: %s)', $where, implode(', ', $extra), implode(', ', $allowed)));
        }
    }

    private static function invalid(string $message): LoadFailure
    {
        return new LoadFailure(LoadFailure::SCENARIO_INVALID, $message);
    }
}
