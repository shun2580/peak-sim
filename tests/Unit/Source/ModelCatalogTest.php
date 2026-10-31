<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Source;

use PeakSim\Input\ArtisanSource;
use PeakSim\Input\ModelInfo;
use PeakSim\Input\RelationInfo;
use PeakSim\Source\AppModelCatalog;

final class ModelCatalogTest extends SourceCase
{
    private function catalog(string $case = 'src-model-not-in-export-inherit'): AppModelCatalog
    {
        $source = new class () implements ArtisanSource {
            public function routes(): array
            {
                return [];
            }

            public function modelInfo(string $class): ?ModelInfo
            {
                return match ($class) {
                    'App\Models\Order' => new ModelInfo(
                        'App\Models\Order',
                        'orders',
                        'mysql',
                        ['customer' => new RelationInfo('customer', 'BelongsTo', 'App\Models\Customer', false)],
                        [],
                        [],
                        [],
                    ),
                    'App\Models\Customer' => new ModelInfo('App\Models\Customer', 'customers', 'mysql', [], [], [], []),
                    default => null,
                };
            }
        };

        return new AppModelCatalog(self::index($case), $source);
    }

    public function testClassesWhoseAncestorIsAnEloquentModelAreModels(): void
    {
        $catalog = $this->catalog();

        self::assertTrue($catalog->isModel('App\Models\Order'));
        self::assertTrue($catalog->isModel('App\Models\EagerOrder'), 'a model that is not in the export');
        self::assertTrue($catalog->isModel('App\Models\AppendedOrder'), 'two levels below a model');
        self::assertFalse($catalog->isModel('App\Http\Controllers\ReportController'));
        self::assertFalse($catalog->isModel('App\Models\DoesNotExist'));
        self::assertFalse($catalog->isModel('Illuminate\Database\Eloquent\Model'), 'the vendor base class is not an app model');
    }

    public function testAnExportedModelIsDescribedFromTheExport(): void
    {
        $descriptor = $this->catalog()->describe('App\Models\Order');

        self::assertNotNull($descriptor);
        self::assertSame('orders', $descriptor->table);
        self::assertSame('mysql', $descriptor->driver);
        self::assertSame(['customer' => 'export'], SourceShape::relationSources($descriptor));
        self::assertSame([], $descriptor->with);
        self::assertSame([], $descriptor->appends);
        self::assertSame([], SourceShape::accessors($descriptor));
    }

    public function testAModelMissingFromTheExportInheritsItsAncestorsInfoAsInferred(): void
    {
        $descriptor = $this->catalog()->describe('App\Models\EagerOrder');

        self::assertNotNull($descriptor);
        self::assertSame('orders', $descriptor->table, '$table read from the AST');
        self::assertSame('mysql', $descriptor->driver);
        self::assertSame(['customer' => 'inferred'], SourceShape::relationSources($descriptor), 'RELATION_INFERRED is raised from this marker');
        self::assertSame(['customer'], $descriptor->with);
    }

    public function testWithAppendsAndAccessorsAreMergedAlongTheChain(): void
    {
        $descriptor = $this->catalog()->describe('App\Models\AppendedOrder');

        self::assertNotNull($descriptor);
        self::assertSame(['customer', 'items'], $descriptor->with, 'ancestors first, then the class itself, no duplicates');
        self::assertSame(['customer_name', 'label'], $descriptor->appends);
        self::assertSame(
            [
                'customer_name' => 'getCustomerNameAttribute',
                'label' => 'label',
                'short_code' => 'getShortCodeAttribute',
                'status_label' => 'getStatusLabelAttribute',
            ],
            SourceShape::accessors($descriptor),
            'old and new style accessors, appended or not, declared on the class or in a trait it uses',
        );
        self::assertSame(['customer' => 'inferred'], SourceShape::relationSources($descriptor));
    }

    public function testAModelDeclaringNothingTakesTableAndRelationsFromTheExportedAncestor(): void
    {
        $descriptor = $this->catalog()->describe('App\Models\ArchivedOrder');

        self::assertNotNull($descriptor);
        self::assertSame('orders', $descriptor->table);
        self::assertSame([], $descriptor->with);
        self::assertSame(['customer' => 'inferred'], SourceShape::relationSources($descriptor));
    }

    public function testAModelNoExportKnowsHasNoDescriptorButIsStillAModel(): void
    {
        $source = new class () implements ArtisanSource {
            public function routes(): array
            {
                return [];
            }

            public function modelInfo(string $class): ?ModelInfo
            {
                return null;
            }
        };
        $catalog = new AppModelCatalog(self::index('src-model-not-in-export-inherit'), $source);

        self::assertTrue($catalog->isModel('App\Models\Order'));
        self::assertNull($catalog->describe('App\Models\Order'), 'the caller turns this into a MODEL_INFO_UNAVAILABLE gap');
    }

    public function testNothingIsUnresolvedForTheFixtureModels(): void
    {
        $descriptor = $this->catalog()->describe('App\Models\AppendedOrder');

        self::assertNotNull($descriptor);
        self::assertSame([], $descriptor->unresolved);
    }

    public function testAPropertyThatIsNotConstantIsReportedInsteadOfDroppedSilently(): void
    {
        $catalog = $this->catalog();
        $descriptor = $catalog->describe('App\Models\DynamicOrder');

        self::assertNotNull($descriptor);
        self::assertSame([], $descriptor->with);
        self::assertSame(['with'], $descriptor->unresolved);
    }

    public function testNonModelsAndUnknownClassesHaveNoDescriptor(): void
    {
        $catalog = $this->catalog();

        self::assertNull($catalog->describe('App\Http\Controllers\ReportController'));
        self::assertNull($catalog->describe('App\Models\DoesNotExist'));
    }
}
