<?php

declare(strict_types=1);

namespace PeakSim\Judge;

use PeakSim\Input\RouteEntry;
use PeakSim\Model\Assumption;
use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Axis;
use PeakSim\Model\AxisVerdict;
use PeakSim\Model\CacheView;
use PeakSim\Model\Confidence;
use PeakSim\Model\CostEvent;
use PeakSim\Model\EventKind;
use PeakSim\Model\Gap;
use PeakSim\Model\Growth;
use PeakSim\Model\RouteResult;
use PeakSim\Model\ScenarioTag;
use PeakSim\Model\Site;
use PeakSim\Model\Summary;

/**
 * Turns the facts found for a route into its verdict. A pure function: it knows no syntax tree and does
 * not look at the order the facts arrived in (events by seq; gaps and assumptions by place).
 *
 * A route with a Cache::remember is judged twice, as a miss and as a hit. The main verdict is the worse
 * of the two; when they are equally bad the miss view gives the whole verdict, confidence included.
 */
final class Judge
{
    private const FRAMEWORK_NAMESPACES = ['Illuminate', 'Laravel', 'Symfony', 'Livewire'];

    public function judge(RouteEntry $route, Summary $summary): RouteResult
    {
        [$events, $gaps, $assumptions] = self::ordered($summary);

        $cached = self::cacheIds($events, $gaps, $assumptions) !== [];
        if ($cached) {
            $miss = ViewFacts::side(ScenarioTag::MISS, $events, $gaps, $assumptions);
            $hit = ViewFacts::side(ScenarioTag::HIT, $events, $gaps, $assumptions);
            $onMiss = self::axes($miss);
            $onHit = self::axes($hit);
            $query = AxisJudge::worse($onMiss['query'], $onHit['query']);
            $memory = AxisJudge::worse($onMiss['memory'], $onHit['memory']);
            $cache = new CacheView(self::cacheSites($events, $gaps, $assumptions), $onMiss, $onHit, self::restoresAll($hit), 'miss');
        } else {
            $axes = self::axes(new ViewFacts($events, $gaps, $assumptions));
            $query = $axes['query'];
            $memory = $axes['memory'];
            $cache = null;
        }

        $tables = TableChooser::choose($events);
        $methods = array_values(array_unique($route->methods));
        sort($methods);

        return new RouteResult(
            $route->uri,
            $methods,
            $route->action,
            self::kindOf($route->action),
            $query,
            $memory,
            $tables->n,
            $tables->m,
            $tables->per,
            self::routeConfidence($query, $memory),
            $cache,
            self::worstCase($assumptions),
            Coefficients::of($events),
            $gaps,
            $assumptions,
            Evidence::of($summary->trace, $events, $gaps, $assumptions),
            count($events),
            $summary->truncated,
            $tables->alternatives,
        );
    }

    /**
     * @return array{0: list<CostEvent>, 1: list<Gap>, 2: list<Assumption>}
     */
    private static function ordered(Summary $summary): array
    {
        $events = $summary->events;
        usort($events, static fn (CostEvent $a, CostEvent $b): int => [$a->seq, $a->fingerprint()] <=> [$b->seq, $b->fingerprint()]);

        $gaps = self::unique($summary->gaps, static fn (Gap $g): array => [$g->site->file, $g->site->line, $g->code->value, $g->fingerprint(), $g->message]);
        $assumptions = self::unique($summary->assumptions, static fn (Assumption $a): array => [$a->site->file, $a->site->line, $a->code->value, $a->fingerprint(), $a->message]);

        return [array_values($events), $gaps, $assumptions];
    }

