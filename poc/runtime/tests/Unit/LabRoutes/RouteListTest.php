<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabRoutes;

use PeakSim\Lab\Routes\RouteList;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../lab/routes/RouteList.php';

/** The route list of the lab backtest (simulator-spec.md section 7.2) is made by the rule of that section, and by nothing else. */
final class RouteListTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 5);
    }

    /** @return array<string, mixed> */
    private static function estimate(string ...$routes): array
    {
        $out = [];
        foreach ($routes as $route) {
            $out[] = ['route' => $route, 'status' => 'captured', 'complete' => true];
        }

        return ['routes' => $out];
    }

    /**
     * @param array<string, string|null> $bounds route => static lower bound
     *
     * @return array<string, mixed>
     */
    private static function fits(array $bounds): array
    {
        $out = [];
        foreach ($bounds as $route => $bound) {
            $out[] = ['route' => $route, 'static_lower_bound' => $bound];
        }

        return ['routes' => $out];
    }

    /** @return list<string> */
    private static function selected(array $list): array
    {
        return array_column($list['selected'], 'route');
    }

    public function test_the_degree_is_the_sum_of_the_exponents_and_no_bound_is_zero(): void
    {
        $this->assertSame(0, RouteList::degree(null));
        $this->assertSame(0, RouteList::degree(''));
        $this->assertSame(0, RouteList::degree('O(1)'));
        $this->assertSame(1, RouteList::degree('O(N)'));
        $this->assertSame(2, RouteList::degree('O(N*M)'));
        $this->assertSame(2, RouteList::degree('O(N^2)'));
        $this->assertSame(3, RouteList::degree('O(N^2*M)'));
    }

    public function test_a_form_of_bound_it_does_not_know_is_refused_not_read_as_zero(): void
    {
        foreach (['N', 'O(N log N)', 'O(N+M)', 'O()', 'O(N^0)', 'O(n)'] as $bound) {
            try {
                RouteList::degree($bound);
                $this->fail('accepted: ' . $bound);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($bound, $e->getMessage());
            }
        }
    }

    public function test_a_larger_degree_comes_first_and_a_route_without_a_bound_comes_last(): void
    {
        $list = RouteList::build(
            self::estimate('GET /none', 'GET /one', 'GET /two', 'GET /zero'),
            self::fits(['GET /none' => null, 'GET /one' => 'O(N)', 'GET /two' => 'O(N*M)', 'GET /zero' => 'O(1)']),
        );
        // O(1) and no bound are the same degree, so they fall back to the string order
        $this->assertSame(['GET /two', 'GET /one', 'GET /none', 'GET /zero'], self::selected($list));
    }

    public function test_the_same_degree_is_ordered_by_the_string_of_method_and_uri_in_byte_order(): void
    {
        $routes = ['GET /b', 'GET /a/{x}', 'GET /B', 'GET /a/x', 'GET /a', 'GET /_', 'GET /a/{x}/z'];
        $list = RouteList::build(self::estimate(...$routes), self::fits(array_fill_keys($routes, 'O(N)')));
        $expected = $routes;
        usort($expected, 'strcmp');
        $this->assertSame($expected, self::selected($list));
        // a spot check of the byte order itself: upper case before lower case, "/_" (0x5F) before "/a" (0x61), "x" (0x78) before "{" (0x7B)
        $this->assertSame(['GET /B', 'GET /_', 'GET /a', 'GET /a/x', 'GET /a/{x}', 'GET /a/{x}/z', 'GET /b'], self::selected($list));
    }

    public function test_the_list_does_not_depend_on_the_order_of_the_input(): void
    {
        $bounds = ['GET /a' => 'O(N)', 'GET /b' => 'O(N*M)', 'GET /c' => null, 'GET /d' => 'O(N)'];
        $forward = RouteList::build(self::estimate(...array_keys($bounds)), self::fits($bounds));
        $backward = RouteList::build(self::estimate(...array_reverse(array_keys($bounds))), self::fits(array_reverse($bounds, true)));
        $this->assertSame(json_encode($forward), json_encode($backward));
    }

    public function test_only_the_top_eight_are_taken_in_a_first_half_of_four_and_a_second_half_of_four(): void
    {
        $routes = [];
        for ($i = 1; $i <= 10; $i++) {
            $routes[] = sprintf('GET /r%02d', $i);
        }
        $bounds = array_fill_keys($routes, 'O(N)');
        $bounds['GET /r10'] = 'O(N*M)'; // jumps to the front
        $list = RouteList::build(self::estimate(...$routes), self::fits($bounds));

        $this->assertSame(
            ['GET /r10', 'GET /r01', 'GET /r02', 'GET /r03', 'GET /r04', 'GET /r05', 'GET /r06', 'GET /r07'],
            self::selected($list),
        );
        $this->assertSame(['first', 'first', 'first', 'first', 'second', 'second', 'second', 'second'], array_column($list['selected'], 'half'));
        $this->assertSame(range(1, 8), array_column($list['selected'], 'rank'));
        $this->assertSame(8, $list['counts']['selected']);

        // the two left out stay in the list of candidates, marked as not selected and with no half
        $this->assertCount(10, $list['candidates']);
        $left = array_values(array_filter($list['candidates'], static fn (array $c): bool => !$c['selected']));
        $this->assertSame(['GET /r08', 'GET /r09'], array_column($left, 'route'));
        $this->assertSame([null, null], array_column($left, 'half'));
    }

    public function test_a_tie_at_the_edge_of_the_eight_is_cut_by_the_string_order(): void
    {
        $routes = ['GET /m', 'GET /n', 'GET /a', 'GET /b', 'GET /c', 'GET /d', 'GET /e', 'GET /f', 'GET /g'];
        $list = RouteList::build(self::estimate(...$routes), self::fits(array_fill_keys($routes, 'O(N)')));
        // nine routes of one degree: the ninth by string order ("GET /n") is the one left out
        $this->assertSame(['GET /a', 'GET /b', 'GET /c', 'GET /d', 'GET /e', 'GET /f', 'GET /g', 'GET /m'], self::selected($list));
    }

    public function test_fewer_than_eight_candidates_give_fewer_routes_and_nothing_is_made_up(): void
    {
        $list = RouteList::build(self::estimate('GET /a', 'GET /b', 'GET /c'), self::fits(['GET /a' => 'O(N)', 'GET /b' => 'O(N)', 'GET /c' => 'O(N)']));
        $this->assertSame(['GET /a', 'GET /b', 'GET /c'], self::selected($list));
        $this->assertSame(['first', 'first', 'first'], array_column($list['selected'], 'half'));
    }

    public function test_only_get_routes_with_a_complete_captured_estimate_are_taken(): void
    {
        $estimate = ['routes' => [
            ['route' => 'POST /heavy', 'status' => 'captured', 'complete' => true],
            ['route' => 'PUT /heavy', 'status' => 'captured', 'complete' => true],
            ['route' => 'GET /incomplete', 'status' => 'captured', 'complete' => false],
            ['route' => 'GET /failed', 'status' => 'failed', 'complete' => true],
            ['route' => 'GET /no-status', 'complete' => true],
            ['route' => 'GET /ok', 'status' => 'captured', 'complete' => true],
            ['route' => 'GETX /not-a-get', 'status' => 'captured', 'complete' => true],
        ]];
        $bounds = ['POST /heavy' => 'O(N*M)', 'PUT /heavy' => 'O(N*M)', 'GET /incomplete' => 'O(N*M)', 'GET /failed' => 'O(N*M)',
            'GET /no-status' => 'O(N*M)', 'GET /ok' => null, 'GETX /not-a-get' => 'O(N*M)'];
        $list = RouteList::build($estimate, self::fits($bounds));

        $this->assertSame(['GET /ok'], self::selected($list));
        $this->assertSame(['GET /failed', 'GET /incomplete', 'GET /no-status'], $list['get_routes_without_estimate']);
        $this->assertSame(['get_routes_in_estimate' => 4, 'get_routes_with_estimate' => 1, 'get_routes_without_estimate' => 3, 'selected' => 1], $list['counts']);
    }

    public function test_a_route_the_static_analysis_has_no_entry_for_is_ranked_as_having_no_bound(): void
    {
        $list = RouteList::build(self::estimate('GET /known', 'GET /unknown'), self::fits(['GET /known' => 'O(N)']));
        $this->assertSame(['GET /known', 'GET /unknown'], self::selected($list));
        $this->assertNull($list['selected'][1]['static_lower_bound']);
        $this->assertSame(0, $list['selected'][1]['degree']);
    }

    public function test_the_output_holds_no_time_and_no_path_of_this_machine(): void
    {
        $inputs = ['estimate' => RouteList::inputRecord('data/estimate.json', 'x'), 'fits' => RouteList::inputRecord('data/fits.json', 'y')];
        $json = (string) json_encode(RouteList::build(self::estimate('GET /a'), self::fits(['GET /a' => 'O(N)']), $inputs));
        $this->assertSame($json, (string) json_encode(RouteList::build(self::estimate('GET /a'), self::fits(['GET /a' => 'O(N)']), $inputs)));
        $this->assertSame(hash('sha256', 'x'), $inputs['estimate']['sha256']);
        $this->assertDoesNotMatchRegularExpression('~/home/|\\d{4}-\\d{2}-\\d{2}~', $json);
    }

    public function test_the_committed_list_is_what_the_script_makes_from_the_committed_inputs(): void
    {
        $dir = self::root() . '/poc/lab/routes';
        $list = json_decode((string) file_get_contents($dir . '/route-list.json'), true);
        $this->assertIsArray($list);

        $records = [];
        $decoded = [];
        foreach (['estimate', 'fits'] as $name) {
            $path = $list['inputs'][$name]['path'];
            $contents = (string) file_get_contents(self::root() . '/' . $path);
            $this->assertSame($list['inputs'][$name]['sha256'], hash('sha256', $contents), "the input $name changed after the list was made");
            $records[$name] = RouteList::inputRecord($path, $contents);
            $decoded[$name] = json_decode($contents, true);
        }

        $fresh = RouteList::build($decoded['estimate'], $decoded['fits'], $records);
        $this->assertSame(
            (string) file_get_contents($dir . '/route-list.json'),
            json_encode($fresh, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        );
        $this->assertCount(RouteList::TOP, $list['selected']);
        foreach ($list['selected'] as $c) {
            $this->assertStringStartsWith('GET ', $c['route']);
        }
        $degrees = array_column($list['candidates'], 'degree');
        $sorted = $degrees;
        rsort($sorted);
        $this->assertSame($sorted, $degrees, 'the degrees must not increase down the list');
    }

    public function test_the_sha256_file_matches_the_inputs_the_script_and_the_list(): void
    {
        $dir = self::root() . '/poc/lab/routes';
        $lines = array_filter(explode("\n", (string) file_get_contents($dir . '/SHA256SUMS')));
        $this->assertNotEmpty($lines);
        $paths = [];
        foreach ($lines as $line) {
            $this->assertSame(1, preg_match('~^([0-9a-f]{64})  (\S+)$~', $line, $m), 'bad line: ' . $line);
            $this->assertSame($m[1], hash_file('sha256', self::root() . '/' . $m[2]), $m[2] . ' differs from its recorded SHA-256');
            $paths[] = $m[2];
        }
        foreach (['poc/lab/routes/RouteList.php', 'poc/lab/routes/make-route-list', 'poc/lab/routes/make-route-list.php', 'poc/lab/routes/route-list.json',
            'docs/research/extrapolation-data-2/bookstack/estimate.json', 'docs/research/extrapolation-data-2/bookstack/fits.json'] as $must) {
            $this->assertContains($must, $paths);
        }
    }
}
