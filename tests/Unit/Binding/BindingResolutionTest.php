<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Binding;

/** What resolve() answers: several candidates, context, none, decoration, and the cases it must not guess. */
final class BindingResolutionTest extends BindingCase
{
    private const READER = 'App\Contracts\Reader';
    private const EAGER = 'App\Readers\EagerReader';
    private const LAZY = 'App\Readers\LazyReader';

    public function testTwoProvidersGiveBothCandidatesInProviderOrder(): void
    {
        [$table, $files] = $this->make([
            'bootstrap/providers.php' => self::providers('AppServiceProvider', 'ReaderServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', '        $this->app->bind(Reader::class, EagerReader::class);'),
            'app/Providers/ReaderServiceProvider.php' => self::provider('ReaderServiceProvider', '        $this->app->bind(Reader::class, LazyReader::class);'),
        ]);

        $r = $table->resolve(self::READER);

        self::assertSame([[self::EAGER, 'bind', false], [self::LAZY, 'bind', false]], self::shape($r)['candidates']);
        self::assertSame('app/Providers/ReaderServiceProvider.php:' . self::lineIn($files['app/Providers/ReaderServiceProvider.php'], '$this->app->bind'), $r->candidates[1]->site->fingerprint());
    }

    public function testTheLaterBindingDoesNotWin(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        $this->app->bind(Reader::class, LazyReader::class);
        $this->app->bind(Reader::class, EagerReader::class);
PHP),
        ]);

        self::assertSame([[self::LAZY, 'bind', false], [self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testBootstrapProvidersComeBeforeConfigApp(): void
    {
        $table = $this->table([
            'config/app.php' => "<?php\n\nreturn ['providers' => [App\\Providers\\ReaderServiceProvider::class]];\n",
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', '        $this->app->bind(Reader::class, EagerReader::class);'),
            'app/Providers/ReaderServiceProvider.php' => self::provider('ReaderServiceProvider', '        $this->app->bind(Reader::class, LazyReader::class);'),
        ]);

        self::assertSame([self::EAGER, self::LAZY], array_column(self::shape($table->resolve(self::READER))['candidates'], 0));
    }

    public function testContextualBindingIsChosenByTheConsumer(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        $this->app->bind(Reader::class, EagerReader::class);
        $this->app->when(ReportService::class)->needs(Reader::class)->give(LazyReader::class);
PHP),
        ]);

        self::assertSame([[self::LAZY, 'contextual', false]], self::shape($table->resolve(self::READER, 'App\Services\ReportService'))['candidates']);
        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER, 'App\Services\ExportService'))['candidates']);
        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testContextualGiveWithAClosureAndAConsumerList(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        $this->app->when([ReportService::class, ExportService::class])
            ->needs(Reader::class)
            ->give(fn ($app) => $app->make(LazyReader::class));
PHP),
        ]);

        self::assertSame([[self::LAZY, 'contextual', false]], self::shape($table->resolve(self::READER, 'App\Services\ExportService'))['candidates']);
        self::assertSame([[self::LAZY, 'contextual', false]], self::shape($table->resolve(self::READER, 'App\Services\ReportService'))['candidates']);
        self::assertSame('none', $table->resolve(self::READER)->kind->value);
    }

    public function testAContextualGiveThatIsNotAClassIsUnresolvableForThatConsumer(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        $this->app->bind(Reader::class, EagerReader::class);
        $this->app->when(ReportService::class)->needs(Reader::class)->giveTagged('readers');
PHP),
        ]);

        self::assertSame('unresolvable', $table->resolve(self::READER, 'App\Services\ReportService')->kind->value);
        self::assertSame('candidates', $table->resolve(self::READER, 'App\Services\ExportService')->kind->value);
    }

    public function testAnUnboundInterfaceAndAnUnboundAbstractClassAreNone(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', ''),
        ]);

        self::assertSame(['kind' => 'none', 'candidates' => []], self::shape($table->resolve(self::READER)));
        self::assertSame('none', $table->resolve('App\Contracts\BaseWriter')->kind->value);
    }

    public function testAVendorInterfaceWithNoBindingIsNone(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', ''),
        ]);

        self::assertSame('none', $table->resolve('Illuminate\Contracts\Mail\Mailer')->kind->value);
    }

    public function testAConcreteClassWithNoBindingIsItself(): void
    {
        [$table, $files] = $this->make([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', ''),
        ]);

        $r = $table->resolve('App\Services\ReportService');

        self::assertSame([['App\Services\ReportService', 'self', false]], self::shape($r)['candidates']);
        self::assertSame('app/Services/ReportService.php:' . self::lineIn($files['app/Services/ReportService.php'], 'class ReportService'), $r->candidates[0]->site->fingerprint());
    }

    public function testAConcreteClassWithOnlyConditionalBindingsAlsoKeepsItself(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', '        $this->app->bindIf(ReportService::class, ExportService::class);'),
        ]);

        self::assertSame(
            [['App\Services\ExportService', 'bind', true], ['App\Services\ReportService', 'self', true]],
            self::shape($table->resolve('App\Services\ReportService'))['candidates'],
        );
    }

    public function testADecoratedAbstractIsUnresolvable(): void
    {
        [$table, $files] = $this->make([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        $this->app->bind(Reader::class, EagerReader::class);
        $this->app->extend(Reader::class, fn ($reader, $app) => new LazyReader());
PHP),
        ]);

        $r = $table->resolve(self::READER);

        self::assertSame('unresolvable', $r->kind->value);
        self::assertStringStartsWith('decorated:', (string) $r->reason);
        self::assertSame(self::at($files, 'app/Providers/AppServiceProvider.php', '$this->app->extend'), $r->site?->fingerprint());
    }

    public function testAfterResolvingAlsoDecorates(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        $this->app->bind(Reader::class, EagerReader::class);
        $this->app->afterResolving(Reader::class, function ($reader) {
        });
PHP),
        ]);

        $r = $table->resolve(self::READER);

        self::assertSame('unresolvable', $r->kind->value);
        self::assertStringStartsWith('decorated:', (string) $r->reason);
    }

    public function testDecoratingOneAbstractLeavesTheOthersAlone(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        $this->app->bind(Mailer::class, EagerReader::class);
        $this->app->extend(Reader::class, fn ($reader) => $reader);
PHP),
        ]);

        self::assertSame('candidates', $table->resolve('App\Contracts\Mailer')->kind->value);
    }

    public function testABindingWhoseAbstractIsNotConstantIsUnresolvableForAnyInterface(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        foreach (config('contracts') as $abstract => $impl) {
            $this->app->bind($abstract, $impl);
        }
PHP),
        ]);

        $r = $table->resolve(self::READER);

        self::assertSame('unresolvable', $r->kind->value);
        self::assertStringStartsWith('unresolvable:', (string) $r->reason);
    }

    public function testAProviderWithASyntaxErrorMakesResolutionUnresolvable(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => "<?php\nnamespace App\\Providers;\n\nclass AppServiceProvider {\n    public function register( {\n",
        ]);

        $r = $table->resolve(self::READER);

        self::assertSame('unresolvable', $r->kind->value);
        self::assertStringStartsWith('unresolvable:', (string) $r->reason);
    }

    public function testAnAppWithNoProviderListStillAnswers(): void
    {
        $table = $this->table([]);

        self::assertSame('none', $table->resolve(self::READER)->kind->value);
        self::assertSame('candidates', $table->resolve('App\Services\ReportService')->kind->value);
    }

    public function testTheAnswerIsStableAndCarriesNoAbsolutePath(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        $this->app->bind(Reader::class, fn ($app) => $app->isLocal() ? new LazyReader() : new EagerReader());
PHP),
        ]);

        $first = $table->resolve(self::READER);
        $second = $table->resolve(self::READER);

        self::assertEquals($first, $second);
        foreach ($first->candidates as $candidate) {
            self::assertStringStartsNotWith('/', $candidate->site->file);
            self::assertSame('app/Providers/AppServiceProvider.php', $candidate->site->file);
        }
    }

    public function testTheFixtureAppResolvesLayeredAndConditionalBindings(): void
    {
        $table = new \PeakSim\Binding\ProviderBindingTable(new \PeakSim\Source\AppSourceIndex(dirname(__DIR__, 3) . '/fixtures/laravel-app'));

        $layered = $table->resolve('App\Ng\Repositories\OrderRepository');
        $conditional = $table->resolve('App\Hidden\Contracts\OrderSummaries');

        self::assertSame([['App\Ng\Repositories\EloquentOrderRepository', 'bind', false]], self::shape($layered)['candidates']);
        self::assertSame(
            [['App\Hidden\Services\EagerOrderSummaries', 'bind', true], ['App\Hidden\Services\LazyOrderSummaries', 'bind', true]],
            self::shape($conditional)['candidates'],
        );
    }

    private static function lineIn(string $text, string $needle): int
    {
        foreach (explode("\n", $text) as $i => $line) {
            if (str_contains($line, $needle)) {
                return $i + 1;
            }
        }

        throw new \LogicException($needle);
    }
}
