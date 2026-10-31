<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Blade;

use PeakSim\Analysis\Blade\ViewHandler;
use PeakSim\Analysis\Contracts\HandlerRegistry;
use PeakSim\Analysis\Engine\CandidateComparison;
use PeakSim\Analysis\Engine\Engine;
use PeakSim\Analysis\Engine\EngineOptions;
use PeakSim\Analysis\Handlers\Collection\CollectionHandlers;
use PeakSim\Model\CostEvent;
use PeakSim\Model\EventKind;
use PeakSim\Model\Gap;
use PeakSim\Model\GapCode;
use PeakSim\Model\Summary;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Unit\Engine\Support\FakeBindingTable;
use PeakSim\Tests\Unit\Engine\Support\FakeModelCatalog;
use PeakSim\Tests\Unit\Engine\Support\StubKnownHandler;
use PeakSim\Tests\Unit\Engine\Support\StubQueryHandler;
use PeakSim\Tests\Unit\Engine\Support\TempApp;
use PHPUnit\Framework\TestCase;

/**
 * A throwaway app with one controller action and some Blade views, walked by the real engine with the view
 * handler in front of the stub query handler. The views are the ones the blade-* mini-apps use, so what a
 * mini-app expects is what these tests pin down one level lower.
 */
abstract class ViewHandlerCase extends TestCase
{
    protected const VIEW = 'resources/views/';

    private const CONTROLLER = <<<'PHP'
    <?php

    namespace App\Http\Controllers;

    use App\Models\Order;
    use App\Models\Customer;

    class T
    {
        public function __invoke()
        {
    @@BODY@@
        }
    }
    PHP;

    private ?TempApp $app = null;

    protected ?Engine $engine = null;

    /**
     * @param string $body the statements of the action
     * @param array<string, string> $views view file (under resources/views, with .blade.php) => Blade source
     * @param array<string, string> $more other app files (path => code), e.g. config/view.php
     */
    protected function run(string $body, array $views, array $more = [], ?string $viewPath = null): Summary
    {
        $files = ['app/Http/Controllers/T.php' => str_replace('@@BODY@@', $body, self::CONTROLLER)];
        foreach ($views as $name => $source) {
            $files[self::VIEW . $name] = $source;
        }
        $this->app = new TempApp($files + $more);

        $registry = new HandlerRegistry();
        $registry->register(new ViewHandler($this->app->root, $viewPath), 5);
        $registry->register(new StubQueryHandler(), 10);
        $registry->register(new StubKnownHandler(), 30);
        CollectionHandlers::register($registry, 40);
        $this->engine = new Engine(
            new AppSourceIndex($this->app->root),
            new FakeModelCatalog([
                'App\Models\Order' => ['table' => 'orders', 'relations' => ['customer' => ['App\Models\Customer', false], 'items' => ['App\Models\Item', true]]],
                'App\Models\Customer' => ['table' => 'customers'],
                'App\Models\Item' => ['table' => 'items', 'relations' => ['product' => ['App\Models\Product', false]]],
                'App\Models\Product' => ['table' => 'products'],
            ]),
            new FakeBindingTable([]),
            $registry,
            new EngineOptions(auditUnknown: true),
        );

        return $this->engine->analyzeAction('App\Http\Controllers\T', '__invoke');
    }

    /** @return list<CostEvent> */
    protected function events(Summary $s, ?EventKind $kind = null): array
    {
        return array_values(array_filter($s->events, static fn (CostEvent $e): bool => $kind === null || $e->kind === $kind));
    }

    /** @return list<string> "file:line" of the events, in discovery order */
    protected function eventSites(Summary $s, ?EventKind $kind = null): array
    {
        return array_map(static fn (CostEvent $e): string => $e->site->file . ':' . $e->site->line, $this->events($s, $kind));
    }

    /** @return list<GapCode> */
    protected function gapCodes(Summary $s): array
    {
        return array_map(static fn (Gap $g): GapCode => $g->code, $s->gaps);
    }

    /** @return list<string> "CODE@file:line" of the gaps */
    protected function gapSites(Summary $s): array
    {
        return array_map(static fn (Gap $g): string => $g->code->value . '@' . $g->site->file . ':' . $g->site->line, $s->gaps);
    }

    /** @return array{query: int, memory: int} */
    protected function degrees(Summary $s): array
    {
        return CandidateComparison::degrees($s);
    }

    /** Invariant I1 over the whole walk: no UnknownT was made without a gap. */
    protected function assertNoUnknownWithoutGap(): void
    {
        self::assertNotNull($this->engine);
        self::assertSame([], $this->engine->violations());
    }
}
