<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Source;

use PeakSim\Source\ParseFailed;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;

final class SourceIndexFilesTest extends SourceCase
{
    public function testPsr4MapIsReadFromTheAppComposerJson(): void
    {
        $index = self::index('src-psr4-nested');

        self::assertSame('src/Billing/Invoicer.php', $index->findClass('App\Domain\Billing\Invoicer')?->file, 'the longest prefix wins');
        self::assertSame('app/Http/Controllers/ReportController.php', $index->findClass('App\Http\Controllers\ReportController')?->file);
    }

    public function testFallsBackToAShorterPrefixWhenTheLongestHasNoFile(): void
    {
        $index = self::index('src-psr4-nested');

        self::assertSame('app/Domain/Billing/Legacy.php', $index->findClass('App\Domain\Billing\Legacy')?->file);
    }

    public function testAPrefixWithSeveralDirectories(): void
    {
        $index = self::index('src-psr4-nested');

        self::assertSame('lib/shared/Clock.php', $index->findClass('Shared\Clock')?->file);
        self::assertSame('lib/extra/Timing/Stopwatch.php', $index->findClass('Shared\Timing\Stopwatch')?->file);
    }

    public function testUnknownClassesAreNull(): void
    {
        $index = self::index('src-psr4-nested');

        self::assertNull($index->findClass('App\Domain\Billing\Missing'));
        self::assertNull($index->findClass('Other\Thing'));
        self::assertNull($index->findClass('Illuminate\Support\Collection'));
    }

    public function testFilesAreListedSortedAndRelativeToTheAppRoot(): void
    {
        $index = self::index('src-psr4-nested');

        self::assertSame(['src/Billing/Invoicer.php'], $index->files('src'));
        self::assertSame(
            [
                'app/Domain/Billing/Legacy.php',
                'app/Http/Controllers/Controller.php',
                'app/Http/Controllers/ReportController.php',
                'app/Models/Customer.php',
                'app/Models/Order.php',
            ],
            $index->files('app'),
        );
        self::assertSame([], $index->files('nowhere'));
    }

    public function testRelativeStripsTheAppRootOnly(): void
    {
        $index = self::index('src-psr4-nested');
        $root = self::appPath('src-psr4-nested');

        self::assertSame('app/Models/Order.php', $index->relative($root . '/app/Models/Order.php'));
        self::assertSame('app/Models/Order.php', $index->relative('app/Models/Order.php'));
        self::assertSame('/elsewhere/file.php', $index->relative('/elsewhere/file.php'));
    }

    public function testParseReturnsNameResolvedAstAndParsesEachFileOnce(): void
    {
        $index = self::index('src-parse-error-gap');
        $first = $index->parse('app/Services/Fine.php');
        $second = $index->parse(self::appPath('src-parse-error-gap') . '/app/Services/Fine.php');

        self::assertSame($first, $second, 'relative and absolute spellings share one cached parse');
        $class = (new NodeFinder())->findFirstInstanceOf($first, ClassLike::class);
        self::assertNotNull($class);
        self::assertSame('App\Services\Fine', $class->namespacedName?->toString(), 'names are resolved');
    }

    public function testASyntaxErrorIsParseFailedEveryTime(): void
    {
        $index = self::index('src-parse-error-gap');

        foreach ([1, 2] as $attempt) {
            try {
                $index->parse('app/Services/Broken.php');
                self::fail('expected ParseFailed on attempt ' . $attempt);
            } catch (ParseFailed $e) {
                self::assertStringContainsString('app/Services/Broken.php', $e->getMessage());
            }
        }
    }

    public function testFindClassOnABrokenFileRaisesParseFailedInsteadOfHidingTheClass(): void
    {
        $index = self::index('src-parse-error-gap');

        $this->expectException(ParseFailed::class);
        $index->findClass('App\Services\Broken');
    }

    public function testOtherClassesAreStillReadableAfterAParseFailure(): void
    {
        $index = self::index('src-parse-error-gap');
        try {
            $index->parse('app/Services/Broken.php');
        } catch (ParseFailed) {
        }

        self::assertSame('App\Services\Fine', $index->findClass('App\Services\Fine')?->fqcn);
    }

    public function testSubclassesCannotBeListedWhileAFileUnderThePsr4DirectoriesIsBroken(): void
    {
        $index = self::index('src-parse-error-gap');

        $this->expectException(ParseFailed::class);
        $index->subclassesOf('App\Services\Fine');
    }

    public function testAnUnreadableFileIsParseFailedToo(): void
    {
        $index = self::index('src-parse-error-gap');

        $this->expectException(ParseFailed::class);
        $index->parse('app/Services/DoesNotExist.php');
    }
}
