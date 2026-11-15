<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\RouteMix;
use PHPUnit\Framework\TestCase;

final class RouteMixTest extends TestCase
{
    public function test_even_gives_every_route_the_same_share(): void
    {
        $shares = RouteMix::shares(8, RouteMix::EVEN);
        $this->assertCount(8, $shares);
        foreach ($shares as $s) {
            $this->assertEqualsWithDelta(0.125, $s, 1e-12);
        }
    }

    public function test_first80_puts_80_percent_on_the_first_four_and_20_on_the_last_four(): void
    {
        $shares = RouteMix::shares(8, RouteMix::FIRST_80);
        $this->assertEqualsWithDelta(0.8, array_sum(array_slice($shares, 0, 4)), 1e-12);
        $this->assertEqualsWithDelta(0.2, array_sum(array_slice($shares, 4)), 1e-12);
        $this->assertEqualsWithDelta(0.2, $shares[0], 1e-12);
        $this->assertEqualsWithDelta(0.05, $shares[7], 1e-12);
    }

    public function test_last80_is_the_reverse_of_first80(): void
    {
        $first = RouteMix::shares(8, RouteMix::FIRST_80);
        $last = RouteMix::shares(8, RouteMix::LAST_80);
        $this->assertEqualsWithDelta($first, array_reverse($last), 1e-12);
        $this->assertEqualsWithDelta(0.8, array_sum(array_slice($last, 4)), 1e-12);
    }

    public function test_odd80_puts_80_percent_on_the_1st_3rd_5th_and_7th_and_20_on_the_rest(): void
    {
        $shares = RouteMix::shares(8, RouteMix::ODD_80);
        $this->assertEqualsWithDelta([0.2, 0.05, 0.2, 0.05, 0.2, 0.05, 0.2, 0.05], $shares, 1e-12);
        $odd = $even = 0.0;
        foreach ($shares as $i => $s) {
            if ($i % 2 === 0) {
                $odd += $s;
            } else {
                $even += $s;
            }
        }
        $this->assertEqualsWithDelta(0.8, $odd, 1e-12);
        $this->assertEqualsWithDelta(0.2, $even, 1e-12);
    }

    public function test_even80_puts_80_percent_on_the_2nd_4th_6th_and_8th_and_20_on_the_rest(): void
    {
        $shares = RouteMix::shares(8, RouteMix::EVEN_80);
        $this->assertSame('even80', RouteMix::EVEN_80);
        foreach ([0, 2, 4, 6] as $i) {
            $this->assertEqualsWithDelta(0.05, $shares[$i], 1e-12, 'place ' . ($i + 1));
        }
        foreach ([1, 3, 5, 7] as $i) {
            $this->assertEqualsWithDelta(0.2, $shares[$i], 1e-12, 'place ' . ($i + 1));
        }
        $this->assertEqualsWithDelta(0.8, $shares[1] + $shares[3] + $shares[5] + $shares[7], 1e-12);
    }

    public function test_even80_is_odd80_with_the_two_groups_swapped_and_the_two_add_up_to_even(): void
    {
        $even80 = RouteMix::shares(8, RouteMix::EVEN_80);
        $odd80 = RouteMix::shares(8, RouteMix::ODD_80);
        $even = RouteMix::shares(8, RouteMix::EVEN);
        foreach (range(0, 7) as $i) {
            $this->assertEqualsWithDelta($odd80[$i ^ 1], $even80[$i], 1e-12, 'route ' . ($i + 1) . ' of even80 is the neighbour of odd80');
            $this->assertEqualsWithDelta(2 * $even[$i], $even80[$i] + $odd80[$i], 1e-12, 'route ' . ($i + 1));
        }
    }

    public function test_the_mixes_that_existed_before_even80_keep_their_shares_to_the_bit(): void
    {
        $this->assertSame([0.125, 0.125, 0.125, 0.125, 0.125, 0.125, 0.125, 0.125], RouteMix::shares(8, RouteMix::EVEN));
        $this->assertSame([0.2, 0.2, 0.2, 0.2, 0.05, 0.05, 0.05, 0.05], RouteMix::shares(8, RouteMix::FIRST_80));
        $this->assertSame([0.05, 0.05, 0.05, 0.05, 0.2, 0.2, 0.2, 0.2], RouteMix::shares(8, RouteMix::LAST_80));
        $this->assertSame([0.2, 0.05, 0.2, 0.05, 0.2, 0.05, 0.2, 0.05], RouteMix::shares(8, RouteMix::ODD_80));
        $this->assertSame([RouteMix::EVEN, RouteMix::FIRST_80, RouteMix::LAST_80, RouteMix::ODD_80, RouteMix::EVEN_80, RouteMix::PLACES_1235, RouteMix::PLACES_1345], RouteMix::names());
    }

    public function test_places1235_puts_80_percent_on_the_1st_2nd_3rd_and_5th_and_20_on_the_rest(): void
    {
        $this->assertSame('places1235', RouteMix::PLACES_1235);
        $this->assertEqualsWithDelta([0.2, 0.2, 0.2, 0.05, 0.2, 0.05, 0.05, 0.05], RouteMix::shares(8, RouteMix::PLACES_1235), 1e-12);
    }

