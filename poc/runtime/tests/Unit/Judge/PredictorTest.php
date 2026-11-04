<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Judge;

use PeakSim\Runtime\Judge\Predictor;
use PHPUnit\Framework\TestCase;

/** Expected values are worked out by hand from y = a + b * N^k. */
final class PredictorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fit(float $a, float $b, float $k): array
    {
        return ['fit_status' => 'ok', 'a' => $a, 'b' => $b, 'k' => $k, 'sse' => 0.0, 'scales' => [0, 100, 1000, 10000], 'pairs' => []];
    }

    /**
     * @param array<string, mixed> $quantities
     *
     * @return array<string, mixed>
     */
    private static function fits(string $route, array $quantities): array
    {
        return ['schema_version' => '0.1', 'source' => 'run-1', 'routes' => [['route' => $route, 'axis' => 'N', 'table' => 'pages', 'static_lower_bound' => 'O(N)', 'quantities' => $quantities]]];
    }

    public function test_linear_formula_at_one_hundred_thousand(): void
    {
        // 4 + 1 * 100000 = 100004
        $out = Predictor::predict(self::fits('GET /a', ['queries' => self::fit(4.0, 1.0, 1.0)]), 100000);

        $q = $out['routes'][0]['quantities']['queries'];
        $this->assertSame('predicted', $q['status']);
        $this->assertEqualsWithDelta(100004.0, $q['value'], 1e-6);
        $this->assertSame(100000, $out['at']);
        $this->assertSame('run-1', $out['source']);
    }

    public function test_constant_formula_ignores_n(): void
    {
        // k = 0: b * N^0 = b, so 7 + 3 = 10 at any N
        $out = Predictor::predict(self::fits('GET /a', ['queries' => self::fit(7.0, 3.0, 0.0)]), 100000);

        $this->assertEqualsWithDelta(10.0, $out['routes'][0]['quantities']['queries']['value'], 1e-9);
    }

    public function test_quadratic_formula_with_zero_a(): void
    {
        // 0 + 0.5 * 100^2 = 5000
        $out = Predictor::predict(self::fits('GET /a', ['rows_examined' => self::fit(0.0, 0.5, 2.0)]), 100);

        $this->assertEqualsWithDelta(5000.0, $out['routes'][0]['quantities']['rows_examined']['value'], 1e-9);
    }

    public function test_fractional_exponent(): void
    {
        // 1 + 2 * 10000^0.5 = 1 + 2 * 100 = 201
        $out = Predictor::predict(self::fits('GET /a', ['peak_memory' => self::fit(1.0, 2.0, 0.5)]), 10000);

        $this->assertEqualsWithDelta(201.0, $out['routes'][0]['quantities']['peak_memory']['value'], 1e-9);
    }

    public function test_a_fit_that_is_not_ok_gets_no_value_and_keeps_its_reason(): void
    {
        $out = Predictor::predict(self::fits('GET /a', [
            'queries' => ['fit_status' => 'contradicts_static', 'reason' => 'k 0.1 under a O(N) bound'],
            'peak_memory' => ['fit_status' => 'insufficient_scales', 'reason' => 'scale 10000: timeout'],
        ]), 100000);

        $queries = $out['routes'][0]['quantities']['queries'];
        $this->assertSame('not_predicted', $queries['status']);
        $this->assertSame('contradicts_static', $queries['fit_status']);
        $this->assertSame('k 0.1 under a O(N) bound', $queries['reason']);
        $this->assertArrayNotHasKey('value', $queries);
        $this->assertSame('scale 10000: timeout', $out['routes'][0]['quantities']['peak_memory']['reason']);
    }

    public function test_routes_come_out_in_the_order_of_their_names_and_the_output_is_repeatable(): void
    {
        $fits = self::fits('GET /b', ['queries' => self::fit(1.0, 1.0, 1.0)]);
        $fits['routes'][] = self::fits('GET /a', ['queries' => self::fit(2.0, 1.0, 1.0)])['routes'][0];
        $reversed = $fits;
        $reversed['routes'] = array_reverse($fits['routes']);

        $first = Predictor::predict($fits, 1000);

        $this->assertSame(['GET /a', 'GET /b'], array_column($first['routes'], 'route'));
        $this->assertSame(json_encode($first), json_encode(Predictor::predict($reversed, 1000)));
    }

    public function test_n_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Predictor::predict(self::fits('GET /a', []), 0);
    }
}
