<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Source;

final class SourceIndexParentTest extends SourceCase
{
    private const MODEL = 'Illuminate\Database\Eloquent\Model';

    public function testWalksOwnClassThenTraitThenParents(): void
    {
        $index = self::index('src-parent-app-chain');
        $class = 'App\Http\Controllers\OrderReportController';

        self::assertSame('App\Http\Controllers\OrderReportController', SourceShape::found($index->resolveMethod($class, 'index'))['owner'] ?? null);
        // overridden one level up: the nearest declaration wins
        self::assertSame('App\Http\Controllers\ReportController', SourceShape::found($index->resolveMethod($class, 'title'))['owner'] ?? null);
        // trait of the parent
        self::assertSame('App\Concerns\HasSubtitle', SourceShape::found($index->resolveMethod($class, 'subtitle'))['owner'] ?? null);
        // two levels up
        self::assertSame('App\Http\Controllers\Controller', SourceShape::found($index->resolveMethod($class, 'customerNames'))['owner'] ?? null);
    }

    public function testAMethodMissingFromAnAppOnlyChainIsMissing(): void
    {
        $index = self::index('src-parent-app-chain');
        $resolution = $index->resolveMethod('App\Http\Controllers\OrderReportController', 'nothingLikeThis');

        self::assertSame('Missing', SourceShape::notFoundReason($resolution));
    }

    public function testASubclassSeesTheDescendantsOfAClass(): void
    {
        $index = self::index('src-parent-app-chain');

        self::assertSame(
            ['App\Http\Controllers\OrderReportController', 'App\Http\Controllers\ReportController'],
            $index->subclassesOf('App\Http\Controllers\Controller'),
            'direct and indirect descendants, sorted',
        );
        self::assertSame(['App\Http\Controllers\OrderReportController'], $index->subclassesOf('App\Http\Controllers\ReportController'));
        self::assertSame([], $index->subclassesOf('App\Http\Controllers\OrderReportController'));
        self::assertSame([], $index->subclassesOf('App\Nothing\AtAll'));
    }

    public function testFallingOffTheAppIntoVendorIsAVendorParentGap(): void
    {
        $index = self::index('src-parent-vendor-gap');
        $class = 'App\Http\Controllers\ReportController';

        self::assertSame('App\Http\Controllers\ReportController', SourceShape::found($index->resolveMethod($class, 'index'))['owner'] ?? null);

        $resolution = $index->resolveMethod($class, 'inheritedFromVendor');
        self::assertNull(SourceShape::found($resolution));
        self::assertSame('VendorParent', SourceShape::notFoundReason($resolution), 'the vendor parent may well define it');
    }

    public function testExtendsVendorFollowsTheWholeChain(): void
    {
        $index = self::index('src-parent-vendor-gap');
        $controller = 'App\Http\Controllers\ReportController';

        self::assertTrue($index->extendsVendor($controller, 'Illuminate\Routing\Controller'));
        self::assertTrue($index->extendsVendor('\\' . $controller, '\Illuminate\Routing\Controller'));
        self::assertFalse($index->extendsVendor($controller, self::MODEL));
        self::assertTrue($index->extendsVendor('App\Models\Order', self::MODEL));
        self::assertFalse($index->extendsVendor('App\Models\Nothing', self::MODEL));
    }

    public function testExtendsVendorSeesThroughAppParents(): void
    {
        $index = self::index('src-model-not-in-export-inherit');

        self::assertTrue($index->extendsVendor('App\Models\AppendedOrder', self::MODEL));
        self::assertFalse($index->extendsVendor('App\Http\Controllers\ReportController', self::MODEL));
    }

    public function testPropertyTypeOfAPromotedConstructorArgument(): void
    {
        $index = self::index('src-psr4-nested');

        self::assertSame('App\Domain\Billing\Invoicer', $index->propertyType('App\Http\Controllers\ReportController', 'invoicer'));
        self::assertNull($index->propertyType('App\Http\Controllers\ReportController', 'missing'));
    }

    public function testPropertyTypeOfATypedPropertyDeclaredInAParent(): void
    {
        $index = self::index('src-parent-app-chain');

        self::assertSame('App\Models\Order', $index->propertyType('App\Http\Controllers\OrderReportController', 'latest'), 'nullable types give the class name');
    }

    public function testFindClassDescribesTheClass(): void
    {
        $index = self::index('src-parent-app-chain');
        $info = $index->findClass('App\Http\Controllers\ReportController');

        self::assertNotNull($info);
        self::assertSame('App\Http\Controllers\ReportController', $info->fqcn);
        self::assertSame('app/Http/Controllers/ReportController.php', $info->file);
        self::assertSame('App\Http\Controllers\Controller', $info->parent);
        self::assertSame(['App\Concerns\HasSubtitle'], $info->traits);
        self::assertFalse($info->isAbstract);
        self::assertTrue($index->findClass('App\Http\Controllers\Controller')->isAbstract);
        self::assertNull($index->findClass('Illuminate\Routing\Controller'), 'vendor classes are not indexed');
    }
}
