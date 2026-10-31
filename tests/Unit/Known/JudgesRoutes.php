<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Known;

use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\Engine;
use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Analysis\Handlers\Cache\CacheOps;
use PeakSim\Analysis\Handlers\Known\KnownCalls;
use PeakSim\Input\RouteEntry;
use PeakSim\Judge\Judge;
use PeakSim\Model\Gap;
use PeakSim\Model\RouteResult;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Unit\Engine\Support\FakeBindingTable;
use PeakSim\Tests\Unit\Engine\Support\FakeModelCatalog;
use PeakSim\Tests\Unit\Engine\Support\StubCollectionHandler;
use PeakSim\Tests\Unit\Engine\Support\StubQueryHandler;
use PeakSim\Tests\Unit\Engine\Support\TempApp;

/**
 * A one-controller app, the real engine with the PK handlers in the order the real registry will use (cache first,
 * the allow-list last) and the real Judge on what comes out. The Query and Collection handlers are the engine
 * tests' stubs, the sink is the StubSink next door.
 */
trait JudgesRoutes
{
    private ?Engine $engine = null;

    /**
     * @param array<string, string> $more extra app files
     */
    private function judge(string $body, string $uses = '', string $params = '', array $more = []): RouteResult
    {
        $code = <<<PHP
        <?php

        namespace App\\Http\\Controllers;

        use App\\Models\\Order;
        use Illuminate\\Http\\JsonResponse;
        {$uses}

        class T
        {
            public function __invoke({$params}): JsonResponse
            {
        {$body}
            }
        }
        PHP;
        $app = new TempApp(['app/Http/Controllers/T.php' => $code] + $more);
        $registry = new HandlerRegistry();
        $registry->register(new StubSink(), 5);
        $registry->register(new CacheOps(), 8);
        $registry->register(new StubQueryHandler(), 10);
        $registry->register(new StubCollectionHandler(), 20);
        $registry->register(new KnownCalls(), 90);
        $this->engine = new Engine(
            new AppSourceIndex($app->root),
            new FakeModelCatalog([
                'App\\Models\\Order' => ['table' => 'orders', 'relations' => [
                    'customer' => ['App\\Models\\Customer', false],
                    'items' => ['App\\Models\\Item', true],
                ]],
                'App\\Models\\Customer' => ['table' => 'customers'],
                'App\\Models\\Item' => ['table' => 'items'],
            ]),
            new FakeBindingTable(),
            $registry,
            new EngineOptions(auditUnknown: true),
        );
        $summary = $this->engine->analyzeAction('App\\Http\\Controllers\\T', '__invoke');

        return (new Judge())->judge(new RouteEntry('/t', ['GET'], null, 'App\\Http\\Controllers\\T@__invoke', [], null, null), $summary);
    }

    /** @return list<string> */
    private function gapCodes(RouteResult $r): array
    {
        return array_map(static fn (Gap $g): string => $g->code->value, $r->gaps);
    }
}
