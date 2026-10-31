<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Blade;

use PeakSim\Analysis\Blade\ViewHandler;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;

/** What the view handler does at the edges: where views are looked for, which calls it reads, what it refuses. */
final class ViewHandlerEdgesTest extends ViewHandlerCase
{
    /** The action body starts on this line of the generated controller. */
    private const BODY_LINE = 12;

    private const LOOP_ROW = "@foreach (\$orders as \$order)\n    <p>{{ \$order->customer->name }}</p>\n@endforeach";

    // ----- which calls build a view -------------------------------------------------------------------

    public function testResponseViewBuildsAView(): void
    {
        $s = $this->walk("return response()->view('t', ['orders' => Order::get()], 200);", ['t.blade.php' => self::LOOP_ROW]);

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testSeveralWithCallsRenderTheViewOnceWithAllTheData(): void
    {
        $blade = "@foreach (\$orders as \$order)\n    {{ \$order->customer->name }} {{ \$title }}\n@endforeach";
        $s = $this->walk("return view('t')->with('title', 'x')->with('orders', Order::get());", ['t.blade.php' => $blade]);

        self::assertCount(1, $this->events($s, EventKind::LazyLoad), 'rendered once, not once per with()');
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testWithTakesAnArrayAndCompact(): void
    {
        $blade = "@foreach (\$orders as \$order)\n    {{ \$order->customer->name }}{{ \$title }}\n@endforeach";
        $body = "\$orders = Order::get();\nreturn view('t', ['title' => 'a'])->with(compact('orders'));";
        $s = $this->walk($body, ['t.blade.php' => $blade]);

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);

        $s = $this->walk("return view('t')->with(['orders' => Order::get(), 'title' => 'b']);", ['t.blade.php' => $blade]);
        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testDataHeldInAVariableIsRead(): void
    {
        $body = "\$data = ['orders' => Order::get()];\nreturn view('t', \$data);";
        $s = $this->walk($body, ['t.blade.php' => self::LOOP_ROW]);

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testMergeDataIsPassedToo(): void
    {
        $s = $this->walk("return view('t', [], ['orders' => Order::get()]);", ['t.blade.php' => self::LOOP_ROW]);

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
    }

    public function testAViewMadeInsideALoopIsWalkedUnderThatLoop(): void
    {
        $body = "foreach (Order::get() as \$o) {\n    view('t', ['orders' => Order::get()]);\n}";
        $s = $this->walk($body, ['t.blade.php' => self::LOOP_ROW]);

        $reads = $this->events($s, EventKind::LazyLoad);
        self::assertCount(1, $reads);
        self::assertCount(2, $reads[0]->frames, 'the loop around view(), then the loop in the template');
        self::assertSame(['query' => 2, 'memory' => 2], $this->degrees($s));
    }

    public function testWithOnAViewThatWasAlreadyBuiltIsAGap(): void
    {
        $body = "\$view = view('t', ['orders' => Order::get()]);\n\$view->with('title', 'x');\nreturn \$view;";
        $s = $this->walk($body, ['t.blade.php' => self::LOOP_ROW]);

        self::assertContains(GapCode::UNRESOLVED_CALL, $this->gapCodes($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testWithOnAModelQueryIsNotTheViewHandlersBusiness(): void
    {
        $s = $this->walk("return Order::with('customer')->get();", []);

        self::assertSame([], $s->gaps);
        self::assertCount(1, $this->events($s, EventKind::EagerLoad));
    }

    // ----- where views are looked for -------------------------------------------------------------------

    public function testADottedNameIsADirectoryPath(): void
    {
        $views = ['admin/orders/index.blade.php' => self::LOOP_ROW];
        $s = $this->walk("return view('admin.orders.index', ['orders' => Order::get()]);", $views);

        self::assertSame([self::VIEW . 'admin/orders/index.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
    }

    public function testAMissingViewIsAGapAtTheCall(): void
    {
        $s = $this->walk("return view('nope', ['orders' => Order::get()]);", []);

        self::assertSame(['VIEW_NOT_FOUND@app/Http/Controllers/T.php:' . self::BODY_LINE], $this->gapSites($s));
        self::assertCount(1, $this->events($s, EventKind::Select), 'the data was still evaluated');
        $this->assertNoUnknownWithoutGap();
    }

    public function testANameThatIsNotConstantIsAGap(): void
    {
        $s = $this->walk("\$name = request('v');\nreturn view(\$name, ['orders' => Order::get()]);", ['t.blade.php' => self::LOOP_ROW]);

        self::assertContains(GapCode::VIEW_NOT_FOUND, $this->gapCodes($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testAPackageViewNameIsAGap(): void
    {
        $s = $this->walk("return view('mail::message', []);", []);

        self::assertSame([GapCode::VIEW_NOT_FOUND], $this->gapCodes($s));
    }

    public function testANameThatEscapesTheViewDirectoryIsAGap(): void
    {
        $s = $this->walk("return view('..secret', []);", ['t.blade.php' => 'x']);

        self::assertSame([GapCode::VIEW_NOT_FOUND], $this->gapCodes($s));
    }

    public function testConfigViewPathsAreRead(): void
    {
        $config = "<?php\n\nreturn [\n    'paths' => [\n        resource_path('themes/plain'),\n        resource_path('views'),\n    ],\n];\n";
        $files = ['config/view.php' => $config, 'resources/themes/plain/t.blade.php' => self::LOOP_ROW];
        $s = $this->walk("return view('t', ['orders' => Order::get()]);", [], $files);

        self::assertSame(['resources/themes/plain/t.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
    }

    public function testTheFirstConfiguredPathThatHasTheViewWins(): void
    {
        $config = "<?php\nreturn ['paths' => [resource_path('themes/plain'), resource_path('views')]];\n";
        $s = $this->walk(
            "return view('t', ['orders' => Order::get()]);",
            ['t.blade.php' => self::LOOP_ROW],
            ['config/view.php' => $config],
        );

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
    }

    public function testAnExplicitViewPathReplacesTheDefault(): void
    {
        $files = ['templates/t.blade.php' => self::LOOP_ROW];
        $s = $this->walk("return view('t', ['orders' => Order::get()]);", [], $files, 'templates');

        self::assertSame(['templates/t.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
    }

    // ----- includes and variables ------------------------------------------------------------------------

    public function testAnIncludeInsideAForeachSeesTheLoopVariableWithoutAnExplicitArray(): void
    {
        $views = [
            't.blade.php' => "@foreach (\$orders as \$order)\n    @include('partials.row')\n@endforeach",
            'partials/row.blade.php' => '<p>{{ $order->customer->name }}</p>',
        ];
        $s = $this->walk("return view('t', ['orders' => Order::get()]);", $views);

        self::assertSame([self::VIEW . 'partials/row.blade.php:1'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testAnIncludeSeesTheVariablesTheParentViewWasGiven(): void
    {
        $views = [
            't.blade.php' => "@include('partials.list')",
            'partials/list.blade.php' => "@foreach (\$orders as \$o)\n{{ \$o->customer->name }}\n@endforeach",
        ];
        $s = $this->walk("return view('t', ['orders' => Order::get()]);", $views);

        self::assertSame([self::VIEW . 'partials/list.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
    }

    public function testTheArrayOfAnIncludeIsEvaluatedOnceAndItsQueryIsAnEvent(): void
    {
        $views = [
            't.blade.php' => "@include('partials.list', ['rows' => \App\Models\Order::get()])",
            'partials/list.blade.php' => '{{ count($rows) }}',
        ];
        $s = $this->walk("return view('t');", $views);

        self::assertCount(1, $this->events($s, EventKind::Select));
        self::assertSame([], $s->gaps);
    }

    public function testATemplateThatIncludesItselfIsARecursionGap(): void
    {
        $views = ['t.blade.php' => "@foreach (\$orders as \$o)\n@include('t')\n@endforeach"];
        $s = $this->walk("return view('t', ['orders' => Order::get()]);", $views);

        self::assertContains(GapCode::RECURSION, $this->gapCodes($s));
        $this->assertNoUnknownWithoutGap();
    }

    public function testTwoTemplatesThatIncludeEachOtherAreARecursionGap(): void
    {
        $views = [
            'a.blade.php' => "@include('b')",
            'b.blade.php' => "@include('a')",
        ];
        $s = $this->walk("return view('a');", $views);

        self::assertContains(GapCode::RECURSION, $this->gapCodes($s));
    }

    public function testAVariableTheViewWasNeverGivenIsAGapNotSilence(): void
    {
        $s = $this->walk("return view('t', []);", ['t.blade.php' => self::LOOP_ROW]);

        self::assertNotSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testLoopIsABuiltInVariable(): void
    {
        $blade = "@foreach (\$orders as \$order)\n    {{ \$loop->index }} {{ \$loop->first }}\n@endforeach";
        $s = $this->walk("return view('t', ['orders' => Order::get()]);", ['t.blade.php' => $blade]);

        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testErrorsIsAViewErrorBagSoTheKnownCallsHandlerCanDecideWhatItMayDo(): void
    {
        $blade = "{{ \$errors->first('x') }}";
        $s = $this->walk("return view('t');", ['t.blade.php' => $blade]);

        self::assertSame(['VENDOR_CALL_NOT_MODELLED@' . self::VIEW . 't.blade.php:1'], $this->gapSites($s));
        self::assertStringContainsString(ViewHandler::ERRORS_CLASS, $s->gaps[0]->message);
        $this->assertNoUnknownWithoutGap();
    }

    public function testADataKeyBeatsABuiltInOfTheSameName(): void
    {
        $blade = "@foreach (\$errors as \$e)\n{{ \$e->customer->name }}\n@endforeach";
        $s = $this->walk("return view('t', ['errors' => Order::get()]);", ['t.blade.php' => $blade]);

        self::assertSame([self::VIEW . 't.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
    }

    // ----- gaps the transpiler reports ---------------------------------------------------------------------

    public function testAGapIsReportedOncePerTemplateEvenWhenTheViewIsMadeTwice(): void
    {
        $blade = "@can('x')\nhi\n@endcan";
        $body = "view('t', []);\nreturn view('t', []);";
        $s = $this->walk($body, ['t.blade.php' => $blade]);

        self::assertSame(
            ['BLADE_UNSUPPORTED@' . self::VIEW . 't.blade.php:1', 'BLADE_UNSUPPORTED@' . self::VIEW . 't.blade.php:3'],
            $this->gapSites($s),
            '@can and @endcan, each once',
        );
    }

    public function testATemplateWithOnlyTextHasNoEventsAndNoGaps(): void
    {
        $s = $this->walk("return view('t');", ['t.blade.php' => "<h1>Hello</h1>\n"]);

        self::assertSame([], $s->events);
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }
}
