<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Binding;

/** Where bindings come from: the call forms, the properties and the three provider lists. */
final class BindingCallsTest extends BindingCase
{
    private const READER = 'App\Contracts\Reader';
    private const EAGER = 'App\Readers\EagerReader';
    private const LAZY = 'App\Readers\LazyReader';

    public function testBindRecordsTheImplementationAndItsSite(): void
    {
        [$table, $files] = $this->make([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider(
                'AppServiceProvider',
                '        $this->app->bind(Reader::class, EagerReader::class);',
            ),
        ]);

        $r = $table->resolve(self::READER);

        self::assertSame(['kind' => 'candidates', 'candidates' => [[self::EAGER, 'bind', false]]], self::shape($r));
        self::assertSame(
            self::at($files, 'app/Providers/AppServiceProvider.php', '$this->app->bind'),
            $r->candidates[0]->site->fingerprint(),
        );
    }

    public function testSingletonAndScopedKeepTheirKind(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        $this->app->singleton(Reader::class, EagerReader::class);
        $this->app->scoped(Mailer::class, LazyReader::class);
PHP),
        ]);

        self::assertSame([[self::EAGER, 'singleton', false]], self::shape($table->resolve(self::READER))['candidates']);
        self::assertSame([[self::LAZY, 'scoped', false]], self::shape($table->resolve('App\Contracts\Mailer'))['candidates']);
    }

    public function testTheIfVariantsAreConditional(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        $this->app->bindIf(Reader::class, EagerReader::class);
        $this->app->singletonIf(Mailer::class, LazyReader::class);
        $this->app->scopedIf('App\Contracts\BaseWriter', ReportService::class);
PHP),
        ]);

        self::assertSame([[self::EAGER, 'bind', true]], self::shape($table->resolve(self::READER))['candidates']);
        self::assertSame([[self::LAZY, 'singleton', true]], self::shape($table->resolve('App\Contracts\Mailer'))['candidates']);
        self::assertSame([['App\Services\ReportService', 'scoped', true]], self::shape($table->resolve('App\Contracts\BaseWriter'))['candidates']);
    }

    public function testTheHelperAndFacadeFormsAreRead(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', <<<'PHP'
        app()->bind(Reader::class, EagerReader::class);
        App::singleton(Mailer::class, LazyReader::class);
PHP),
        ]);

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
        self::assertSame([[self::LAZY, 'singleton', false]], self::shape($table->resolve('App\Contracts\Mailer'))['candidates']);
    }

    public function testASingleArgumentBindsTheClassToItself(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', '        $this->app->singleton(ReportService::class);'),
        ]);

        self::assertSame([['App\Services\ReportService', 'singleton', false]], self::shape($table->resolve('App\Services\ReportService'))['candidates']);
    }

    public function testBindingsPropertyIsRead(): void
    {
        [$table, $files] = $this->make([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider(
                'AppServiceProvider',
                '',
                "    public array \$bindings = [\n        Reader::class => EagerReader::class,\n    ];\n",
            ),
        ]);

        $r = $table->resolve(self::READER);

        self::assertSame([[self::EAGER, 'property', false]], self::shape($r)['candidates']);
        self::assertSame(self::at($files, 'app/Providers/AppServiceProvider.php', 'Reader::class => EagerReader'), $r->candidates[0]->site->fingerprint());
    }

    public function testSingletonsPropertyTakesKeyedAndBareEntries(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider(
                'AppServiceProvider',
                '',
                "    public array \$singletons = [\n        Reader::class => LazyReader::class,\n        ReportService::class,\n    ];\n",
            ),
        ]);

        self::assertSame([[self::LAZY, 'property', false]], self::shape($table->resolve(self::READER))['candidates']);
        self::assertSame([['App\Services\ReportService', 'property', false]], self::shape($table->resolve('App\Services\ReportService'))['candidates']);
    }

    public function testProvidersAreTakenFromConfigAppIncludingAMergeCall(): void
    {
        $table = $this->table([
            'config/app.php' => <<<'PHP'
<?php

use Illuminate\Support\ServiceProvider;

return [
    'name' => 'demo',
    'providers' => ServiceProvider::defaultProviders()->merge([
        App\Providers\AppServiceProvider::class,
    ])->toArray(),
];
PHP,
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', '        $this->app->bind(Reader::class, EagerReader::class);'),
        ]);

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testProvidersAreTakenFromWithProviders(): void
    {
        $table = $this->table([
            'bootstrap/app.php' => <<<'PHP'
<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        App\Providers\AppServiceProvider::class,
    ])
    ->create();
PHP,
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', '        $this->app->bind(Reader::class, LazyReader::class);'),
        ]);

        self::assertSame([[self::LAZY, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testAProviderListedTwiceIsReadOnce(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'config/app.php' => "<?php\n\nreturn ['providers' => [App\\Providers\\AppServiceProvider::class]];\n",
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', '        $this->app->bind(Reader::class, EagerReader::class);'),
        ]);

        self::assertCount(1, $table->resolve(self::READER)->candidates);
    }

    public function testAProviderThatIsNotListedIsNotRead(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', ''),
            'app/Providers/OtherServiceProvider.php' => self::provider('OtherServiceProvider', '        $this->app->bind(Reader::class, EagerReader::class);'),
        ]);

        self::assertSame('none', $table->resolve(self::READER)->kind->value);
    }

    public function testVendorProvidersAreSkippedWithoutError(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => "<?php\n\nreturn [\n    Illuminate\\Auth\\AuthServiceProvider::class,\n    App\\Providers\\AppServiceProvider::class,\n];\n",
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', '        $this->app->bind(Reader::class, EagerReader::class);'),
        ]);

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testBindingsOfAnAppParentProviderAreInherited(): void
    {
        $table = $this->table([
            'bootstrap/providers.php' => self::providers('ChildServiceProvider'),
            'app/Providers/BaseServiceProvider.php' => self::provider('BaseServiceProvider', '        $this->app->bind(Reader::class, EagerReader::class);'),
            'app/Providers/ChildServiceProvider.php' => self::provider('ChildServiceProvider', '', '', 'BaseServiceProvider'),
        ]);

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }
}
