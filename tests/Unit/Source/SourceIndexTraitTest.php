<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Source;

final class SourceIndexTraitTest extends SourceCase
{
    public function testMethodFromATraitUsedByTheClass(): void
    {
        $index = self::index('src-trait-simple');
        $found = SourceShape::found($index->resolveMethod('App\Http\Controllers\ReportController', 'summarize'));

        self::assertSame(
            ['owner' => 'App\Concerns\ListsOrders', 'file' => 'app/Concerns/ListsOrders.php', 'name' => 'summarize', 'via' => 'App\Concerns\ListsOrders'],
            $found,
        );
    }

    public function testTheClassOwnMethodIsFoundBeforeAnythingElse(): void
    {
        $index = self::index('src-trait-simple');
        $found = SourceShape::found($index->resolveMethod('App\Http\Controllers\ReportController', 'index'));

        self::assertSame('App\Http\Controllers\ReportController', $found['owner'] ?? null);
        self::assertNull($found['via'] ?? 'unset', 'declared on the class itself: no trait in between');
    }

    public function testLeadingBackslashOfTheClassNameIsTolerated(): void
    {
        $index = self::index('src-trait-simple');
        $found = SourceShape::found($index->resolveMethod('\App\Http\Controllers\ReportController', 'summarize'));

        self::assertSame('App\Concerns\ListsOrders', $found['owner'] ?? null);
    }

    public function testAMethodThatDoesNotExistAnywhereIsMissing(): void
    {
        $index = self::index('src-trait-simple');
        $resolution = $index->resolveMethod('App\Http\Controllers\ReportController', 'nothingLikeThis');

        self::assertNull(SourceShape::found($resolution));
        self::assertSame('Missing', SourceShape::notFoundReason($resolution));
    }

    public function testMethodFromATraitUsedByATrait(): void
    {
        $index = self::index('src-trait-nested');
        $class = 'App\Http\Controllers\ReportController';

        self::assertSame('App\Concerns\LoadsCustomers', SourceShape::found($index->resolveMethod($class, 'loadCustomers'))['owner'] ?? null);
        self::assertSame('App\Concerns\ReportsOrders', SourceShape::found($index->resolveMethod($class, 'report'))['owner'] ?? null);
    }

    public function testInsteadofPicksTheChosenTrait(): void
    {
        $index = self::index('src-trait-insteadof');
        $found = SourceShape::found($index->resolveMethod('App\Http\Controllers\ReportController', 'load'));

        self::assertSame('App\Concerns\FastLoader', $found['owner'] ?? null);
        self::assertSame('load', $found['name'] ?? null);
    }

    public function testAnAsAliasResolvesToTheExcludedTraitMethod(): void
    {
        $index = self::index('src-trait-insteadof');
        $found = SourceShape::found($index->resolveMethod('App\Http\Controllers\ReportController', 'loadSlow'));

        self::assertSame('App\Concerns\SlowLoader', $found['owner'] ?? null);
        self::assertSame('load', $found['name'] ?? null, 'the node is the original method; only the name it is called by differs');
    }

    public function testTwoTraitsDefiningTheSameMethodWithoutInsteadofIsAConflict(): void
    {
        $index = self::index('src-trait-conflict-gap');
        $resolution = $index->resolveMethod('App\Http\Controllers\ReportController', 'load');

        self::assertNull(SourceShape::found($resolution), 'a conflict must not silently pick one of the traits');
        self::assertSame('TraitConflict', SourceShape::notFoundReason($resolution));
    }

    public function testAMethodOnlyOneConflictingTraitDefinesIsStillFound(): void
    {
        $index = self::index('src-trait-conflict-gap');
        $found = SourceShape::found($index->resolveMethod('App\Http\Controllers\ReportController', 'onlyFast'));

        self::assertSame('App\Concerns\FastLoader', $found['owner'] ?? null);
    }

    public function testTraitMethodsCanBeResolvedOnTheTraitItself(): void
    {
        $index = self::index('src-trait-nested');
        $found = SourceShape::found($index->resolveMethod('App\Concerns\ReportsOrders', 'loadCustomers'));

        self::assertSame('App\Concerns\LoadsCustomers', $found['owner'] ?? null);
    }
}
