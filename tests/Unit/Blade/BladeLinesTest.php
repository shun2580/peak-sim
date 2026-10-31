<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Blade;

use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;

/**
 * The engine reports evidence as "template:line", so a statement made from template line N
 * must sit on line N of the generated PHP.
 */
final class BladeLinesTest extends TestCase
{
    use BladeAssertions;

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        yield 'html around echoes' => ["<html>\n<body>\n<p>{{ \$m3 }}</p>\n\n<p>{{ \$m5 }}</p>\n</body>\n</html>"];
        yield 'loop with body' => ["<ul>\n@foreach (\$a as \$b)\n<li>{{ \$m3 }}</li>\n@endforeach\n{{ \$m5 }}\n</ul>"];
        yield 'multi-line comment' => ["{{--\n a comment\n over four lines\n--}}\n{{ \$m5 }}"];
        yield 'multi-line echo' => ["{{\n  \$m2\n}}\n{{ \$m4 }}"];
        yield 'multi-line directive arguments' => ["@if(\n  \$a\n  && \$b\n)\n{{ \$m5 }}\n@endif"];
        yield 'multi-line include data' => ["@include('x', [\n  'a' => \$m2,\n  'b' => \$m3,\n])\n{{ \$m5 }}"];
        yield 'php block' => ["@php\n\$x = 1;\n\$m3 = \$x;\n@endphp\n{{ \$m5 }}"];
        yield 'forelse' => ["@forelse(\$a as \$b)\n{{ \$m2 }}\n@empty\n{{ \$m4 }}\n@endforelse\n{{ \$m6 }}"];
        yield 'switch' => ["@switch(\$a)\n@case(1)\n{{ \$m3 }}\n@break\n@default\n{{ \$m6 }}\n@endswitch\n{{ \$m8 }}"];
        yield 'extends and sections' => ["@extends('layout')\n\n@section('content')\n{{ \$m4 }}\n@endsection\n{{ \$m6 }}"];
        yield 'comment between code' => ["{{ \$m1 }}\n{{-- x\n y --}}\n{{ \$m4 }}"];
        yield 'verbatim block' => ["@verbatim\n{{ nope }}\n@endverbatim\n{{ \$m4 }}"];
        yield 'escaped echo over lines' => ["@{{\n \$a \n}}\n{{ \$m4 }}"];
        yield 'unsupported directive' => ["@can('x')\n{{ \$m2 }}\n@endcan\n{{ \$m4 }}"];
        yield 'component tag' => ["<x-alert\n  type=\"e\"\n/>\n{{ \$m4 }}"];
        yield 'blank leading lines' => ["\n\n\n{{ \$m4 }}"];
        yield 'trailing newline' => ["{{ \$m1 }}\n"];
    }

    /** Every `$mN` in the template must be on line N of the PHP, and the line count must match. */
    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function testEveryStatementKeepsItsTemplateLine(string $template): void
    {
        $view = self::transpile($template);
        self::parse($view->php);

        $this->assertSame(
            substr_count($template, "\n"),
            substr_count($view->php, "\n"),
            'line count must not change',
        );

        $phpLines = explode("\n", $view->php);
        preg_match_all('/\$m(\d+)/', $template, $m);
        $this->assertNotEmpty($m[1], 'the template must contain markers');
        foreach ($m[1] as $n) {
            $this->assertStringContainsString("\$m{$n}", $phpLines[(int) $n - 1] ?? '', "marker \$m{$n} moved off line {$n}");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function templatesForCrlf(): iterable
    {
        yield from self::templates();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templatesForCrlf')]
    public function testCrlfTemplatesKeepTheSameLines(string $template): void
    {
        $crlf = str_replace("\n", "\r\n", $template);
        $view = self::transpile($crlf);
        self::parse($view->php);

        $phpLines = explode("\n", $view->php);
        $this->assertSame(substr_count($template, "\n"), count($phpLines) - 1);
        preg_match_all('/\$m(\d+)/', $template, $m);
        foreach ($m[1] as $n) {
            $this->assertStringContainsString("\$m{$n}", $phpLines[(int) $n - 1], "marker \$m{$n} moved off line {$n} (CRLF)");
        }
    }

    public function testCrlfAndLfGiveTheSameGapsWithTheSameLines(): void
    {
        $lf = "@can('a')\n x\n@endcan\n@foo\n";
        $a = self::transpile($lf);
        $b = self::transpile(str_replace("\n", "\r\n", $lf));

        $this->assertSame(
            array_map(static fn ($g) => [$g->message, $g->site->line], $a->gaps),
            array_map(static fn ($g) => [$g->message, $g->site->line], $b->gaps),
        );
    }

    public function testCrlfInsideAnExpressionDoesNotBreakTheParse(): void
    {
        $view = self::transpile("@if(\r\n \$a\r\n)\r\n x\r\n@endif\r\n{{ \$m6 }}");

        $this->assertSame([], $view->gaps);
        $this->assertStringContainsString('$m6', explode("\n", $view->php)[5]);
    }

    public function testNodeLinesInTheParsedTreeMatchTheTemplate(): void
    {
        $template = "<div>\n  @foreach (\$orders as \$order)\n    <p>{{ \$order->customer->name }}</p>\n  @endforeach\n</div>";
        $view = self::transpile($template);

        $fetches = (new NodeFinder())->findInstanceOf(self::parse($view->php), PropertyFetch::class);
        $lines = array_unique(array_map(static fn (PropertyFetch $f) => $f->getStartLine(), $fetches));
        $this->assertSame([3], array_values($lines));
    }

    public function testEmptyTemplate(): void
    {
        $view = self::transpile('');

        $this->assertSame([], self::parse($view->php));
        $this->assertSame([], $view->gaps);
        $this->assertSame([], $view->includes);
        $this->assertSame(0, substr_count($view->php, "\n"));
    }

    public function testWhitespaceOnlyTemplate(): void
    {
        $view = self::transpile("  \n\t\n\n");

        $this->assertSame([], self::parse($view->php));
        $this->assertSame([], $view->gaps);
        $this->assertSame(3, substr_count($view->php, "\n"));
    }

    public function testHtmlOnlyTemplateHasNoStatements(): void
    {
        $view = self::transpile("<!DOCTYPE html>\n<html><body><p>hi</p></body></html>\n");

        $this->assertSame([], self::parse($view->php));
        $this->assertSame([], $view->gaps);
    }

    public function testFixtureHiddenBladeKeepsTheCustomerLookupOnItsTemplateLine(): void
    {
        $path = dirname(__DIR__, 3) . '/fixtures/laravel-app/resources/views/hidden/blade.blade.php';
        $source = (string) file_get_contents($path);
        $this->assertNotSame('', $source, 'the fixture view must exist');

        $expectedLine = 0;
        foreach (explode("\n", $source) as $i => $line) {
            if (str_contains($line, 'customer->name')) {
                $expectedLine = $i + 1;
            }
        }
        $this->assertGreaterThan(0, $expectedLine);

        $view = self::transpile($source);
        $this->assertSame([], $view->gaps);
        $nameFetches = array_filter(
            (new NodeFinder())->findInstanceOf(self::parse($view->php), PropertyFetch::class),
            static fn (PropertyFetch $f) => $f->name instanceof \PhpParser\Node\Identifier && $f->name->name === 'name',
        );
        $this->assertCount(1, $nameFetches);
        $this->assertSame($expectedLine, reset($nameFetches)->getStartLine());
    }
}
