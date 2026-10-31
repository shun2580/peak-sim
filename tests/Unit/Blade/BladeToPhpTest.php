<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Blade;

use PeakSim\Analysis\Blade\BladeToPhp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One row per line of the conversion table in the design doc (section 1.11, set S1).
 * Each row is a template and the PHP it must become; both sides are parsed and pretty-printed
 * before comparing, so spacing and brace style do not matter but the meaning does.
 */
final class BladeToPhpTest extends TestCase
{
    use BladeAssertions;

    /** @return iterable<string, array{string, string}> */
    public static function conversionTable(): iterable
    {
        // echo
        yield 'echo' => ['{{ $a }}', 'echo $a;'];
        yield 'echo without spaces' => ['{{$a}}', 'echo $a;'];
        yield 'raw echo' => ['{!! $html !!}', 'echo $html;'];
        yield 'echo with property chain' => ['{{ $order->customer->name }}', 'echo $order->customer->name;'];
        yield 'echo with array access' => ["{{ \$a['k'] }}", "echo \$a['k'];"];
        yield 'echo with ternary' => ["{{ \$a ? 'x' : 'y' }}", "echo \$a ? 'x' : 'y';"];
        yield 'echo with null coalesce' => ["{{ \$a ?? 'none' }}", "echo \$a ?? 'none';"];
        yield 'echo with call' => ['{{ number_format($o->total, 2) }}', 'echo number_format($o->total, 2);'];
        yield 'echo with closing braces in a string' => ["{{ '}}' }}", "echo '}}';"];
        yield 'echo with double quoted braces' => ['{{ "a}}b" }}', 'echo "a}}b";'];
        yield 'echo with nested braces' => ['{{ [1, 2][0] }}', 'echo [1, 2][0];'];
        yield 'two echoes on one line' => ['{{ $a }} {{ $b }}', 'echo $a; echo $b;'];
        yield 'empty echo emits nothing' => ['{{ }}', ''];
        yield 'blade comment is removed' => ['{{-- {{ $a }} @if($x) --}}', ''];
        yield 'text only' => ['<p>hello</p>', ''];

        // @php
        yield 'php block' => ['@php $a = 1; @endphp', '$a = 1;'];
        yield 'php block without semicolon' => ['@php $a = 1 @endphp', '$a = 1;'];
        yield 'php block with several statements' => ["@php\n\$a = 1;\n\$b = \$a + 1;\n@endphp", '$a = 1; $b = $a + 1;'];
        yield 'php inline' => ['@php($a = 1)', '$a = 1;'];
        yield 'php inline with call' => ['@php($x = f(1, 2))', '$x = f(1, 2);'];
        yield 'php block holding a foreach' => ['@php foreach ($a as $b) { echo $b; } @endphp', 'foreach ($a as $b) { echo $b; }'];

        // @if family
        yield 'if' => ['@if($a) x @endif', 'if ($a) {}'];
        yield 'if with space before paren' => ['@if ($a) x @endif', 'if ($a) {}'];
        yield 'if else' => ['@if($a) x @else y @endif', 'if ($a) {} else {}'];
        yield 'if elseif else' => ['@if($a) x @elseif($b) y @else z @endif', 'if ($a) {} elseif ($b) {} else {}'];
        yield 'if with body' => ['@if($a) {{ $b }} @endif', 'if ($a) { echo $b; }'];
        yield 'if with nested call parens' => ['@if(f(g($a)) && h()) x @endif', 'if (f(g($a)) && h()) {}'];
        yield 'if with paren inside string' => ["@if(\$a == ')') x @endif", "if (\$a == ')') {}"];
        yield 'if with open paren inside string' => ["@if(\$a == '(') x @endif", "if (\$a == '(') {}"];
        yield 'if with escaped quote in string' => ["@if(\$a == 'it\\'s )') x @endif", "if (\$a == 'it\\'s )') {}"];
        yield 'if with double quoted string' => ['@if($a == ")") x @endif', 'if ($a == ")") {}'];
        yield 'nested ifs' => ['@if($a) @if($b) x @endif @endif', 'if ($a) { if ($b) {} }'];
        yield 'if with method call on relation' => ['@if($order->items->count() > 0) x @endif', 'if ($order->items->count() > 0) {}'];
        yield 'unless' => ['@unless($a) x @endunless', 'if (!($a)) {}'];
        yield 'isset' => ['@isset($a) x @endisset', 'if (isset($a)) {}'];
        yield 'empty with argument' => ['@empty($a) x @endempty', 'if (empty($a)) {}'];

        // loops
        yield 'foreach' => ['@foreach($orders as $order) {{ $order->id }} @endforeach', 'foreach ($orders as $order) { echo $order->id; }'];
        yield 'foreach with space' => ['@foreach ($orders as $order) @endforeach', 'foreach ($orders as $order) {}'];
        yield 'foreach with key' => ['@foreach($a as $k => $v) @endforeach', 'foreach ($a as $k => $v) {}'];
        yield 'foreach over a call' => ['@foreach($order->items()->get() as $i) @endforeach', 'foreach ($order->items()->get() as $i) {}'];
        yield 'nested foreach' => ['@foreach($a as $b) @foreach($b->c as $d) @endforeach @endforeach', 'foreach ($a as $b) { foreach ($b->c as $d) {} }'];
        yield 'for' => ['@for($i = 0; $i < 10; $i++) x @endfor', 'for ($i = 0; $i < 10; $i++) {}'];
        yield 'while' => ['@while($a) x @endwhile', 'while ($a) {}'];
        yield 'continue' => ['@foreach($a as $b) @continue @endforeach', 'foreach ($a as $b) { continue; }'];
        yield 'break' => ['@foreach($a as $b) @break @endforeach', 'foreach ($a as $b) { break; }'];
        yield 'conditional continue' => ['@foreach($a as $b) @continue($b->skip) @endforeach', 'foreach ($a as $b) { if ($b->skip) { continue; } }'];
        yield 'conditional break' => ['@foreach($a as $b) @break($b->done) @endforeach', 'foreach ($a as $b) { if ($b->done) { break; } }'];

        // @switch
        yield 'switch' => [
            "@switch(\$a)\n@case(1)\nx\n@break\n@case(2)\ny\n@break\n@default\nz\n@endswitch",
            'switch ($a) { case 1: break; case 2: break; default: }',
        ];
        yield 'switch with echo in a case' => [
            "@switch(\$a)\n@case('x')\n{{ \$b }}\n@break\n@endswitch",
            "switch (\$a) { case 'x': echo \$b; break; }",
        ];

        // includes, layouts
        yield 'include with data' => ["@include('partials.row', ['order' => \$order])", "__peak_include('partials.row', ['order' => \$order]);"];
        yield 'include without data' => ["@include('partials.row')", "__peak_include('partials.row', []);"];
        yield 'include with double quoted name' => ['@include("partials.row")', '__peak_include("partials.row", []);'];
        yield 'include with commas inside the data' => ["@include('a', ['x' => f(1, 2), 'y' => [3, 4]])", "__peak_include('a', ['x' => f(1, 2), 'y' => [3, 4]]);"];
        yield 'extends' => ["@extends('layouts.app')", "__peak_extends('layouts.app');"];
        yield 'section block keeps its body' => ["@section('content') {{ \$a }} @endsection", 'echo $a;'];
        yield 'section with value' => ["@section('title', 'Orders')", ''];
        yield 'section with show' => ["@section('sidebar') {{ \$a }} @show", 'echo $a;'];
        yield 'yield' => ["@yield('content')", ''];
        yield 'parent' => ['@section("x") @parent {{ $a }} @endsection', 'echo $a;'];

        // safe directives
        yield 'csrf' => ['@csrf', ''];
        yield 'method' => ["@method('PUT')", ''];
        yield 'vite' => ["@vite(['resources/css/app.css', 'resources/js/app.js'])", ''];
        yield 'json' => ['@json($a)', 'echo json_encode($a);'];
        yield 'json with flags' => ['@json($a, JSON_PRETTY_PRINT)', 'echo json_encode($a, JSON_PRETTY_PRINT);'];
        yield 'lang with static key' => ["@lang('messages.hi')", ''];
        yield 'push block keeps its body' => ["@push('scripts') {{ \$a }} @endpush", 'echo $a;'];
        yield 'stack' => ["@stack('scripts')", ''];
        yield 'once block keeps its body' => ['@once {{ $a }} @endonce', 'echo $a;'];
        yield 'verbatim keeps braces as text' => ['@verbatim {{ item.name }} @if(x) @endverbatim', ''];

        // escapes
        yield 'escaped echo is text' => ['@{{ $a }}', ''];
        yield 'escaped directive is text' => ['@@if($a)', ''];
        yield 'email is not a directive' => ['contact: a@b.com', ''];
        yield 'email in a link' => ['<a href="mailto:me@example.com">me@example.com</a>', ''];
        yield 'email after a digit' => ['x1@example.com', ''];
        yield 'email after an underscore' => ['a_b@example.com', ''];
        yield 'email inside an echo string' => ["{{ 'a@b.com' }}", "echo 'a@b.com';"];
        yield 'email next to a real directive' => ['@if($a) a@b.com @endif', 'if ($a) {}'];
        yield 'at sign alone' => ['price @ 10', ''];
        yield 'at sign at the end' => ['trailing @', ''];
        yield 'email after multibyte text' => ['連絡先@example.com', ''];
    }

