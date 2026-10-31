<?php

declare(strict_types=1);

namespace PeakSim\Output;

use PeakSim\Model\AnalysisReport;
use PeakSim\Model\Assumption;
use PeakSim\Model\Axis;
use PeakSim\Model\AxisVerdict;
use PeakSim\Model\CacheView;
use PeakSim\Model\Gap;
use PeakSim\Model\RouteResult;
use PeakSim\Model\ScenarioTag;
use PeakSim\Model\Site;
use PeakSim\Name;

/**
 * An AnalysisReport as JSON (docs/output-schema.md). The same report always gives the same bytes: fixed key
 * order, canonical order of unordered lists, no floats, no time, no host, no absolute path.
 *
 * A path that is not relative to the app root is a bug somewhere upstream, and it is refused (LogicException)
 * rather than written.
 */
final class JsonRenderer
{
    public const SCHEMA_VERSION = '0.1';

    public const MODE_EXPORT_DIR = 'export-dir';

    public const MODE_ARTISAN_COMMAND = 'artisan-command';

    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private string $where = '';

    /** @param string|null $inputMode how the app's routes and models were read; null when the caller does not say */
    public function render(AnalysisReport $report, ?string $inputMode = null): string
    {
        $summary = ReportSummary::of($report);
        $document = [
            'schema_version' => self::SCHEMA_VERSION,
            'tool' => ['name' => Name::TOOL_NAME],
            'input' => ['mode' => $inputMode],
            'analysis_scope' => $this->scope($report->scope),
            'summary' => [
                'routes' => $summary->routes,
                'by_confidence' => $summary->byConfidence,
                'by_growth' => $summary->byGrowth,
                'gap_reasons' => $summary->gapReasons === [] ? new \stdClass() : $summary->gapReasons,
                'skipped' => $summary->skipped,
            ],
            'skipped' => Canonical::skipped($report->skipped),
            'routes' => array_map($this->route(...), Canonical::routes($report->routes)),
        ];
        $this->assertNoFloat($document);

        return json_encode($document, self::FLAGS) . "\n";
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function scope(array $scope): array
    {
        $notCovered = array_values(array_unique(array_map('strval', (array) ($scope['not_covered'] ?? []))));
        sort($notCovered, SORT_STRING);
        unset($scope['not_covered']);
        ksort($scope, SORT_STRING);

        return ['not_covered' => $notCovered] + array_map($this->sortedValue(...), $scope);
    }

    private function sortedValue(mixed $value): mixed
    {
        if (is_array($value) && !array_is_list($value)) {
            ksort($value, SORT_STRING);
            $value = array_map($this->sortedValue(...), $value);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function route(RouteResult $r): array
    {
        $this->where = $r->uri;
        $methods = $r->methods;
        sort($methods, SORT_STRING);

        return [
            'route' => $r->uri,
            'methods' => $methods,
            'action' => $r->action,
            'kind' => $r->kind,
            'query' => $this->axis($r->query),
            'memory' => $this->axis($r->memory),
            'n_table' => $r->nTable,
            'm_table' => $r->mTable,
            'm_per' => $r->mPer,
            'n_table_alternatives' => $this->alternatives($r->nTableAlternatives),
            'confidence' => $r->confidence?->value,
            'cache' => $r->cache === null ? null : $this->cache($r->cache),
            'worst_case' => $r->worstCase === null ? null : array_map($this->worstCase(...), $r->worstCase),
            'coefficients' => $this->coefficients($r->coefficients),
            'gaps' => array_map($this->gap(...), Canonical::gaps($r->gaps)),
            'assumptions' => array_map($this->assumption(...), Canonical::assumptions($r->assumptions)),
            'evidence' => array_map(fn (array $e): array => [
                'role' => $e['role'],
                'site' => $this->site($e['site']),
                'axis' => $e['axis'] ?? null,
                'note' => $e['note'] ?? '',
            ], $r->evidence),
            'events_found' => $r->eventsFound,
            'truncated' => $r->truncated,
        ];
    }

    /**
     * The other tables that tie with N, once each, in name order.
     *
     * @param list<string> $tables
     * @return list<string>
     */
    private function alternatives(array $tables): array
    {
        $tables = array_values(array_unique(array_map('strval', $tables)));
        sort($tables, SORT_STRING);

        return $tables;
    }

    /** @return array<string, mixed> */
    private function axis(AxisVerdict $a): array
    {
        return [
            'growth' => $a->growth->value,
            'degree' => $a->degree,
            'confidence' => $a->confidence?->value,
            'lower_bound' => $a->lowerBound?->value,
        ];
    }

    /** @return array<string, mixed> */
    private function cache(CacheView $c): array
    {
        return [
            'sites' => array_map($this->site(...), $c->sites),
            'on_miss' => ['query' => $this->axis($c->onMiss['query']), 'memory' => $this->axis($c->onMiss['memory'])],
            'on_hit' => ['query' => $this->axis($c->onHit['query']), 'memory' => $this->axis($c->onHit['memory'])],
            'restores_all_on_hit' => $c->restoresAllOnHit,
            'adopted_view' => $c->adoptedView,
        ];
    }

    /**
     * @param array<string, mixed> $w
     * @return array<string, mixed>
     */
    private function worstCase(array $w): array
    {
        return [
            'site' => $this->site($w['site']),
            'abstract' => $w['abstract'],
            'candidates' => array_values($w['candidates']),
            'chosen' => $w['chosen'],
        ];
    }

    /**
     * Always the three keys; a number that was not derived is null.
     *
     * @param array<string, mixed> $c
     * @return array{query_coefficient: ?string, per_item_queries: ?int, extra_queries_per_request: ?int}
     */
    private function coefficients(array $c): array
    {
        return [
            'query_coefficient' => $c['query_coefficient'] ?? null,
            'per_item_queries' => $c['per_item_queries'] ?? null,
            'extra_queries_per_request' => $c['extra_queries_per_request'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function gap(Gap $g): array
    {
        return [
            'code' => $g->code->value,
            'site' => $this->site($g->site),
            'message' => $g->message,
            'affects' => array_map(static fn (Axis $a): string => $a->value, $g->affects),
            'loop_depth' => $g->loopDepth,
            'scenario' => $this->scenario($g->scenario),
        ];
    }

    /** @return array<string, mixed> */
    private function assumption(Assumption $a): array
    {
        $candidates = null;
        $chosen = null;
        if ($a->candidates !== null) {
            $candidates = [];
            foreach ($a->candidates as $key => $value) {
                if (is_int($key)) {
                    $candidates[] = (string) $value;
                }
            }
            $chosen = isset($a->candidates['chosen']) ? (string) $a->candidates['chosen'] : null;
        }

        return [
            'code' => $a->code->value,
            'site' => $this->site($a->site),
            'message' => $a->message,
            'affects' => array_map(static fn (Axis $x): string => $x->value, $a->affects),
            'scenario' => $this->scenario($a->scenario),
            'candidates' => $candidates,
            'chosen' => $chosen,
        ];
    }

    /**
     * @param list<ScenarioTag> $tags
     * @return list<array{cache: int, side: string}>
     */
    private function scenario(array $tags): array
    {
        return array_map(static fn (ScenarioTag $t): array => ['cache' => $t->cacheSite, 'side' => $t->side], $tags);
    }

    /** @return array{file: string, line: int, note: string} */
    private function site(Site $s): array
    {
        if (str_starts_with($s->file, '/') || str_starts_with($s->file, '\\') || preg_match('~^[A-Za-z]:[\\\\/]~', $s->file) === 1) {
            throw new \LogicException("an absolute path reached the output (in the result of {$this->where}); sites must be relative to the app root");
        }

        return ['file' => $s->file, 'line' => $s->line, 'note' => $s->note];
    }

    private function assertNoFloat(mixed $value): void
    {
        if (is_float($value)) {
            throw new \LogicException('a float reached the output; counts and degrees are integers');
        }
        if (is_array($value)) {
            array_walk($value, $this->assertNoFloat(...));
        }
    }
}
