<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Binding;

/** The second argument of a binding: class constant, string, closure, first-class callable. */
final class BindingImplementationTest extends BindingCase
{
    private const READER = 'App\Contracts\Reader';
    private const EAGER = 'App\Readers\EagerReader';
    private const LAZY = 'App\Readers\LazyReader';

    /** @return array<string, string> */
    private static function app(string $register, string $members = ''): array
    {
        return [
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', $register, $members),
        ];
    }

    public function testAStringConstantAndAClassConstantAreEvaluated(): void
    {
        $table = $this->table(self::app(
            '        $this->app->bind(Reader::class, self::IMPL);' . "\n" . '        $this->app->bind(Mailer::class, \'App\\Readers\\LazyReader\');',
            "    private const IMPL = EagerReader::class;\n",
        ));

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
        self::assertSame([[self::LAZY, 'bind', false]], self::shape($table->resolve('App\Contracts\Mailer'))['candidates']);
    }

    public function testAClosureReturningNewIsFollowed(): void
    {
        $table = $this->table(self::app('        $this->app->bind(Reader::class, function ($app) {' . "\n" . '            return new EagerReader();' . "\n" . '        });'));

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testMakeAppAndResolveAreFollowedInArrowFunctions(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        $this->app->bind(Reader::class, fn ($app) => $app->make(EagerReader::class));
        $this->app->bind(Mailer::class, fn () => app(LazyReader::class));
        $this->app->bind('App\Contracts\BaseWriter', fn () => resolve(ReportService::class));
PHP));

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
        self::assertSame([[self::LAZY, 'bind', false]], self::shape($table->resolve('App\Contracts\Mailer'))['candidates']);
        self::assertSame([['App\Services\ReportService', 'bind', false]], self::shape($table->resolve('App\Contracts\BaseWriter'))['candidates']);
    }

    public function testAReturnedClassConstantIsFollowed(): void
    {
        $table = $this->table(self::app('        $this->app->bind(Reader::class, fn () => EagerReader::class);'));

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testBothBranchesOfATernaryAreCandidatesAndConditional(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        $this->app->bind(Reader::class, fn ($app) => $app->environment('production')
            ? $app->make(EagerReader::class)
            : $app->make(LazyReader::class));
PHP));

        self::assertSame(
            [[self::EAGER, 'bind', true], [self::LAZY, 'bind', true]],
            self::shape($table->resolve(self::READER))['candidates'],
        );
    }

    public function testEveryReturnOfAnIfElseIsACandidate(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        $this->app->bind(Reader::class, function ($app) {
            if ($app->isLocal()) {
                return new LazyReader();
            }

            return new EagerReader();
        });
PHP));

        self::assertSame(
            [[self::LAZY, 'bind', true], [self::EAGER, 'bind', true]],
            self::shape($table->resolve(self::READER))['candidates'],
        );
    }

    public function testAVariableIsFollowedToEveryAssignment(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        $this->app->bind(Reader::class, function ($app) {
            $reader = new EagerReader();
            if ($app->isLocal()) {
                $reader = $app->make(LazyReader::class);
            }

            return $reader;
        });
PHP));

        self::assertSame(
            [[self::EAGER, 'bind', true], [self::LAZY, 'bind', true]],
            self::shape($table->resolve(self::READER))['candidates'],
        );
    }

    public function testMakeWithATernaryOfClassNamesGivesBoth(): void
    {
        $table = $this->table(self::app('        $this->app->bind(Reader::class, fn ($app) => $app->make($app->isLocal() ? LazyReader::class : EagerReader::class));'));

        self::assertSame(
            [[self::LAZY, 'bind', true], [self::EAGER, 'bind', true]],
            self::shape($table->resolve(self::READER))['candidates'],
        );
    }

    public function testNewWithArgumentsNamesOnlyTheOuterClass(): void
    {
        $table = $this->table(self::app('        $this->app->bind(Reader::class, fn () => new EagerReader(new LazyReader()));'));

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testAClosureWhoseResultIsNotKnownIsUnresolvable(): void
    {
        [$table, $files] = $this->make(self::app('        $this->app->bind(Reader::class, fn ($app) => $app->make($app[\'config\']->get(\'reader\')));'));

        $r = $table->resolve(self::READER);

        self::assertSame('unresolvable', $r->kind->value);
        self::assertSame([], $r->candidates);
        self::assertSame(self::at($files, 'app/Providers/AppServiceProvider.php', '$this->app->bind'), $r->site?->fingerprint());
        self::assertStringStartsWith('unresolvable:', (string) $r->reason);
    }

    public function testOneUnknownReturnMakesTheWholeClosureUnresolvable(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        $this->app->bind(Reader::class, function ($app) {
            if ($app->isLocal()) {
                return new LazyReader();
            }

            return $app->makeWith($app['config']->get('reader'));
        });
PHP));

        self::assertSame('unresolvable', $table->resolve(self::READER)->kind->value);
    }

    public function testASelfStaticFirstClassCallableIsReadFromTheProvider(): void
    {
        [$table, $files] = $this->make(self::app(
            '        $this->app->bind(Reader::class, self::byEnvironment(...));',
            <<<'PHP'
    public static function byEnvironment(Application $app): Reader
    {
        return $app->environment('production')
            ? $app->make(EagerReader::class)
            : $app->make(LazyReader::class);
    }

PHP,
        ));

        $r = $table->resolve(self::READER);

        self::assertSame(
            [[self::EAGER, 'bind', true], [self::LAZY, 'bind', true]],
            self::shape($r)['candidates'],
        );
        self::assertSame(
            self::at($files, 'app/Providers/AppServiceProvider.php', '$app->make(EagerReader::class)'),
            $r->candidates[0]->site->fingerprint(),
        );
    }

    public function testAThisFirstClassCallableAndAnArrayCallableAreRead(): void
    {
        $table = $this->table(self::app(
            '        $this->app->bind(Reader::class, $this->eager(...));' . "\n" . '        $this->app->bind(Mailer::class, [AppServiceProvider::class, \'lazy\']);',
            <<<'PHP'
    public function eager(): Reader
    {
        return new EagerReader();
    }

    public static function lazy(): Mailer
    {
        return new LazyReader();
    }

PHP,
        ));

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
        self::assertSame([[self::LAZY, 'bind', false]], self::shape($table->resolve('App\Contracts\Mailer'))['candidates']);
    }

    public function testACallableToAMissingMethodIsUnresolvable(): void
    {
        $table = $this->table(self::app('        $this->app->bind(Reader::class, self::gone(...));'));

        self::assertSame('unresolvable', $table->resolve(self::READER)->kind->value);
    }

    public function testAnImplementationThatIsAVariableIsUnresolvable(): void
    {
        $table = $this->table(self::app('        $impl = config(\'reader\');' . "\n" . '        $this->app->bind(Reader::class, $impl);'));

        self::assertSame('unresolvable', $table->resolve(self::READER)->kind->value);
    }

    public function testAnEnvironmentNameIsNeverEvaluated(): void
    {
        // both branches stay, whatever the environment would be
        $table = $this->table(self::app(<<<'PHP'
        if ($this->app->environment('production')) {
            $this->app->bind(Reader::class, EagerReader::class);
        } else {
            $this->app->bind(Reader::class, LazyReader::class);
        }
PHP));

        self::assertSame(
            [[self::EAGER, 'bind', true], [self::LAZY, 'bind', true]],
            self::shape($table->resolve(self::READER))['candidates'],
        );
    }
}
