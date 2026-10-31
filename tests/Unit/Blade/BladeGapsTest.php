<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Blade;

use PeakSim\Model\Axis;
use PeakSim\Model\GapCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What the transpiler cannot follow is recorded as a gap, never silently dropped (D21). */
final class BladeGapsTest extends TestCase
{
    use BladeAssertions;

    /** @return iterable<string, array{string, string}> template and the text the gap message must name */
    public static function unsupported(): iterable
    {
        yield 'custom directive' => ['@datetime($order->created_at)', '@datetime'];
        yield 'custom directive without arguments' => ['@myThing', '@myThing'];
        yield 'can' => ["@can('update', \$order) x @endcan", '@can'];
        yield 'cannot' => ["@cannot('update', \$order) x @endcannot", '@cannot'];
        yield 'canany' => ["@canany(['a', 'b']) x @endcanany", '@canany'];
        yield 'auth' => ['@auth x @endauth', '@auth'];
        yield 'guest' => ['@guest x @endguest', '@guest'];
        yield 'component directive' => ["@component('alert') x @endcomponent", '@component'];
        yield 'slot' => ["@slot('title') x @endslot", '@slot'];
        yield 'livewire directive' => ["@livewire('counter')", '@livewire'];
        yield 'include if' => ["@includeIf('partials.row')", '@includeIf'];
        yield 'include when' => ["@includeWhen(\$a, 'partials.row')", '@includeWhen'];
        yield 'each' => ["@each('partials.row', \$orders, 'order')", '@each'];
        yield 'error' => ["@error('name') x @enderror", '@error'];
        yield 'env' => ["@env('local') x @endenv", '@env'];
        yield 'class' => ["<p @class(['a' => \$b])>", '@class'];
        yield 'alpine shorthand is not told apart from a directive' => ['<button @click="open = true">', '@click'];
        yield 'component tag' => ['<x-alert type="error" />', '<x-alert'];
        yield 'component tag with dots' => ['<x-forms.input name="a" />', '<x-forms.input'];
        yield 'slot tag' => ['<x-slot:title>Hi</x-slot>', '<x-slot:title'];
        yield 'livewire tag' => ['<livewire:counter />', '<livewire:counter'];
    }

    #[DataProvider('unsupported')]
    public function testUnsupportedSyntaxIsAGapNamingTheSyntax(string $template, string $named): void
    {
        $view = self::transpile($template);

        $this->assertNotEmpty($view->gaps, 'must not be silent');
        foreach ($view->gaps as $gap) {
            $this->assertSame(GapCode::BLADE_UNSUPPORTED, $gap->code);
        }
        $this->assertStringContainsString($named, implode("\n", self::gapMessages($view)));
        self::parse($view->php);
    }

    public function testGapCarriesTheTemplateNameLineAndBothAxes(): void
    {
        $view = \PeakSim\Analysis\Blade\BladeToPhp::transpile('resources/views/a/b.blade.php', "<p>\n\n  @can('x')\n</p>");

        $this->assertCount(1, $view->gaps);
        $gap = $view->gaps[0];
        $this->assertSame('resources/views/a/b.blade.php', $gap->site->file);
        $this->assertSame(3, $gap->site->line);
        $this->assertSame([Axis::Query, Axis::Memory], $gap->affects);
        $this->assertSame(0, $gap->loopDepth);
        $this->assertSame([], $gap->scenario);
    }

    public function testEachUnsupportedOccurrenceIsItsOwnGap(): void
    {
        $view = self::transpile("@can('a')\n x\n@endcan\n@can('b')\n y\n@endcan");

        // two openers and two closers
        $this->assertCount(4, $view->gaps);
        $this->assertSame([1, 3, 4, 6], array_map(static fn ($g) => $g->site->line, $view->gaps));
    }

    public function testCodeInsideAnUnsupportedBlockIsStillTranspiled(): void
    {
        $view = self::transpile("@can('x')\n{{ \$order->customer->name }}\n@endcan");

        $this->assertStringContainsString('$order->customer->name', self::code($view->php));
        $this->assertNotEmpty($view->gaps);
    }

    public function testEmailAddressesAndEscapedAtSignsAreNotGaps(): void
    {
        $view = self::transpile("a@b.com @@if(\$x) @{{ \$y }} <a href=\"mailto:me@example.com\">x</a>");

        $this->assertSame([], $view->gaps);
    }

    public function testClosingTagOfAComponentIsNotASecondGap(): void
    {
        $view = self::transpile('<x-alert>hi</x-alert>');

        $this->assertCount(1, $view->gaps);
    }

    // ---- includes and layouts ----

    public function testStaticIncludeIsRecordedWithItsNameAndLine(): void
    {
        $view = self::transpile("<p>\n@include('partials.row', ['a' => 1])\n</p>");

        $this->assertCount(1, $view->includes);
        $inc = $view->includes[0];
        $this->assertSame('include', $inc->kind);
        $this->assertSame('partials.row', $inc->name);
        $this->assertSame(2, $inc->line);
        $this->assertFalse($inc->isDynamic());
        $this->assertSame([], $view->gaps);
    }

