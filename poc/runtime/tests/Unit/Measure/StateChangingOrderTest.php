<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\DatabaseBuilder;
use PeakSim\Runtime\Measure\DatabasePoint;
use PeakSim\Runtime\Measure\Driver;
use PeakSim\Runtime\Measure\MeasureRequest;
use PeakSim\Runtime\Measure\OneResult;
use PeakSim\Runtime\Measure\OneRunner;
use PeakSim\Runtime\Measure\Profile;
use PeakSim\Runtime\Measure\RouteOrder;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Measure\SeederPlanner;
use PeakSim\Runtime\Run\Attempt;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The routes a profile says rewrite a table other routes read are measured after every other route of their database, in the order the
 * profile lists them; every other route keeps its place. No database and no app: the order is read from what the runner is asked.
 */
final class StateChangingOrderTest extends PlannerTestCase
{
    /** @return list<string> */
    private static function order(array $lastRoutes): array
    {
        $runner = new class implements OneRunner {
            /** @var list<MeasureRequest> */
            public array $requests = [];

            public function run(MeasureRequest $request): OneResult
            {
                $this->requests[] = $request;
                $attempts = [];
                foreach ($request->phases as $phase) {
                    $attempts[] = new Attempt($request->route, $request->method, $request->scale, $request->axis, $phase, 200, 1, ['mysql' => 1], ['orders'], 50, 1000, $request->gateBypass, 'ok', null);
                }

                return OneResult::ok($attempts);
            }
        };
        $builder = new class implements DatabaseBuilder {
            public function build(DatabasePoint $point): array
            {
                return [];
            }

            public function drop(DatabasePoint $point): void
            {
            }
        };
        $tasks = [
            new RouteTask(self::spec('/a', [], 'POST'), '/a'),
            new RouteTask(self::spec('/b', []), '/b'),
            new RouteTask(self::spec('/c', [], 'PUT'), '/c'),
            new RouteTask(self::spec('/d', []), '/d'),
        ];
        (new Driver(new SeederPlanner(), $builder, $runner, warm: false, lastRoutes: $lastRoutes))->run($tasks, [100]);

        // the requests of the database of 100 rows (the scale after the empty system's), by route
        return array_values(array_map(
            static fn (MeasureRequest $r): string => $r->method . ' ' . $r->route,
            array_filter($runner->requests, static fn (MeasureRequest $r): bool => $r->scale === 100),
        ));
    }

    public function test_with_no_state_changing_route_the_order_is_the_order_of_the_task_list(): void
    {
        $this->assertSame(['POST /a', 'GET /b', 'PUT /c', 'GET /d'], self::order([]));
    }

    public function test_a_state_changing_route_is_measured_after_every_other_route_of_the_database(): void
    {
        $this->assertSame(['GET /b', 'PUT /c', 'GET /d', 'POST /a'], self::order(['POST /a']));
    }

    public function test_two_of_them_go_in_the_order_the_profile_lists_them_not_the_order_of_the_task_list(): void
    {
        $this->assertSame(['GET /b', 'GET /d', 'PUT /c', 'POST /a'], self::order(['PUT /c', 'POST /a']));
        $this->assertSame(['GET /b', 'GET /d', 'POST /a', 'PUT /c'], self::order(['POST /a', 'PUT /c']));
    }

    public function test_a_listed_route_that_is_not_in_the_run_changes_nothing(): void
    {
        $this->assertSame(['POST /a', 'GET /b', 'PUT /c', 'GET /d'], self::order(['GET /not/there']));
    }

    public function test_the_ordering_rule_keeps_the_place_of_every_other_item_and_of_a_repeated_key(): void
    {
        $items = [['x', 1], ['y', 2], ['x', 3], ['z', 4]];

        $this->assertSame([['y', 2], ['z', 4], ['x', 1], ['x', 3]], RouteOrder::lastRoutesLast($items, static fn (array $i): string => $i[0], ['x']));
        $this->assertSame($items, RouteOrder::lastRoutesLast($items, static fn (array $i): string => $i[0], []));
    }

    public function test_the_bookstack_profile_lists_the_book_update_first_and_the_regeneration_last_with_their_tables(): void
    {
        $listed = Profile::stateChanging(Profile::load('bookstack'));

        $this->assertSame(['PUT /books/{slug}', 'POST /settings/maintenance/regenerate-references'], array_keys($listed));
        $this->assertSame(['slug_history'], $listed['PUT /books/{slug}']);
        $this->assertSame(['references'], $listed['POST /settings/maintenance/regenerate-references']);
    }

    /** @return iterable<string, array{string}> */
    public static function otherProfiles(): iterable
    {
        yield 'the verification app' => ['fixtures'];
        yield 'Firefly III' => ['firefly'];
        yield 'Koel' => ['koel'];
    }

    #[DataProvider('otherProfiles')]
    public function test_the_other_profiles_name_no_state_changing_route_so_their_order_is_as_it_was(string $name): void
    {
        $this->assertSame([], Profile::stateChanging(Profile::load($name)));
    }

    /** @return iterable<string, array{string}> */
    public static function badEntries(): iterable
    {
        yield 'not a list' => ["'state_changing' => 'x'"];
        yield 'empty' => ["'state_changing' => []"];
        yield 'a route with no method' => ["'state_changing' => [['route' => '/a', 'tables' => ['t'], 'why' => 'w']]"];
        yield 'no tables' => ["'state_changing' => [['route' => 'GET /a', 'tables' => [], 'why' => 'w']]"];
        yield 'a table that is not a plain name' => ["'state_changing' => [['route' => 'GET /a', 'tables' => ['t;x'], 'why' => 'w']]"];
        yield 'no why' => ["'state_changing' => [['route' => 'GET /a', 'tables' => ['t']]]"];
        yield 'the same route twice' => ["'state_changing' => [['route' => 'GET /a', 'tables' => ['t'], 'why' => 'w'], ['route' => 'GET /a', 'tables' => ['t'], 'why' => 'w']]"];
    }

    #[DataProvider('badEntries')]
    public function test_a_badly_formed_state_changing_list_is_refused_when_the_profile_is_loaded(string $entry): void
    {
        $dir = sys_get_temp_dir() . '/peak-state-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/bad.php', '<?php return [' . $entry . '];');

        try {
            Profile::load('bad', $dir);
            $this->fail('the badly formed list was accepted');
        } catch (BuildFailure $e) {
            $this->assertStringContainsString('state_changing', $e->getMessage());
        } finally {
            unlink($dir . '/bad.php');
            rmdir($dir);
        }
    }

    public function test_the_driver_of_bin_measure_is_given_the_profiles_routes(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/inner/measure.php');

        $this->assertStringContainsString('array_keys(Profile::stateChanging(Profile::load($options->profile)))', $source);
        $this->assertStringContainsString('$options->tapDir, $lastRoutes, $options->dropDb)', $source);
    }
}
