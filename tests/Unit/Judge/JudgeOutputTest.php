<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Judge;

use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Axis;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\RouteResult;

/** What the rest of RouteResult carries, and row (j): the same facts in any order give the same result. */
final class JudgeOutputTest extends JudgeCase
{
    public function testTheRouteIsDescribedFromTheEntry(): void
    {
        $r = self::judge(self::summary(), self::route('/ng/n-plus-one', 'App\Ng\NPlusOneController@index', ['HEAD', 'GET']));

        self::assertSame('/ng/n-plus-one', $r->uri);
        self::assertSame(['GET', 'HEAD'], $r->methods);
        self::assertSame('App\Ng\NPlusOneController@index', $r->action);
        self::assertSame(RouteResult::KIND_CONTROLLER, $r->kind);
    }

    public function testClosureAndFrameworkRoutesAreToldApart(): void
    {
        self::assertSame(RouteResult::KIND_CLOSURE, self::judge(self::summary(), self::route('/up', 'Closure'))->kind);
        self::assertSame(RouteResult::KIND_FRAMEWORK, self::judge(self::summary(), self::route('/storage/{path}', 'Illuminate\Filesystem\ServeFile'))->kind);
        self::assertSame(RouteResult::KIND_CONTROLLER, self::judge(self::summary(), self::route('/a', 'App\Http\Controllers\InvokableController'))->kind);
    }

    public function testCoefficientsAreAlwaysThreeKeysAndNullWhenNotDerivable(): void
    {
        $r = self::judge(self::summary([$this->event()]));

        self::assertSame(['query_coefficient' => null, 'per_item_queries' => null, 'extra_queries_per_request' => null], $r->coefficients);
    }

