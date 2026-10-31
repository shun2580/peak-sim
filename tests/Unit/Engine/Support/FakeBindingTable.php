<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine\Support;

use PeakSim\Binding\BindingResult;
use PeakSim\Binding\BindingTable;
use PeakSim\Binding\Candidate;
use PeakSim\Model\Site;

final class FakeBindingTable implements BindingTable
{
    /** @param array<string, list<string>> $bindings abstract => implementations */
    public function __construct(private readonly array $bindings = [])
    {
    }

    public function resolve(string $abstract, ?string $consumer = null): BindingResult
    {
        $impls = $this->bindings[ltrim($abstract, '\\')] ?? [];
        if ($impls === []) {
            return BindingResult::none();
        }

        return BindingResult::candidates(array_map(
            static fn (string $impl): Candidate => new Candidate($impl, new Site('app/Providers/AppServiceProvider.php', 1), false, 'bind'),
            $impls,
        ));
    }
}
