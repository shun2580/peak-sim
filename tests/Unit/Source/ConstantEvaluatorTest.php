<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Source;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeFinder;

final class ConstantEvaluatorTest extends SourceCase
{
    private const CONTROLLER = 'App\Http\Controllers\ReportController';
    private const LIMITS = 'App\Support\Limits';
    private const CHILD = 'App\Support\ChildLimits';

    /**
     * Expressions written in ReportController::index(), keyed by the variable they are assigned to.
     *
     * @return array<string, array{known: bool, value: mixed}>
     */
    private function evaluateControllerExpressions(): array
    {
        $index = self::index('src-const-eval');
        $stmts = $index->parse('app/Http/Controllers/ReportController.php');
        $assigns = (new NodeFinder())->find($stmts, static fn (Node $n): bool => $n instanceof Assign && $n->var instanceof Variable && is_string($n->var->name));
        $results = [];
        foreach ($assigns as $assign) {
            $results[$assign->var->name] = SourceShape::constant($index->evaluate($assign->expr, self::CONTROLLER));
        }

        return $results;
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function knownExpressions(): iterable
    {
        yield 'literal arithmetic' => ['plain', 42];
        yield 'class constant' => ['fromClass', 50];
        yield 'constant in arithmetic' => ['arithmetic', 101];
        yield 'parentheses, nested constants, own private constant' => ['nested', 665];
        yield 'power' => ['power', 1024];
        yield 'concatenation' => ['concat', 'x50y'];
        yield 'class name string' => ['classString', self::LIMITS];
        yield 'array of constants' => ['listValue', [10, 20, 50]];
        yield 'child overrides a constant' => ['overridden', 25];
        yield 'self in a parent constant means the declaring class' => ['inherited', 100];
        yield 'constant defined from an overridden one' => ['triple', 75];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('knownExpressions')]
    public function testEvaluatesConstantExpressions(string $variable, mixed $expected): void
    {
        $results = $this->evaluateControllerExpressions();

        self::assertArrayHasKey($variable, $results);
        self::assertTrue($results[$variable]['known'], "{$variable} should be a constant");
        self::assertSame($expected, $results[$variable]['value']);
    }

    /** @return iterable<string, array{string}> */
    public static function notConstantExpressions(): iterable
    {
        yield 'division by zero' => ['divisionByZero'];
        yield 'variable' => ['fromVariable'];
        yield 'function call' => ['fromCall'];
        yield 'env()' => ['fromEnv'];
        yield 'class constant that does not exist' => ['unknownConstant'];
        yield 'late static binding' => ['staticLate'];
        yield 'constants that refer to each other' => ['loops'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notConstantExpressions')]
    public function testAnythingElseIsNotConstant(string $variable): void
    {
        $results = $this->evaluateControllerExpressions();

        self::assertArrayHasKey($variable, $results);
        self::assertFalse($results[$variable]['known'], "{$variable} must not be reported as a constant");
    }

    public function testClassConstants(): void
    {
        $index = self::index('src-const-eval');
        $limit = static fn (string $class, string $name): array => SourceShape::constant($index->classConstant($class, $name));

        self::assertSame(['known' => true, 'value' => 50], $limit(self::LIMITS, 'PAGE_SIZE'));
        self::assertSame(['known' => true, 'value' => 100], $limit(self::LIMITS, 'DOUBLE'));
        self::assertSame(['known' => true, 'value' => 100], $limit(self::LIMITS, 'CHUNK'));
        self::assertSame(['known' => true, 'value' => 3.5], $limit(self::LIMITS, 'HALF'));
        self::assertSame(['known' => true, 'value' => 1], $limit(self::LIMITS, 'REMAINDER'));
        self::assertSame(['known' => true, 'value' => -50], $limit(self::LIMITS, 'NEGATIVE'));
        self::assertSame(['known' => true, 'value' => 'page-50'], $limit(self::LIMITS, 'LABEL'));
        self::assertSame(['known' => true, 'value' => [10, 20, 50]], $limit(self::LIMITS, 'SIZES'));
        self::assertSame(['known' => true, 'value' => true], $limit(self::LIMITS, 'ENABLED'));
        self::assertSame(['known' => true, 'value' => PHP_INT_SIZE], $limit(self::LIMITS, 'FROM_GLOBAL'));
        self::assertIsInt($index->classConstant(self::LIMITS, 'CHUNK')->value, '1000 / 10 stays an int, as in PHP');
    }

    public function testClassConstantsThroughInheritance(): void
    {
        $index = self::index('src-const-eval');
        $limit = static fn (string $class, string $name): array => SourceShape::constant($index->classConstant($class, $name));

        self::assertSame(['known' => true, 'value' => 25], $limit(self::CHILD, 'PAGE_SIZE'));
        self::assertSame(['known' => true, 'value' => 100], $limit(self::CHILD, 'DOUBLE'));
        self::assertSame(['known' => true, 'value' => 100], $limit(self::CHILD, 'PARENT_DOUBLE'));
        self::assertSame(['known' => true, 'value' => 75], $limit(self::CHILD, 'TRIPLE'));
    }

    public function testClassConstantsThatCannotBeEvaluated(): void
    {
        $index = self::index('src-const-eval');

        self::assertFalse($index->classConstant(self::LIMITS, 'LOOPS_A')->known, 'a cycle must end in NotConstant, not in an endless loop');
        self::assertFalse($index->classConstant(self::LIMITS, 'MISSING')->known);
        self::assertFalse($index->classConstant('App\Support\NoSuchClass', 'PAGE_SIZE')->known);
        self::assertFalse($index->classConstant('Illuminate\Database\Eloquent\Model', 'CREATED_AT')->known, 'vendor constants are not read');
    }

    public function testSelfWithoutAClassContextIsNotConstant(): void
    {
        $index = self::index('src-const-eval');
        $stmts = $index->parse('app/Http/Controllers/ReportController.php');
        $assign = (new NodeFinder())->findFirst($stmts, static fn (Node $n): bool => $n instanceof Assign && $n->var instanceof Variable && $n->var->name === 'nested');

        self::assertNotNull($assign);
        self::assertFalse($index->evaluate($assign->expr, null)->known);
        self::assertTrue($index->evaluate($assign->expr, self::CONTROLLER)->known);
    }
}