    #[DataProvider('conversionTable')]
    public function testConversionTableRow(string $template, string $expectedPhp): void
    {
        $this->assertTranspilesTo($expectedPhp, $template);
    }

    public function testForelseRunsTheEmptyBranchOnlyWhenTheLoopNeverRan(): void
    {
        $view = self::transpile("@forelse(\$users as \$user)\n{{ \$user->name }}\n@empty\n{{ \$none }}\n@endforelse");

        $this->assertSame([], $view->gaps);
        $code = self::code($view->php);
        // a flag set true before the loop, cleared in the loop body, tested after it
        $this->assertMatchesRegularExpression('/\$(\w+) = true;\s*foreach \(\$users as \$user\) \{\s*\$\1 = false;\s*echo \$user->name;\s*\}\s*if \(\$\1\) \{\s*echo \$none;\s*\}/', $code);
    }

    public function testForelseWithoutAnEmptyBranchStillCloses(): void
    {
        $view = self::transpile('@forelse($a as $b) {{ $b }} @endforelse');

        $this->assertSame([], $view->gaps);
        $this->assertStringContainsString('foreach ($a as $b)', self::code($view->php));
    }

    public function testTwoForelseLoopsDoNotShareTheirFlag(): void
    {
        $view = self::transpile('@forelse($a as $b) @empty x @endforelse @forelse($c as $d) @empty y @endforelse');

        preg_match_all('/\$(\w+) = true;/', self::code($view->php), $m);
        $this->assertCount(2, $m[1]);
        $this->assertNotSame($m[1][0], $m[1][1]);
    }

