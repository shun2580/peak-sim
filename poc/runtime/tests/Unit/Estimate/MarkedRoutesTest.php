<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Estimate;

use PeakSim\Runtime\Estimate\MarkedRoutes;
use PeakSim\Runtime\Estimate\MarkedRoutesCommand;
use PHPUnit\Framework\TestCase;

/** The marked routes are made from an explain.json by the rule of simulator-spec.md section 7.1, and by nothing else. */
final class MarkedRoutesTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function stmt(string $id, bool $changes = false, bool $unstable = false): array
    {
        return ['id' => $id, 'marks' => ['plan_changes_at_scale' => $changes, 'plan_unstable' => $unstable, 'explain_unavailable' => null, 'unavailable_states' => [], 'unstable_states' => []]];
    }

    /** @return array<string, mixed> */
    private static function explain(): array
    {
        return ['explain_version' => 1, 'scale' => 200000, 'capture' => ['app' => 'demo'], 'routes' => [
            ['route' => 'POST /z', 'statements' => [self::stmt('a', unstable: true)]],
            ['route' => 'GET /none', 'statements' => [self::stmt('b'), self::stmt('c')]],
            ['route' => 'GET /both', 'statements' => [self::stmt('d', changes: true), self::stmt('e', unstable: true), self::stmt('f')]],
            ['route' => 'GET /empty', 'statements' => []],
            ['route' => 'GET /changes', 'statements' => [self::stmt('g'), self::stmt('h', changes: true)]],
        ]];
    }

    public function test_a_route_with_one_marked_statement_is_marked_and_the_others_are_not(): void
    {
        $out = MarkedRoutes::list(self::explain());
        $this->assertSame(['GET /both', 'GET /changes', 'POST /z'], array_column($out['routes'], 'route'));
        $this->assertSame(3, $out['marked_count']);
        $this->assertSame(5, $out['routes_in_explain']);
        $this->assertSame('demo', $out['app']);
        $this->assertSame(200000, $out['at']);
    }

    public function test_each_marked_route_lists_the_statements_and_marks_that_made_it_marked(): void
    {
        $out = MarkedRoutes::list(self::explain());
        $this->assertSame([['id' => 'd', 'marks' => ['plan_changes_at_scale']], ['id' => 'e', 'marks' => ['plan_unstable']]], $out['routes'][0]['statements']);
    }

    public function test_the_list_does_not_depend_on_the_order_of_the_routes(): void
    {
        $explain = self::explain();
        $reversed = $explain;
        $reversed['routes'] = array_reverse($explain['routes']);
        $this->assertSame(MarkedRoutes::list($explain), MarkedRoutes::list($reversed));
    }

    public function test_a_mark_that_is_not_exactly_true_does_not_mark_a_route(): void
    {
        $explain = ['scale' => 200000, 'routes' => [['route' => 'GET /x', 'statements' => [['id' => 'a', 'marks' => ['plan_unstable' => 'yes', 'plan_changes_at_scale' => 1]]]]]];
        $this->assertSame(0, MarkedRoutes::list($explain)['marked_count']);
    }

    public function test_an_explain_without_routes_gives_an_empty_list(): void
    {
        $out = MarkedRoutes::list(['scale' => 200000]);
        $this->assertSame([], $out['routes']);
        $this->assertSame(0, $out['marked_count']);
    }

    public function test_the_command_prints_the_list_and_refuses_a_wrong_usage_with_exit_two(): void
    {
        $dir = sys_get_temp_dir() . '/peak-marked-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/explain.json', json_encode(self::explain()));
        file_put_contents($dir . '/bad.json', '{not json');
        try {
            $run = static function (array $args): array {
                $out = fopen('php://memory', 'w+');
                $err = fopen('php://memory', 'w+');
                $code = (new MarkedRoutesCommand($out, $err))->run($args);
                rewind($out);
                rewind($err);

                return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
            };
            [$code, $out] = $run(['--explain=' . $dir . '/explain.json']);
            $this->assertSame(0, $code);
            $this->assertSame(3, json_decode($out, true)['marked_count']);
            foreach ([[], ['--bogus'], ['--explain=' . $dir . '/missing.json'], ['--explain=' . $dir . '/bad.json']] as $args) {
                [$code, $out, $err] = $run($args);
                $this->assertSame(2, $code);
                $this->assertSame('', $out);
                $this->assertStringContainsString('usage: bin/marked-routes', $err);
            }
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