    public function testAChunkGivesOneOverTheChunkSize(): void
    {
        $chunk = $this->event(['query' => self::query(true, 'orders', 1, false, 100)]);
        $inner = $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('orders', null, true)], 'table' => 'customers']);

        self::assertSame('1/100', self::judge(self::summary([$chunk]))->coefficients['query_coefficient']);
        self::assertSame('1/100', self::judge(self::summary([$chunk, $inner]))->coefficients['query_coefficient']);
    }

    public function testAnUnboundedQueryWithoutAKnownChunkSizeHasNoCoefficient(): void
    {
        $unknown = $this->event(['query' => self::query(true, 'orders')]);

        self::assertNull(self::judge(self::summary([$unknown]))->coefficients['query_coefficient']);
    }

    public function testAConditionalQueryInALoopGivesItsStatementsPerItem(): void
    {
        $upsert = $this->event(['kind' => EventKind::Write, 'frames' => [self::frame('products')], 'table' => 'products', 'query' => self::query(false, null, 2, true)]);
        $plain = $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('products')], 'table' => 'x']);

        self::assertSame(2, self::judge(self::summary([$upsert]))->coefficients['per_item_queries']);
        self::assertNull(self::judge(self::summary([$plain]))->coefficients['per_item_queries']);
    }

    public function testConstantStatementsBeyondTheFirstAreTheExtraQueriesPerRequest(): void
    {
        // /hidden/model-with: one select and one more query for the relation in $with
        $select = $this->event(['table' => 'orders', 'memory' => self::memory(1, 'orders')]);
        $with = $this->event(['kind' => EventKind::EagerLoad, 'table' => 'customers']);

        self::assertSame(1, self::judge(self::summary([$select, $with]))->coefficients['extra_queries_per_request']);
        self::assertNull(self::judge(self::summary([$select]))->coefficients['extra_queries_per_request']);
    }

    public function testQueriesInsideALoopAreNotExtraPerRequest(): void
    {
        $select = $this->event();
        $inner = $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('orders')], 'table' => 'customers']);

        self::assertNull(self::judge(self::summary([$select, $inner]))->coefficients['extra_queries_per_request']);
    }

    public function testAWorstCaseAssumptionBecomesAWorstCaseEntry(): void
    {
        $worst = self::assumption(
            [Axis::Query],
            AssumptionCode::WORST_CASE_OF_CANDIDATES,
            [],
            60,
            ['App\Eager', 'App\Lazy', 'chosen' => 'App\Lazy'],
            'App\Contracts\OrderSummaries has 2 implementations that cost differently; the costliest is adopted',
        );

        $r = self::judge(self::summary([], [], [$worst]));

        self::assertNotNull($r->worstCase);
        self::assertCount(1, $r->worstCase);
        self::assertSame('app/Http/Controllers/OrderController.php:60', $r->worstCase[0]['site']->fingerprint());
        self::assertSame('App\Contracts\OrderSummaries', $r->worstCase[0]['abstract']);
        self::assertSame(['App\Eager', 'App\Lazy'], $r->worstCase[0]['candidates']);
        self::assertSame('App\Lazy', $r->worstCase[0]['chosen']);
        self::assertSame('medium', $r->query->confidence?->value);
    }

    public function testAnExplicitAbstractInTheAssumptionIsPreferredOverTheMessage(): void
    {
        $worst = self::assumption([Axis::Query], AssumptionCode::WORST_CASE_OF_CANDIDATES, [], 60, ['A', 'B', 'chosen' => 'B', 'abstract' => 'App\Contracts\Thing'], 'whatever');

        self::assertSame('App\Contracts\Thing', self::judge(self::summary([], [], [$worst]))->worstCase[0]['abstract']);
        self::assertSame(['A', 'B'], self::judge(self::summary([], [], [$worst]))->worstCase[0]['candidates']);
    }

    public function testOtherAssumptionsAreNotWorstCases(): void
    {
        self::assertNull(self::judge(self::summary([], [], [self::assumption([Axis::Query])]))->worstCase);
    }

    public function testGapsAndAssumptionsAreKeptOnceAndInAFixedOrder(): void
    {
        $late = self::gap(null, 90);
        $early = self::gap(null, 12, [], 0, GapCode::NO_BINDING);
        $a1 = self::assumption([Axis::Memory], AssumptionCode::RELATION_INFERRED, [], 80);
        $a2 = self::assumption([Axis::Memory], AssumptionCode::DRIVER_ASSUMED, [], 20);

        $r = self::judge(self::summary([], [$late, $early, $late], [$a1, $a2, $a1]));

        self::assertSame([$early, $late], $r->gaps);
        self::assertSame([$a2, $a1], $r->assumptions);
    }

    public function testEvidenceIsCallsThenBindingsThenEventsThenGapsThenAssumptionsWithoutDuplicates(): void
    {
        $call1 = self::site(5, 'app/Services/A.php');
        $call2 = self::site(6, 'app/Services/B.php');
        $event = $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('orders')], 'table' => 'customers', 'line' => 44]);
        $worst = self::assumption([Axis::Query], AssumptionCode::WORST_CASE_OF_CANDIDATES, [], 60, ['A', 'B', 'chosen' => 'B'], 'X has 2 implementations');
        $gap = self::gap([Axis::Memory], 31);
        $plain = self::assumption([Axis::Memory], AssumptionCode::RELATION_INFERRED, [], 70);

        $r = self::judge(self::summary([$event], [$gap], [$plain, $worst], [$call1, $call2, $call1]));

        $roles = array_map(static fn (array $e): string => $e['role'] . '@' . $e['site']->fingerprint(), $r->evidence);
        self::assertSame([
            'call@app/Services/A.php:5',
            'call@app/Services/B.php:6',
            'binding@app/Http/Controllers/OrderController.php:60',
            'event@app/Http/Controllers/OrderController.php:44',
            'gap@app/Http/Controllers/OrderController.php:31',
            'assumption@app/Http/Controllers/OrderController.php:70',
        ], $roles);
        self::assertSame('query', $r->evidence[3]['axis']);
        self::assertSame('memory', $r->evidence[4]['axis']);
        self::assertIsString($r->evidence[3]['note']);
    }

    public function testAnEventThatCostsNothingIsNoEvidence(): void
    {
        self::assertSame([], self::judge(self::summary([$this->event()]))->evidence);
    }

    public function testRowJTheSameFactsInAnyOrderGiveTheSameResult(): void
    {
        $events = [
            $this->event(['kind' => EventKind::Select, 'table' => 'orders', 'memory' => self::memory(1, 'orders')]),
            $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('orders')], 'table' => 'customers']),
            $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('orders'), self::frame('items', 'orders')], 'table' => 'products']),
            $this->event(['kind' => EventKind::Select, 'table' => 'customers', 'memory' => self::memory(1, 'customers')]),
            $this->event(['kind' => EventKind::CacheRestore, 'scenario' => [self::hit()], 'query' => null, 'memory' => self::memory(1, 'orders', MemoryCause::CacheRestore)]),
        ];
        $gaps = [self::gap([Axis::Memory], 31), self::gap([Axis::Query], 12, [self::hit()]), self::gap(null, 77)];
        $assumptions = [
            self::assumption([Axis::Memory], AssumptionCode::RELATION_INFERRED, [], 80),
            self::assumption([Axis::Query], AssumptionCode::WORST_CASE_OF_CANDIDATES, [], 60, ['A', 'B', 'chosen' => 'B'], 'X has 2 implementations'),
        ];
        $trace = [self::site(5, 'app/A.php'), self::site(6, 'app/B.php')];

        $baseline = self::judge(self::summary($events, $gaps, $assumptions, $trace));

        self::assertEquals($baseline, self::judge(self::summary(array_reverse($events), array_reverse($gaps), array_reverse($assumptions), $trace)));
        self::assertEquals($baseline, self::judge(self::summary([...array_slice($events, 2), ...array_slice($events, 0, 2)], [$gaps[1], $gaps[2], $gaps[0]], [$assumptions[1], $assumptions[0]], $trace)));
    }

    public function testNothingAbsoluteOrTimeDependentIsInTheResult(): void
    {
        $event = $this->event(['frames' => [self::frame('orders')]]);

        $dump = print_r(self::judge(self::summary([$event], [self::gap()], [self::assumption([Axis::Query])])), true);

        self::assertSame(0, preg_match('~(?<![\w.])/(?:home|Users|mnt)/~', $dump));
        self::assertSame(0, preg_match('~\b20\d\d-\d\d-\d\d~', $dump));
    }
}