    public function testDoubleQuotedStaticIncludeIsStatic(): void
    {
        $view = self::transpile('@include("partials.row")');

        $this->assertSame('partials.row', $view->includes[0]->name);
        $this->assertFalse($view->includes[0]->isDynamic());
    }

    /** @return iterable<string, array{string}> */
    public static function dynamicIncludes(): iterable
    {
        yield 'variable' => ['@include($partial)'];
        yield 'variable with data' => ['@include($partial, ["a" => 1])'];
        yield 'concatenation' => ["@include('partials.' . \$kind)"];
        yield 'interpolated string' => ['@include("partials.$kind")'];
        yield 'call' => ['@include(config("x.view"))'];
    }

    #[DataProvider('dynamicIncludes')]
    public function testDynamicIncludeIsMarkedDynamicAndLeftToTheViewHandler(string $template): void
    {
        $view = self::transpile($template);

        // design doc 1.11: the transpiler marks it; ViewHandler (PBl-2) turns it into VIEW_NOT_FOUND
        $this->assertCount(1, $view->includes);
        $this->assertTrue($view->includes[0]->isDynamic());
        $this->assertNull($view->includes[0]->name);
        $this->assertSame([], $view->gaps);
        $this->assertStringContainsString('__peak_include(', $view->php);
        self::parse($view->php);
    }

    public function testExtendsIsRecordedAsAnInclude(): void
    {
        $view = self::transpile("@extends('layouts.app')\n@section('content') x @endsection");

        $this->assertCount(1, $view->includes);
        $this->assertSame('extends', $view->includes[0]->kind);
        $this->assertSame('layouts.app', $view->includes[0]->name);
        $this->assertSame(1, $view->includes[0]->line);
    }

    public function testIncludesAreListedInTemplateOrder(): void
    {
        $view = self::transpile("@extends('l')\n@include('a')\n@foreach(\$x as \$y)\n@include('b')\n@endforeach\n@include(\$c)");

        $this->assertSame(
            [['extends', 'l', 1], ['include', 'a', 2], ['include', 'b', 4], ['include', null, 6]],
            array_map(static fn ($i) => [$i->kind, $i->name, $i->line], $view->includes),
        );
    }

    // ---- malformed templates stay parsable and say so ----

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'orphan endif' => ['x @endif y'];
        yield 'orphan else' => ['@else x'];
        yield 'orphan elseif' => ['@elseif($a) x'];
        yield 'orphan endforeach' => ['@endforeach'];
        yield 'endforeach closing an if' => ['@if($a) @endforeach'];
        yield 'endif closing a foreach' => ['@foreach($a as $b) @endif'];
        yield 'elseif after else' => ['@if($a) @else @elseif($b) @endif'];
        yield 'else twice' => ['@if($a) @else @else @endif'];
        yield 'unclosed if' => ['@if($a) x'];
        yield 'unclosed foreach' => ['@foreach($a as $b) {{ $b }}'];
        yield 'unclosed switch' => ['@switch($a) @case(1) x'];
        yield 'case outside switch' => ['@case(1) x'];
        yield 'empty outside forelse' => ['@empty x'];
        yield 'endphp alone' => ['x @endphp'];
        yield 'unterminated php block' => ['@php $a = 1;'];
        yield 'unterminated echo' => ['{{ $a'];
        yield 'unterminated raw echo' => ['{!! $a'];
        yield 'unterminated comment' => ['{{-- never closed'];
        yield 'unterminated verbatim' => ['@verbatim {{ x }}'];
        yield 'unbalanced parenthesis' => ['@if($a'];
        yield 'foreach without arguments' => ['@foreach x @endforeach'];
        yield 'if without arguments' => ['@if x @endif'];
        yield 'include without arguments' => ['@include'];
        yield 'unterminated string in arguments' => ["@if(\$a == 'x) y @endif"];
    }

    #[DataProvider('malformed')]
    public function testMalformedTemplateStillYieldsParsablePhpAndAGap(string $template): void
    {
        $view = self::transpile($template);

        self::parse($view->php); // must not throw
        $this->assertNotEmpty($view->gaps, 'a malformed template is never silently accepted');
        foreach ($view->gaps as $gap) {
            $this->assertSame(GapCode::BLADE_UNSUPPORTED, $gap->code);
            $this->assertGreaterThanOrEqual(1, $gap->site->line);
        }
    }

    public function testUnclosedBlockGapPointsAtTheOpeningLine(): void
    {
        $view = self::transpile("<p>\n\n@if(\$a)\n x\n");

        $this->assertCount(1, $view->gaps);
        $this->assertSame(3, $view->gaps[0]->site->line);
        $this->assertSame(substr_count("<p>\n\n@if(\$a)\n x\n", "\n"), substr_count($view->php, "\n"));
    }
}