    public function test_places1345_puts_80_percent_on_the_1st_3rd_4th_and_5th_and_20_on_the_rest(): void
    {
        $this->assertSame('places1345', RouteMix::PLACES_1345);
        $this->assertEqualsWithDelta([0.2, 0.05, 0.2, 0.2, 0.2, 0.05, 0.05, 0.05], RouteMix::shares(8, RouteMix::PLACES_1345), 1e-12);
    }

    public function test_the_two_place_mixes_drive_the_zip_and_the_book_page_apart_in_the_spec_list(): void
    {
        $routes = self::listRoutes();
        $a = array_combine($routes, RouteMix::shares(8, RouteMix::PLACES_1235));
        $b = array_combine($routes, RouteMix::shares(8, RouteMix::PLACES_1345));
        $this->assertEqualsWithDelta(0.2, $a[RouteMix::ZIP_ROUTE], 1e-12);
        $this->assertEqualsWithDelta(0.05, $a[RouteMix::BOOK_PAGE_ROUTE], 1e-12);
        $this->assertEqualsWithDelta(0.05, $b[RouteMix::ZIP_ROUTE], 1e-12);
        $this->assertEqualsWithDelta(0.2, $b[RouteMix::BOOK_PAGE_ROUTE], 1e-12);
    }

    public function test_every_mix_adds_up_to_one(): void
    {
        foreach (RouteMix::names() as $mix) {
            $this->assertEqualsWithDelta(1.0, array_sum(RouteMix::shares(8, $mix)), 1e-12, $mix);
        }
    }

    public function test_an_unknown_mix_or_an_odd_or_short_list_is_refused(): void
    {
        foreach ([[8, 'random'], [7, RouteMix::EVEN], [0, RouteMix::EVEN], [1, RouteMix::EVEN]] as [$n, $mix]) {
            try {
                RouteMix::shares($n, $mix);
                $this->fail("expected a refusal for $n / $mix");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return list<string> the 8 routes of the calibration plans, in the (alphabetical) order of the route list */
    private static function listRoutes(): array
    {
        return [
            'GET /books/{bookSlug}/chapter/{chapterSlug}/references',
            'GET /books/{bookSlug}/export/zip',
            'GET /books/{bookSlug}/page/{pageSlug}/references',
            'GET /books/{slug}',
            'GET /books/{slug}/references',
            'GET /images/edit/{id}',
            'GET /oidc/callback',
            'GET /shelves/{slug}',
        ];
    }

    public function test_bookpage80zip0_gives_the_book_page_80_percent_the_zip_nothing_and_the_other_six_a_thirtieth_each(): void
    {
        $routes = self::listRoutes();
        $shares = RouteMix::shares(8, RouteMix::BOOK_PAGE_80_ZIP_0, $routes);
        $byRoute = array_combine($routes, $shares);

        $this->assertSame(0.8, $byRoute[RouteMix::BOOK_PAGE_ROUTE]);
        $this->assertSame(0.0, $byRoute[RouteMix::ZIP_ROUTE]);
        $others = array_diff_key($byRoute, [RouteMix::BOOK_PAGE_ROUTE => 0, RouteMix::ZIP_ROUTE => 0]);
        $this->assertCount(6, $others);
        foreach ($others as $route => $share) {
            $this->assertEqualsWithDelta(1 / 30, $share, 1e-12, $route);
        }
        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-12);
    }

    public function test_bookpage80zip0_points_at_the_routes_by_name_not_by_place(): void
    {
        $routes = self::listRoutes();
        $reversed = array_reverse($routes);
        $a = array_combine($routes, RouteMix::shares(8, RouteMix::BOOK_PAGE_80_ZIP_0, $routes));
        $b = array_combine($reversed, RouteMix::shares(8, RouteMix::BOOK_PAGE_80_ZIP_0, $reversed));
        ksort($a);
        ksort($b);
        $this->assertSame($a, $b);
    }

    public function test_bookpage80zip0_is_refused_without_the_list_or_without_one_of_its_two_routes(): void
    {
        $routes = self::listRoutes();
        $noZip = array_values(array_diff($routes, [RouteMix::ZIP_ROUTE])) + [7 => 'GET /other'];
        $twice = array_merge(array_slice($routes, 0, 7), [RouteMix::BOOK_PAGE_ROUTE]);
        foreach ([[8, []], [8, $noZip], [8, $twice], [2, [RouteMix::BOOK_PAGE_ROUTE, RouteMix::ZIP_ROUTE]]] as [$n, $list]) {
            try {
                RouteMix::shares($n, RouteMix::BOOK_PAGE_80_ZIP_0, $list);
                $this->fail('expected a refusal');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_other_mixes_ignore_the_list_and_keep_their_shares(): void
    {
        foreach (RouteMix::names() as $mix) {
            $this->assertSame(RouteMix::shares(8, $mix), RouteMix::shares(8, $mix, self::listRoutes()), $mix);
        }
    }
}
