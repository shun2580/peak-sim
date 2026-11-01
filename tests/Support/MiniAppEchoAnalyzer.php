<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

/**
 * A stand-in analyzer that answers with what the tests expect, never reading the app's source.
 *
 * It exists to prove the *test logic*: against it the matching tests must go green, and the tests that look at
 * the source (gap injection, metamorphic) must go red. It also plays the "dummy analyzer that only copies
 * expected.json" of design 3.2-3, which the metamorphic suite has to catch.
 *
 * - Given an app root that holds an expect.php (a mini-app), it echoes that expectation.
 * - Otherwise it echoes fixtures/expected.json, whatever the source says.
 *
 * $lighterBy lowers every non-unknown axis by that many degrees (an analyzer that under-reports).
 */
final class MiniAppEchoAnalyzer implements MiniAppAnalyzer
{
    private const LABELS = [0 => 'O(1)', 1 => 'O(N)', 2 => 'O(N*M)'];

    public function __construct(private readonly int $lighterBy = 0)
    {
    }

    public function analyzeAll(string $appRoot, ?string $exportDir, array $options = []): array
    {
        $views = [];
        foreach ($this->wants($appRoot) as $uri => $want) {
            $views[$uri] = $this->view($uri, $want);
        }

        return $views;
    }

    public function analyzeRoute(string $appRoot, ?string $exportDir, string $uri, array $options = []): array
    {
        $wants = $this->wants($appRoot);
        if (!isset($wants[$uri])) {
            throw new \InvalidArgumentException("no such route: {$uri}");
        }

        return $this->view($uri, $wants[$uri]);
    }

    public function json(string $appRoot, ?string $exportDir, array $options = []): string
    {
        return ReportAssert::encode(['routes' => array_values($this->analyzeAll($appRoot, $exportDir, $options)), 'skipped' => [], 'scope' => []]);
    }

    public function renderedJson(string $appRoot, ?string $exportDir, string $inputMode, array $options = []): string
    {
        return ReportAssert::encode(['schema_version' => '0.2', 'input' => ['mode' => $inputMode], 'routes' => array_values($this->analyzeAll($appRoot, $exportDir, $options))]);
    }

    /** @return array<string, array<string, mixed>> */
    private function wants(string $appRoot): array
    {
        if (is_file($appRoot . '/expect.php')) {
            return (static fn (string $f): array => require $f)($appRoot . '/expect.php')['routes'];
        }
        $wants = [];
        foreach (ExpectationFixtureRows::rows() as $uri => $row) {
            $wants[$uri] = ExpectationFixtureRows::toWant($row);
        }

        return $wants;
    }

    /**
     * @param array<string, mixed> $want
     * @return array<string, mixed>
     */
    private function view(string $uri, array $want): array
    {
        $assumptions = $want['assumptions'] ?? ($want['assumptions_include'] ?? []);
        $axes = ['query' => $this->axis($want['query'] ?? 'O(1)'), 'memory' => $this->axis($want['memory'] ?? 'O(1)')];
        $gapCodes = $want['gaps'] ?? ($want['gaps_include'] ?? []);
        if (!isset($want['gaps']) && isset($want['gaps_any']) && array_intersect($want['gaps_any'], $gapCodes) === []) {
            $gapCodes[] = $want['gaps_any'][0];
        }
        foreach ($axes as $a) {
            if ($a['growth'] === 'unknown' && $gapCodes === []) {
                $gapCodes = ['UNRESOLVED_CALL'];
            }
        }
        $medium = in_array('medium', [$axes['query']['confidence'], $axes['memory']['confidence']], true);
        $confidences = array_filter([$axes['query']['confidence'], $axes['memory']['confidence']]);
        $worst = $want['worst_case_adopted'] ?? false;
        $events = $want['events_found'] ?? 1;
        $cache = $want['cache'] ?? null;

        return [
            'uri' => $uri,
            'methods' => ['GET', 'HEAD'],
            'action' => 'App\\Http\\Controllers\\TestController@index',
            'kind' => $want['kind'] ?? 'controller',
            'query' => $axes['query'],
            'memory' => $axes['memory'],
            'n_table' => $want['n_table'] ?? null,
            'm_table' => $want['m_table'] ?? null,
            'm_per' => $want['m_per'] ?? null,
            'n_table_alternatives' => [],
            'confidence' => $want['confidence'] ?? ($confidences === [] ? null : ($medium ? 'medium' : 'high')),
            'cache' => $cache === null ? null : [
                'on_miss' => ['query' => $this->axis($cache['on_miss']['query']), 'memory' => $this->axis($cache['on_miss']['memory'])],
                'on_hit' => ['query' => $this->axis($cache['on_hit']['query']), 'memory' => $this->axis($cache['on_hit']['memory'])],
                'restores_all_on_hit' => $cache['restores_all_on_hit'] ?? false,
                'adopted_view' => 'miss',
            ],
            'worst_case_adopted' => $worst,
            'worst_case' => $worst ? [['file' => 'app/X.php', 'line' => 1, 'abstract' => 'A', 'candidates' => ['B', 'C'], 'chosen' => 'C']] : null,
            'coefficients' => $want['coefficients'] ?? [],
            'gaps' => array_map(static fn (string $c): array => [
                'code' => $c, 'file' => 'app/X.php', 'line' => 1, 'note' => '', 'message' => 'echo', 'affects' => ['query', 'memory'], 'loop_depth' => 0,
            ], $gapCodes),
            'assumptions' => array_map(static fn (string $c): array => [
                'code' => $c, 'file' => 'app/X.php', 'line' => 1, 'message' => 'echo', 'affects' => ['query', 'memory'], 'candidates' => null,
            ], $assumptions),
            'evidence' => array_map(static function (string $e): array {
                [$file, $line] = array_pad(explode(':', $e, 2), 2, '1');

                return ['role' => 'event', 'file' => $file, 'line' => (int) $line, 'axis' => null, 'note' => ''];
            }, $want['evidence_include'] ?? []),
            'events_found' => $events === '>=1' ? 1 : $events,
            'truncated' => $want['truncated'] ?? in_array('DEPTH_LIMIT', $gapCodes, true),
        ];
    }

    /**
     * @param string|array<string, mixed> $want
     * @return array<string, mixed>
     */
    private function axis(string|array $want): array
    {
        $a = is_string($want) ? ['growth' => $want] : $want;
        $growth = $a['growth'];
        if ($growth === 'unknown') {
            $bound = $a['lower_bound'] ?? null;

            // D38: no lower bound, no degree; a lower bound brings the degree of that bound.
            return ['growth' => 'unknown', 'degree' => $bound === null ? null : ReportAssert::rank($bound), 'confidence' => null, 'lower_bound' => $bound];
        }
        $degree = max(0, ReportAssert::rank($growth) - $this->lighterBy);

        return [
            'growth' => self::LABELS[min($degree, 2)],
            'degree' => $degree,
            'confidence' => $a['confidence'] ?? 'high',
            'lower_bound' => null,
        ];
    }
}
