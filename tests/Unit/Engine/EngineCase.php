<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\ActionReturnHook;
use PeakSim\Analysis\Engine\CandidateComparison;
use PeakSim\Analysis\Engine\Engine;
use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Model\CostEvent;
use PeakSim\Model\EventKind;
use PeakSim\Model\Gap;
use PeakSim\Model\GapCode;
use PeakSim\Model\Summary;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Unit\Engine\Support\FakeBindingTable;
use PeakSim\Tests\Unit\Engine\Support\FakeModelCatalog;
use PeakSim\Tests\Unit\Engine\Support\StubCollectionHandler;
use PeakSim\Tests\Unit\Engine\Support\StubKnownHandler;
use PeakSim\Tests\Unit\Engine\Support\StubQueryHandler;
use PeakSim\Tests\Unit\Engine\Support\TempApp;
use PHPUnit\Framework\TestCase;

/** Builds a throwaway app, an engine over it with the stub handlers, and reads the summary back. */
abstract class EngineCase extends TestCase
{
    protected const CONTROLLER = 'app/Http/Controllers/';

    private ?TempApp $app = null;

    protected ?Engine $engine = null;

    /**
     * @param array<string, string> $files
     * @param array<string, list<string>|string> $bindings a string is the reason of an Unresolvable result
     */
    protected function analyze(array $files, string $class, string $method = '__invoke', array $bindings = [], ?EngineOptions $options = null, ?ActionReturnHook $hook = null): Summary
    {
        $this->app = new TempApp($files);
        $registry = new HandlerRegistry();
        $registry->register(new StubQueryHandler(), 10);
        $registry->register(new StubCollectionHandler(), 20);
        $registry->register(new StubKnownHandler(), 30);
        $this->engine = new Engine(
            new AppSourceIndex($this->app->root),
            new FakeModelCatalog([
                'App\Models\Order' => ['table' => 'orders', 'relations' => ['customer' => ['App\Models\Customer', false], 'items' => ['App\Models\Item', true]]],
                'App\Models\Customer' => ['table' => 'customers'],
                'App\Models\Item' => ['table' => 'items', 'relations' => ['product' => ['App\Models\Product', false]]],
                'App\Models\Product' => ['table' => 'products'],
            ]),
            new FakeBindingTable($bindings),
            $registry,
            $options ?? new EngineOptions(auditUnknown: true),
            $hook,
        );

        return $this->engine->analyzeAction($class, $method);
    }

    /** A controller with one __invoke body, in namespace App\Http\Controllers. @param array<string, string> $more */
    protected function invokable(string $body, string $uses = '', array $more = []): Summary
    {
        $code = <<<PHP
        <?php

        namespace App\Http\Controllers;

        use App\Models\Order;
        use App\Models\Customer;
        {$uses}

        class T
        {
            public function __invoke()
            {
        {$body}
            }
        }
        PHP;

        return $this->analyze([self::CONTROLLER . 'T.php' => $code] + $more, 'App\Http\Controllers\T');
    }

    /** @return list<CostEvent> */
    protected function events(Summary $s, ?EventKind $kind = null): array
    {
        return array_values(array_filter($s->events, static fn (CostEvent $e): bool => $kind === null || $e->kind === $kind));
    }

    /** @return list<GapCode> */
    protected function gapCodes(Summary $s): array
    {
        return array_map(static fn (Gap $g): GapCode => $g->code, $s->gaps);
    }

    /** @return array{query: int, memory: int} */
    protected function degrees(Summary $s): array
    {
        return CandidateComparison::degrees($s);
    }

    /** Invariant I1 over the whole walk. */
    protected function assertNoUnknownWithoutGap(): void
    {
        self::assertNotNull($this->engine);
        self::assertSame([], $this->engine->violations());
    }
}