    /**
     * Sorted by the key, then one item per fingerprint.
     *
     * @template T of Gap|Assumption
     * @param list<T> $items
     * @param callable(T): array<int, int|string> $key
     * @return list<T>
     */
    private static function unique(array $items, callable $key): array
    {
        usort($items, static fn ($a, $b): int => $key($a) <=> $key($b));
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            if (!isset($seen[$item->fingerprint()])) {
                $seen[$item->fingerprint()] = true;
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return array{query: AxisVerdict, memory: AxisVerdict} */
    private static function axes(ViewFacts $view): array
    {
        return ['query' => AxisJudge::verdict(Axis::Query, $view), 'memory' => AxisJudge::verdict(Axis::Memory, $view)];
    }

    /** The lower confidence of the two axes; null when either axis is unknown. */
    private static function routeConfidence(AxisVerdict $query, AxisVerdict $memory): ?Confidence
    {
        if ($query->growth === Growth::Unknown || $memory->growth === Growth::Unknown) {
            return null;
        }

        return $query->confidence === Confidence::Medium || $memory->confidence === Confidence::Medium
            ? Confidence::Medium
            : Confidence::High;
    }

    private static function kindOf(string $action): string
    {
        if ($action === 'Closure') {
            return RouteResult::KIND_CLOSURE;
        }

        return in_array(explode('\\', ltrim($action, '\\'))[0], self::FRAMEWORK_NAMESPACES, true)
            ? RouteResult::KIND_FRAMEWORK
            : RouteResult::KIND_CONTROLLER;
    }

    /**
     * @param list<Assumption> $assumptions
     * @return list<array{site: Site, abstract: string, candidates: list<string>, chosen: string}>|null
     */
    private static function worstCase(array $assumptions): ?array
    {
        $out = [];
        foreach ($assumptions as $a) {
            if ($a->code !== AssumptionCode::WORST_CASE_OF_CANDIDATES) {
                continue;
            }
            $raw = $a->candidates ?? [];
            $candidates = [];
            foreach ($raw as $key => $value) {
                if (is_int($key) && is_string($value)) {
                    $candidates[] = $value;
                }
            }
            $abstract = is_string($raw['abstract'] ?? null)
                ? $raw['abstract']
                : (preg_match('/^(\S+) has \d+ implementations/', $a->message, $m) === 1 ? $m[1] : '');
            $out[] = [
                'site' => $a->site,
                'abstract' => $abstract,
                'candidates' => $candidates,
                'chosen' => is_string($raw['chosen'] ?? null) ? $raw['chosen'] : '',
            ];
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param list<CostEvent> $events
     * @param list<Gap> $gaps
     * @param list<Assumption> $assumptions
     * @return list<int>
     */
    private static function cacheIds(array $events, array $gaps, array $assumptions): array
    {
        $ids = [];
        foreach ([...$events, ...$gaps, ...$assumptions] as $item) {
            foreach ($item->scenario as $tag) {
                $ids[$tag->cacheSite] = $tag->cacheSite;
            }
        }
        sort($ids);

        return array_values($ids);
    }

    /**
     * A cache call is placed where its restore event is; without one, at the first item tagged with it.
     *
     * @param list<CostEvent> $events
     * @param list<Gap> $gaps
     * @param list<Assumption> $assumptions
     * @return list<Site>
     */
    private static function cacheSites(array $events, array $gaps, array $assumptions): array
    {
        $sites = [];
        foreach (self::cacheIds($events, $gaps, $assumptions) as $id) {
            $sites[] = self::siteOfCache($id, $events, $gaps, $assumptions);
        }

        return $sites;
    }

    /**
     * @param list<CostEvent> $events
     * @param list<Gap> $gaps
     * @param list<Assumption> $assumptions
     */
    private static function siteOfCache(int $id, array $events, array $gaps, array $assumptions): Site
    {
        $tagged = static fn (array $tags, string $side = ''): bool => array_filter(
            $tags,
            static fn (ScenarioTag $t): bool => $t->cacheSite === $id && ($side === '' || $t->side === $side),
        ) !== [];
        foreach ($events as $e) {
            if ($e->kind === EventKind::CacheRestore && $tagged($e->scenario, ScenarioTag::HIT)) {
                return $e->site;
            }
        }
        foreach ([...$events, ...$gaps, ...$assumptions] as $item) {
            if ($tagged($item->scenario)) {
                return $item->site;
            }
        }

        throw new \LogicException("cache {$id} has no tagged item");
    }

    private static function restoresAll(ViewFacts $hit): bool
    {
        foreach ($hit->events as $e) {
            if ($e->kind === EventKind::CacheRestore && (Degree::of(Axis::Memory, $e) ?? 0) >= 1) {
                return true;
            }
        }

        return false;
    }
}
