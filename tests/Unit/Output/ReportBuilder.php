<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Output;

use PeakSim\Model\AnalysisReport;
use PeakSim\Model\Assumption;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Axis;
use PeakSim\Model\AxisVerdict;
use PeakSim\Model\CacheView;
use PeakSim\Model\Confidence;
use PeakSim\Model\Gap;
use PeakSim\Model\GapCode;
use PeakSim\Model\Growth;
use PeakSim\Model\RouteResult;
use PeakSim\Model\ScenarioTag;
use PeakSim\Model\Site;

/**
 * Builds an AnalysisReport by hand from the data of tests/Fixtures/Golden/input.json, so the renderers are
 * tested on a report that no analysis produced. The spec's shape is the shape of the output, with sites kept as
 * objects; nothing here knows how the renderers format anything.
 */
final class ReportBuilder
{
    public static function goldenPath(string $name): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Golden/' . $name;
    }

    public static function golden(string $name): string
    {
        $content = file_get_contents(self::goldenPath($name));
        if ($content === false) {
            throw new \RuntimeException("missing golden file {$name}");
        }

        return $content;
    }

    /** @return array<string, mixed> */
    public static function inputSpec(): array
    {
        return json_decode(self::golden('input.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $spec */
    public static function report(array $spec): AnalysisReport
    {
        return new AnalysisReport(
            array_map(self::route(...), $spec['routes']),
            $spec['skipped'],
            $spec['scope'],
        );
    }

    /** @param array<string, mixed> $r */
    public static function route(array $r): RouteResult
    {
        return new RouteResult(
            $r['uri'],
            $r['methods'],
            $r['action'],
            $r['kind'],
            self::axis($r['query']),
            self::axis($r['memory']),
            $r['n_table'],
            $r['m_table'],
            $r['m_per'],
            $r['confidence'] === null ? null : Confidence::from($r['confidence']),
            $r['cache'] === null ? null : self::cache($r['cache']),
            $r['worst_case'] === null ? null : array_map(static fn (array $w): array => [
                'site' => self::site($w['site']), 'abstract' => $w['abstract'], 'candidates' => $w['candidates'], 'chosen' => $w['chosen'],
            ], $r['worst_case']),
            $r['coefficients'],
            array_map(self::gap(...), $r['gaps']),
            array_map(self::assumption(...), $r['assumptions']),
            array_map(static fn (array $e): array => [
                'role' => $e['role'], 'site' => self::site($e['site']), 'axis' => $e['axis'], 'note' => $e['note'],
            ], $r['evidence']),
            $r['events_found'],
            $r['truncated'],
            $r['n_table_alternatives'] ?? [],
        );
    }

    /** @param array<string, mixed> $s */
    public static function site(array $s): Site
    {
        return new Site($s['file'], $s['line'], $s['note']);
    }

    /** @param array<string, mixed> $a */
    public static function axis(array $a): AxisVerdict
    {
        return new AxisVerdict(
            Growth::from($a['growth']),
            $a['degree'],
            $a['confidence'] === null ? null : Confidence::from($a['confidence']),
            $a['lower_bound'] === null ? null : Growth::from($a['lower_bound']),
            null,
        );
    }

    /** @param array<string, mixed> $c */
    private static function cache(array $c): CacheView
    {
        return new CacheView(
            array_map(self::site(...), $c['sites']),
            ['query' => self::axis($c['on_miss']['query']), 'memory' => self::axis($c['on_miss']['memory'])],
            ['query' => self::axis($c['on_hit']['query']), 'memory' => self::axis($c['on_hit']['memory'])],
            $c['restores_all_on_hit'],
            $c['adopted_view'],
        );
    }

    /** @param array<string, mixed> $g */
    private static function gap(array $g): Gap
    {
        return new Gap(
            GapCode::from($g['code']),
            self::site($g['site']),
            $g['message'],
            array_map(static fn (string $a): Axis => Axis::from($a), $g['affects']),
            $g['loop_depth'],
            array_map(static fn (array $t): ScenarioTag => new ScenarioTag($t['cache'], $t['side']), $g['scenario']),
        );
    }

    /** @param array<string, mixed> $a */
    private static function assumption(array $a): Assumption
    {
        $candidates = null;
        if ($a['candidates'] !== null) {
            $candidates = $a['candidates'];
            $candidates['chosen'] = $a['chosen'];
        }

        return new Assumption(
            AssumptionCode::from($a['code']),
            self::site($a['site']),
            $a['message'],
            array_map(static fn (string $x): Axis => Axis::from($x), $a['affects']),
            array_map(static fn (array $t): ScenarioTag => new ScenarioTag($t['cache'], $t['side']), $a['scenario']),
            $candidates,
        );
    }

    /**
     * The same spec with every list that has no meaning in its order reversed: routes, skipped entries, gaps,
     * assumptions. Evidence, cache sites and worst-case candidates are discovery order, and the
     * not_covered list is in the order the tool declares it; they stay as they are.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    public static function shuffled(array $spec): array
    {
        $spec['routes'] = array_reverse($spec['routes']);
        $spec['skipped'] = array_reverse($spec['skipped']);
        foreach ($spec['routes'] as $i => $route) {
            $spec['routes'][$i]['gaps'] = array_reverse($route['gaps']);
            $spec['routes'][$i]['assumptions'] = array_reverse($route['assumptions']);
        }

        return $spec;
    }
}
