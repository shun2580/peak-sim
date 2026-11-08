<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Capture;

use PeakSim\Runtime\Capture\CaptureBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The shape of `capture.json` and the numbers in it, from a small recorded input (`vectors/capture.input.json`, written by
 * hand) to the committed sample (`vectors/capture.sample.json`). The sample is also the example in `CAPTURE_FORMAT.md`.
 */
final class CaptureBuilderTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function load(string $name): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/vectors/' . $name), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string, mixed> */
    private static function built(): array
    {
        $in = self::load('capture.input.json');

        return json_decode((string) json_encode(CaptureBuilder::build($in['meta'], $in['attempts'], self::intKeys($in['taps'])), JSON_PRESERVE_ZERO_FRACTION), true);
    }

    /**
     * @param array<string, array<string, mixed>> $taps
     *
     * @return array<string, array<int, mixed>>
     */
    private static function intKeys(array $taps): array
    {
        $out = [];
        foreach ($taps as $route => $byScale) {
            foreach ($byScale as $scale => $doc) {
                $out[$route][(int) $scale] = $doc;
            }
        }

        return $out;
    }

    public function testOutputIsTheCommittedSample(): void
    {
        self::assertSame(self::load('capture.sample.json'), self::built());
    }

    public function testTheShapeOfTheDocument(): void
    {
        $doc = self::built();
        self::assertSame(['capture_version', 'app', 'profile', 'data', 'scales', 'representative_scale', 'routes'], array_keys($doc));
        self::assertSame(1, $doc['capture_version']);
        self::assertSame([100, 1000, 10000], $doc['scales']);
        self::assertSame(10000, $doc['representative_scale']);
        self::assertSame(['GET /orders/{id}', 'GET /ping/{id}', 'POST /never/{x}'], array_column($doc['routes'], 'route'), 'sorted; child-axis attempts add no route of their own');
        foreach ($doc['routes'] as $route) {
            self::assertSame(['route', 'method', 'uri', 'status', 'reason', 'captured_scales', 'totals', 'statement_types'], array_keys($route));
            foreach ($route['statement_types'] as $type) {
                self::assertSame(['id', 'kind', 'pattern', 'representative', 'per_scale', 'count_fit'], array_keys($type));
                self::assertSame(['scale', 'at_representative_scale', 'sql', 'bindings'], array_keys($type['representative']));
                foreach ($type['per_scale'] as $cell) {
                    self::assertSame(['count', 'texts', 'rows_sum', 'rows_min', 'rows_max', 'unread'], array_keys($cell));
                }
            }
        }
    }

    public function testTypesAreGroupedAndCountedByHand(): void
    {
        $orders = self::built()['routes'][0];
        self::assertSame('captured', $orders['status']);
        self::assertNull($orders['reason']);
        $byPattern = array_column($orders['statement_types'], null, 'pattern');
        self::assertCount(5, $byPattern, 'users, items, tags (three lengths of one list), the update, the literal select');

        $items = $byPattern['select * from `items` where `order_id` = ?'];
        self::assertSame([100, 1000, 10000], array_column($items['per_scale'], 'count'), 'one per order: grows with N');
        self::assertSame([300, 3000, 30000], array_column($items['per_scale'], 'rows_sum'));
        self::assertSame('ok', $items['count_fit']['fit_status']);
        self::assertEqualsWithDelta(1.0, $items['count_fit']['k'], 0.001);
        self::assertEqualsWithDelta(1.0, $items['count_fit']['b'], 0.001);

        $user = $byPattern['select * from `users` where `id` = ? limit ?'];
        self::assertSame([1, 1, 1], array_column($user['per_scale'], 'count'));
        self::assertEqualsWithDelta(1.0, $user['count_fit']['a'] + $user['count_fit']['b'], 0.001, 'a constant of 1');

        $tags = $byPattern['select * from `tags` where `id` in (?)'];
        self::assertSame([1, 1, 1], array_column($tags['per_scale'], 'count'), 'three texts with a list of 3, 5 and 2 are one type');
        self::assertSame([3, 5, 2], array_column($tags['per_scale'], 'rows_sum'));
    }

    public function testRepresentativeIsTheLargestScaleWithItsBindings(): void
    {
        $byPattern = array_column(self::built()['routes'][0]['statement_types'], null, 'pattern');
        $tags = $byPattern['select * from `tags` where `id` in (?)']['representative'];
        self::assertSame(10000, $tags['scale']);
        self::assertTrue($tags['at_representative_scale']);
        self::assertSame('select * from `tags` where `id` in (?, ?)', $tags['sql']);
        self::assertSame([1, 2], $tags['bindings']);
    }

    public function testASecretIsInNeitherTheBindingsNorAnywhereInTheOutput(): void
    {
        $json = (string) json_encode(self::built());
        self::assertStringNotContainsString('s3cr3t-token-value', $json);
        $byPattern = array_column(self::built()['routes'][0]['statement_types'], null, 'pattern');
        $update = $byPattern['update `users` set `remember_token` = ? where `id` = ?'];
        self::assertSame(['[redacted]', 7], $update['representative']['bindings']);
        self::assertSame('update', $update['kind']);
    }

    public function testARouteThatFailedAtTheLargestScaleIsPartialAndHasNoFit(): void
    {
        $ping = self::built()['routes'][1];
        self::assertSame('partial', $ping['status']);
        self::assertSame([100, 1000], $ping['captured_scales']);
        self::assertSame('scale 10000: timeout (request exceeded 20 s)', $ping['reason']);
        self::assertSame('insufficient_scales', $ping['statement_types'][0]['count_fit']['fit_status']);
        self::assertSame(1000, $ping['statement_types'][0]['representative']['scale']);
        self::assertFalse($ping['statement_types'][0]['representative']['at_representative_scale']);
    }

    public function testARouteThatWasNeverCapturedSaysWhy(): void
    {
        $never = self::built()['routes'][2];
        self::assertSame('not_captured', $never['status']);
        self::assertSame([], $never['captured_scales']);
        self::assertSame([], $never['statement_types']);
        self::assertSame('scale 100: auth (needs a login); scale 1000: no cold attempt; scale 10000: no cold attempt', $never['reason']);
    }

    /**
     * D105: a capture from N = 10. The empty system (scale 0) answered not_found, is not a captured scale, and the count
     * formula of every type is made from 10, 100, 1,000 and 10,000 with `a` free.
     */
    public function testACaptureFromTenRowsFitsTheCountsOfFourScalesWithAFree(): void
    {
        $scales = [10, 100, 1000, 10000];
        $attempts = [['route' => '/shelves/{slug}', 'method' => 'GET', 'scale' => 0, 'axis' => 'N', 'attempt' => 2, 'class' => 'not_found', 'detail' => null]];
        $taps = [];
        foreach ($scales as $n) {
            $attempts[] = ['route' => '/shelves/{slug}', 'method' => 'GET', 'scale' => $n, 'axis' => 'N', 'attempt' => 2, 'class' => 'ok', 'detail' => null];
            $taps['GET /shelves/{slug}'][$n] = [
                'tap_version' => 1, 'route' => '/shelves/{slug}', 'method' => 'GET', 'uri' => '/shelves/1', 'scale' => $n, 'axis' => 'N', 'status' => 200,
                'class' => 'ok', 'phase' => 'cold',
                'window' => ['counters_available' => true, 'baseline' => 0, 'cold_rows_examined' => 7 + 3 * $n, 'statement_events' => 1 + $n, 'rows_sum' => 7 + 3 * $n, 'unaccounted' => 0, 'unread_events' => 0],
                'statements' => [
                    ['sql' => 'select * from `settings`', 'type' => 'select', 'count' => 1, 'rows_sum' => 7, 'rows_min' => 7, 'rows_max' => 7, 'unread' => 0, 'first_bindings' => [], 'last_bindings' => [], 'explain_first' => null, 'explain_last' => null],
                    ['sql' => 'select * from `entities` where `id` = ?', 'type' => 'select', 'count' => $n, 'rows_sum' => 3 * $n, 'rows_min' => 3, 'rows_max' => 3, 'unread' => 0, 'first_bindings' => [1], 'last_bindings' => [$n], 'explain_first' => null, 'explain_last' => null],
                ],
            ];
        }

        $doc = CaptureBuilder::build(['app' => 'sample-app', 'profile' => null, 'data' => 'generator', 'scales' => $scales], $attempts, $taps);

        $this->assertSame([10, 100, 1000, 10000], $doc['scales']);
        $route = $doc['routes'][0];
        $this->assertSame('captured', $route['status']);
        $this->assertSame([10, 100, 1000, 10000], $route['captured_scales']);
        $this->assertArrayNotHasKey('0', $route['totals']);
        $byPattern = array_column($route['statement_types'], null, 'pattern');
        $once = $byPattern['select * from `settings`']['count_fit'];
        $linear = $byPattern['select * from `entities` where `id` = ?']['count_fit'];
        $this->assertSame('ok', $once['fit_status']);
        $this->assertEqualsWithDelta(1.0, $once['a'] + $once['b'], 1e-9);
        $this->assertSame([10, 100, 1000, 10000], $linear['scales']);
        $this->assertEqualsWithDelta(0.0, $linear['a'], 1e-6);
        $this->assertEqualsWithDelta(1.0, $linear['b'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $linear['k'], 1e-9);
    }
}
