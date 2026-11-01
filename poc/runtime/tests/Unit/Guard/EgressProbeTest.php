<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Guard;

use PeakSim\Runtime\Guard\EgressProbe;
use PHPUnit\Framework\TestCase;

final class EgressProbeTest extends TestCase
{
    /**
     * @param array<string, string> $dns
     * @param list<string>          $reachable "host:port" entries that connect
     */
    private function probe(array $dns, array $reachable, ?string $gateway, bool $http): EgressProbe
    {
        return new EgressProbe(
            static fn (string $h): string => $dns[$h] ?? $h,
            static fn (string $h, int $p): bool => in_array($h . ':' . $p, $reachable, true),
            static fn (): ?string => $gateway,
            static fn (string $url): bool => $http,
        );
    }

    public function testSealedNetworkPasses(): void
    {
        $results = $this->probe(['measure-db' => '172.20.0.2'], ['measure-db:3306'], null, false)->run('measure-db', 3306);

        $this->assertTrue(EgressProbe::allOk($results));
        $kinds = array_count_values(array_column($results, 'kind'));
        $this->assertSame(['negative' => 5, 'positive' => 2], $kinds);
    }

    public function testResolvingAPublicNameFails(): void
    {
        $results = $this->probe(['measure-db' => '172.20.0.2', 'example.com' => '93.184.216.34'], ['measure-db:3306'], null, false)->run('measure-db', 3306);

        $this->assertFalse(EgressProbe::allOk($results));
    }

    public function testReachingAPublicAddressFails(): void
    {
        foreach (['1.1.1.1:443', '8.8.8.8:53'] as $open) {
            $results = $this->probe(['measure-db' => '172.20.0.2'], ['measure-db:3306', $open], null, false)->run('measure-db', 3306);
            $this->assertFalse(EgressProbe::allOk($results), $open);
        }
    }

    public function testAnHttpResponseFails(): void
    {
        $results = $this->probe(['measure-db' => '172.20.0.2'], ['measure-db:3306'], null, true)->run('measure-db', 3306);

        $this->assertFalse(EgressProbe::allOk($results));
    }

    public function testADefaultRouteFailsEvenIfNothingAnswered(): void
    {
        $results = $this->probe(['measure-db' => '172.20.0.2'], ['measure-db:3306'], '172.20.0.1', false)->run('measure-db', 3306);

        $this->assertFalse(EgressProbe::allOk($results));
    }

    public function testUnreachableDbServiceFails(): void
    {
        $results = $this->probe(['measure-db' => '172.20.0.2'], [], null, false)->run('measure-db', 3306);

        $this->assertFalse(EgressProbe::allOk($results));
    }

    public function testDbServiceResolvingToAPublicAddressFails(): void
    {
        $results = $this->probe(['measure-db' => '93.184.216.34'], ['measure-db:3306'], null, false)->run('measure-db', 3306);

        $this->assertFalse(EgressProbe::allOk($results));
    }

    public function testNoResultsIsNotAPass(): void
    {
        $this->assertFalse(EgressProbe::allOk([]));
    }

    public function testExitCodeIsEleven(): void
    {
        $this->assertSame(11, EgressProbe::EXIT_CODE);
    }
}
