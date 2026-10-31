<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Blade;

use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;

/** The fifteen blade-* cases of design section 6.3, one level below the mini-apps. */
final class ViewHandlerTest extends ViewHandlerCase
{
    private const LOOP_ROW = <<<'BLADE'
    @foreach ($orders as $order)
        <p>{{ $order->customer->name }}</p>
    @endforeach
    BLADE;

    private const RETURN_ORDERS = "return view('t', ['orders' => Order::get()]);";

    public function testForeachInATemplateIsAnNPlusOneAndTheEvidenceIsTheTemplateLine(): void
    {
        $s = $this->walk(self::RETURN_ORDERS, ['t.blade.php' => self::LOOP_ROW]);

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testTheDataIsEvaluatedOnceSoTheOrderQueryIsOneEvent(): void
    {
        $s = $this->walk(self::RETURN_ORDERS, ['t.blade.php' => self::LOOP_ROW]);

        self::assertCount(1, $this->events($s, EventKind::Select));
    }

    public function testForelseWalksTheLoopBodyAndTheEmptyBranchIsAPlainBlock(): void
    {
        $blade = "@forelse (\$orders as \$order)\n    <p>{{ \$order->customer->name }}</p>\n@empty\n    <p>No orders.</p>\n@endforelse";
        $s = $this->walk(self::RETURN_ORDERS, ['t.blade.php' => $blade]);

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testConditionalsAroundTheReadDoNotHideIt(): void
    {
        $blade = <<<'BLADE'
        @if ($orders->isNotEmpty())
            @foreach ($orders as $order)
                @unless ($order->total > 100)
                    <p>{{ $order->customer->name }}</p>
                @endunless
            @endforeach
        @endif
        BLADE;
        $s = $this->walk(self::RETURN_ORDERS, ['t.blade.php' => $blade]);

        self::assertSame([self::VIEW . 't.blade.php:4'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testPhpBlocksAreEvaluatedLikePhp(): void
    {
        $blade = <<<'BLADE'
        @php
            $total = 0;
        @endphp
        @foreach ($orders as $order)
            @php
                $total += $order->customer->id;
            @endphp
        @endforeach
        <p>{{ $total }}</p>
        BLADE;
        $s = $this->walk(self::RETURN_ORDERS, ['t.blade.php' => $blade]);

        self::assertSame([self::VIEW . 't.blade.php:6'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testAViewThatOnlyEchoesReadsNoRelation(): void
    {
        $s = $this->walk(self::RETURN_ORDERS, ['t.blade.php' => '<p>{{ count($orders) }} orders</p>']);

        self::assertSame([], $this->events($s, EventKind::LazyLoad));
        self::assertSame(['query' => 0, 'memory' => 1], $this->degrees($s));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testAStaticIncludeIsFollowedIntoThePartialWithTheLoopAround(): void
    {
        $views = [
            't.blade.php' => "@foreach (\$orders as \$order)\n    @include('partials.row', ['order' => \$order])\n@endforeach",
            'partials/row.blade.php' => '<p>{{ $order->customer->name }}</p>',
        ];
        $s = $this->walk(self::RETURN_ORDERS, $views);

        self::assertSame([self::VIEW . 'partials/row.blade.php:1'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testAnIncludeWhoseNameIsAVariableIsAGapAndTheRestIsStillWalked(): void
    {
        $views = [
            't.blade.php' => "@foreach (\$orders as \$order)\n    @include(\$partial, ['order' => \$order])\n@endforeach",
            'partials/row.blade.php' => '<p>{{ $order->customer->name }}</p>',
        ];
        $s = $this->walk("return view('t', ['orders' => Order::get(), 'partial' => 'partials.row']);", $views);

        self::assertSame([GapCode::VIEW_NOT_FOUND], $this->gapCodes($s));
        self::assertSame(['VIEW_NOT_FOUND@' . self::VIEW . 't.blade.php:2'], $this->gapSites($s));
        self::assertCount(1, $this->events($s, EventKind::Select), 'the order query before the gap is still an event');
        $this->assertNoUnknownWithoutGap();
    }

    public function testExtendsWalksTheLayoutAndTheChildsSectionWithTheSameVariables(): void
    {
        $views = [
            'layouts/app.blade.php' => "<html>\n<body>\n    @yield('content')\n</body>\n</html>",
            't.blade.php' => "@extends('layouts.app')\n\n@section('content')\n    @foreach (\$orders as \$order)\n        <p>{{ \$order->customer->name }}</p>\n    @endforeach\n@endsection",
        ];
        $s = $this->walk(self::RETURN_ORDERS, $views);

        self::assertSame([self::VIEW . 't.blade.php:5'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testALayoutThatReadsARelationIsWalkedWithTheChildsVariables(): void
    {
        $views = [
            'layouts/app.blade.php' => "@foreach (\$orders as \$o)\n    {{ \$o->customer->name }}\n@endforeach\n@yield('content')",
            't.blade.php' => "@extends('layouts.app')\n@section('content')\n    ok\n@endsection",
        ];
        $s = $this->walk(self::RETURN_ORDERS, $views);

        self::assertSame([self::VIEW . 'layouts/app.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
    }

    public function testAnUnsupportedDirectiveIsAGapAtItsTemplateLine(): void
    {
        $blade = "@foreach (\$orders as \$order)\n    @can('view', \$order)\n        <p>{{ \$order->id }}</p>\n    @endcan\n@endforeach";
        $s = $this->walk(self::RETURN_ORDERS, ['t.blade.php' => $blade]);

        self::assertContains(GapCode::BLADE_UNSUPPORTED, $this->gapCodes($s));
        self::assertContains('BLADE_UNSUPPORTED@' . self::VIEW . 't.blade.php:2', $this->gapSites($s));
        self::assertGreaterThanOrEqual(1, count($s->events), 'the walk goes on past the gap');
        $this->assertNoUnknownWithoutGap();
    }

    public function testABladeComponentIsAGap(): void
    {
        $blade = "@foreach (\$orders as \$order)\n    <x-order-row :order=\"\$order\" />\n@endforeach";
        $s = $this->walk(self::RETURN_ORDERS, ['t.blade.php' => $blade]);

        self::assertContains('BLADE_UNSUPPORTED@' . self::VIEW . 't.blade.php:2', $this->gapSites($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testCompactPassesVariablesByName(): void
    {
        $s = $this->walk("\$orders = Order::get();\n\nreturn view('t', compact('orders'));", ['t.blade.php' => self::LOOP_ROW]);

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testWithAfterViewPassesAVariable(): void
    {
        $s = $this->walk("return view('t')->with('orders', Order::get());", ['t.blade.php' => self::LOOP_ROW]);

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        self::assertCount(1, $this->events($s, EventKind::Select));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testViewMakeIsView(): void
    {
        $s = $this->walk("return \\Illuminate\\Support\\Facades\\View::make('t', ['orders' => Order::get()]);", ['t.blade.php' => self::LOOP_ROW]);

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame(['query' => 1, 'memory' => 1], $this->degrees($s));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testAnEmailAddressInTheTemplateIsNotADirective(): void
    {
        $blade = "@foreach (\$orders as \$order)\n    <a href=\"mailto:support@example.com\">{{ \$order->customer->name }}</a>\n@endforeach";
        $s = $this->walk(self::RETURN_ORDERS, ['t.blade.php' => $blade]);

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testNestedForeachOverAToManyRelationIsNestedAndTheTablesArePerOrder(): void
    {
        $blade = "@foreach (\$orders as \$order)\n    @foreach (\$order->items as \$item)\n        <p>{{ \$item->product->name }}</p>\n    @endforeach\n@endforeach";
        $s = $this->walk(self::RETURN_ORDERS, ['t.blade.php' => $blade]);

        self::assertSame(['query' => 2, 'memory' => 2], $this->degrees($s));
        self::assertContains(self::VIEW . 't.blade.php:3', $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
        $frames = array_values(array_filter($this->events($s, EventKind::LazyLoad), static fn ($e): bool => $e->site->line === 3));
        self::assertCount(2, $frames[0]->frames, 'orders, then the items of one order');
        self::assertSame('items', $frames[0]->frames[1]->origin->table);
        self::assertSame('orders', $frames[0]->frames[1]->origin->perTable);
        $this->assertNoUnknownWithoutGap();
    }
}