    public function testEmptyWithArgumentsInsideForelseIsAnEmptyCheckNotTheEmptyBranch(): void
    {
        $view = self::transpile('@forelse($a as $b) @empty($b->x) y @endempty @endforelse');

        $this->assertSame([], $view->gaps);
        $this->assertStringContainsString('empty($b->x)', self::code($view->php));
    }

    public function testExpressionsInNoOpDirectivesAreStillWalked(): void
    {
        // a lazy load can hide in a directive argument, so a non-literal argument must survive
        $view = self::transpile("@lang('hi', ['name' => \$order->customer->name])");

        $this->assertSame([], $view->gaps);
        $this->assertStringContainsString('$order->customer->name', self::code($view->php));
    }

    public function testYieldDefaultExpressionIsWalked(): void
    {
        $view = self::transpile("@yield('title', \$order->customer->name)");

        $this->assertSame([], $view->gaps);
        $this->assertStringContainsString('$order->customer->name', self::code($view->php));
    }

    public function testOutputIsAPureFunctionOfItsInput(): void
    {
        $template = "@foreach(\$a as \$b)\n{{ \$b }}\n@endforeach\n@forelse(\$c as \$d) @empty x @endforelse";

        $first = BladeToPhp::transpile('a', $template);
        $second = BladeToPhp::transpile('a', $template);
        // an unrelated call in between must not change the result (no hidden counter)
        BladeToPhp::transpile('b', '@forelse($x as $y) @empty z @endforelse');
        $third = BladeToPhp::transpile('a', $template);

        $this->assertEquals($first, $second);
        $this->assertEquals($first, $third);
    }

    public function testOutputIsParsableWithoutAClosingTag(): void
    {
        $view = self::transpile('<p>hi</p> {{ $a }}');

        $this->assertStringStartsWith('<?php', $view->php);
        $this->assertStringNotContainsString('?>', $view->php);
    }
}
