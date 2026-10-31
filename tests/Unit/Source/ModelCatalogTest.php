<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Source;

use PeakSim\Input\ArtisanSource;
use PeakSim\Input\ModelInfo;
use PeakSim\Input\RelationInfo;
use PeakSim\Source\AppModelCatalog;

final class ModelCatalogTest extends SourceCase
{
    /**
     * @param list<mixed> $orderObservers the observers the export gives Order (strings, or arrays as `model:show --json` may give)
     * @param list<mixed> $orderEvents the model events the export gives Order (strings, or arrays as `model:show --json` may give)
     */
    private function catalog(string $case = 'src-model-not-in-export-inherit', array $orderObservers = [], array $orderEvents = []): AppModelCatalog
    {
        $source = new class ($orderObservers, $orderEvents) implements ArtisanSource {
            /**
             * @param list<mixed> $orderObservers
             * @param list<mixed> $orderEvents
             */
            public function __construct(private readonly array $orderObservers, private readonly array $orderEvents)
            {
            }

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
                        $this->orderEvents,
                        $this->orderObservers,
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

    public function testAnExportedModelCarriesItsObserversFromTheExport(): void
    {
        $descriptor = $this->catalog('src-model-not-in-export-inherit', ['App\Observers\OrderObserver'])->describe('App\Models\Order');

        self::assertNotNull($descriptor);
        self::assertSame(['App\Observers\OrderObserver'], $descriptor->observers);
    }

    public function testAModelMissingFromTheExportInheritsItsAncestorsObservers(): void
    {
        $descriptor = $this->catalog('src-model-not-in-export-inherit', ['App\Observers\OrderObserver'])->describe('App\Models\EagerOrder');

        self::assertNotNull($descriptor);
        self::assertSame(['App\Observers\OrderObserver'], $descriptor->observers, 'the ancestor\'s observers, as for relations');
    }

    public function testAnExportedModelCarriesItsEventsFromTheExport(): void
    {
        $descriptor = $this->catalog('src-model-not-in-export-inherit', [], ['saving', 'created'])->describe('App\Models\Order');

        self::assertNotNull($descriptor);
        self::assertSame(['saving', 'created'], $descriptor->events);
    }

    public function testAModelMissingFromTheExportInheritsItsAncestorsEvents(): void
    {
        $descriptor = $this->catalog('src-model-not-in-export-inherit', [], ['saving', 'created'])->describe('App\Models\EagerOrder');

        self::assertNotNull($descriptor);
        self::assertSame(['saving', 'created'], $descriptor->events, 'the ancestor\'s events, as for observers');
    }

    public function testAnExportedObserverGivenAsAnArrayIsKeptNotDropped(): void
    {
        $descriptor = $this->catalog('src-model-not-in-export-inherit', [
            ['event' => 'created', 'class' => 'App\Observers\OrderObserver'],
            ['event' => 'saving', 'observer' => ['App\Observers\OrderObserver']],
        ])->describe('App\Models\Order');

        self::assertNotNull($descriptor);
        self::assertSame(
            [
                '{"event":"created","class":"App\\\\Observers\\\\OrderObserver"}',
                '{"event":"saving","observer":["App\\\\Observers\\\\OrderObserver"]}',
            ],
            $descriptor->observers,
            'a non-string entry is kept as JSON text, in the order the export gives',
        );
    }

    public function testAnInheritedObserverGivenAsAnArrayIsKept(): void
    {
        $descriptor = $this->catalog('src-model-not-in-export-inherit', [
            ['event' => 'created', 'class' => 'App\Observers\OrderObserver'],
        ])->describe('App\Models\EagerOrder');

        self::assertNotNull($descriptor);
        self::assertCount(1, $descriptor->observers, 'the ancestor\'s array-shaped observer, not an empty list');
    }

    public function testAnExportedEventGivenAsAnArrayIsKeptNotDropped(): void
    {
        $descriptor = $this->catalog('src-model-not-in-export-inherit', [], [
            ['event' => 'saving', 'observer' => ['App\Observers\OrderObserver']],
            'created',
        ])->describe('App\Models\Order');

        self::assertNotNull($descriptor);
        self::assertSame(
            ['{"event":"saving","observer":["App\\\\Observers\\\\OrderObserver"]}', 'created'],
            $descriptor->events,
            'string entries stay as they are; array entries are kept as JSON text',
        );
    }

    public function testAnInheritedEventGivenAsAnArrayIsKept(): void
    {
        $descriptor = $this->catalog('src-model-not-in-export-inherit', [], [
            ['event' => 'saving', 'observer' => ['App\Observers\OrderObserver']],
        ])->describe('App\Models\EagerOrder');

        self::assertNotNull($descriptor);
        self::assertCount(1, $descriptor->events, 'the ancestor\'s array-shaped event, not an empty list');
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
