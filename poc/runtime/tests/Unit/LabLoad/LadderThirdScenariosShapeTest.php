<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\Ladder;
use PeakSim\Runtime\LabLoad\RouteMix;
use PHPUnit\Framework\TestCase;

/**
 * The five prediction scenarios of the third backtest (simulator-spec.md section 7.4.2) differ in how they load the two heavy routes
 * (the book ZIP export and the book page). This is shown from the definition of the scenarios alone: the shares are worked out from
 * the committed route list and each scenario's mix, with no prediction and no measurement read.
 *
 * Why the pair and not the sum: s-1, s-4 and s-5 all put 0.25 of the rate on the two routes together (0.125 + 0.125, 0.2 + 0.05,
 * 0.05 + 0.2); they differ in how the 0.25 is split between the two, and the two routes do not cost the same. So what must differ
 * is the pair (ZIP share, book page share), and it does for all five.
 */
final class LadderThirdScenariosShapeTest extends TestCase
{
    private const IDS = ['s-1', 's-2', 's-3', 's-4', 's-5'];

    /** @return array<string, array{zip: float, book: float}> */
    private static function heavyShares(): array
    {
        $routes = array_map(static fn (array $s): string => (string) $s['route'], json_decode((string) file_get_contents(__DIR__ . '/../../../../lab/routes/route-list.json'), true)['selected']);
        $out = [];
        foreach (self::IDS as $id) {
            $byRoute = array_combine($routes, RouteMix::shares(count($routes), Ladder::scenario($id)['mix'], $routes));
            $out[$id] = ['zip' => $byRoute[RouteMix::ZIP_ROUTE], 'book' => $byRoute[RouteMix::BOOK_PAGE_ROUTE]];
        }

        return $out;
    }

    public function test_the_zip_is_the_2nd_and_the_book_page_the_4th_of_the_committed_list(): void
    {
        $routes = array_map(static fn (array $s): string => (string) $s['route'], json_decode((string) file_get_contents(__DIR__ . '/../../../../lab/routes/route-list.json'), true)['selected']);
        $this->assertCount(8, $routes);
        $this->assertSame(RouteMix::ZIP_ROUTE, $routes[1]);
        $this->assertSame(RouteMix::BOOK_PAGE_ROUTE, $routes[3]);
    }

    public function test_the_five_scenarios_put_five_different_pairs_of_shares_on_the_two_heavy_routes(): void
    {
        $pairs = array_map(static fn (array $h): string => sprintf('%.6f/%.6f', $h['zip'], $h['book']), self::heavyShares());
        $this->assertCount(5, array_unique($pairs), 'the (ZIP, book page) pairs of s-1 .. s-5: ' . implode(' ', $pairs));
    }

    public function test_the_five_scenarios_have_five_different_share_vectors_over_the_eight_routes(): void
    {
        $routes = array_map(static fn (array $s): string => (string) $s['route'], json_decode((string) file_get_contents(__DIR__ . '/../../../../lab/routes/route-list.json'), true)['selected']);
        $vectors = [];
        foreach (self::IDS as $id) {
            $vectors[$id] = implode(',', array_map(static fn (float $x): string => sprintf('%.6f', $x), RouteMix::shares(8, Ladder::scenario($id)['mix'], $routes)));
        }
        $this->assertCount(5, array_unique($vectors));
    }

    public function test_the_shares_of_the_two_heavy_routes_of_each_scenario_are_the_expected_ones(): void
    {
        $expected = [
            's-1' => ['zip' => 0.125, 'book' => 0.125],
            's-2' => ['zip' => 0.2, 'book' => 0.2],
            's-3' => ['zip' => 0.05, 'book' => 0.05],
            's-4' => ['zip' => 0.2, 'book' => 0.05],
            's-5' => ['zip' => 0.05, 'book' => 0.2],
        ];
        $got = self::heavyShares();
        foreach ($expected as $id => $want) {
            $this->assertEqualsWithDelta($want['zip'], $got[$id]['zip'], 1e-12, "$id ZIP");
            $this->assertEqualsWithDelta($want['book'], $got[$id]['book'], 1e-12, "$id book page");
        }
    }

    public function test_s4_and_s5_give_80_percent_to_four_named_places_of_the_committed_list_and_20_to_the_other_four(): void
    {
        $routes = array_map(static fn (array $s): string => (string) $s['route'], json_decode((string) file_get_contents(__DIR__ . '/../../../../lab/routes/route-list.json'), true)['selected']);
        foreach (['s-4' => [1, 2, 3, 5], 's-5' => [1, 3, 4, 5]] as $id => $places) {
            $shares = RouteMix::shares(8, Ladder::scenario($id)['mix'], $routes);
            $heavy = 0.0;
            foreach ($shares as $i => $share) {
                if (in_array($i + 1, $places, true)) {
                    $this->assertEqualsWithDelta(0.2, $share, 1e-12, "$id place " . ($i + 1));
                    $heavy += $share;
                } else {
                    $this->assertEqualsWithDelta(0.05, $share, 1e-12, "$id place " . ($i + 1));
                }
            }
            $this->assertEqualsWithDelta(0.8, $heavy, 1e-12, $id);
            $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12, $id);
        }
    }

    public function test_the_calibration_mix_cal_3_is_still_odd80(): void
    {
        $this->assertSame(RouteMix::ODD_80, Ladder::trial('cal-3')['mix']);
        $this->assertSame([0.2, 0.05, 0.2, 0.05, 0.2, 0.05, 0.2, 0.05], RouteMix::shares(8, RouteMix::ODD_80));
    }
}
