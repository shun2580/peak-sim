<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Binding;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A closure passed alone to bind() / singleton() / scoped() binds the class its return type names
 * (Container::bindBasedOnClosureReturnTypes). The return type is the abstract, never the implementation:
 * the implementation is read from the closure body, and a body that cannot be read stays unresolvable.
 */
final class BindingClosureReturnTypeTest extends BindingCase
{
    private const READER = 'App\Contracts\Reader';
    private const MAILER = 'App\Contracts\Mailer';
    private const EAGER = 'App\Readers\EagerReader';
    private const LAZY = 'App\Readers\LazyReader';

    /** @return array<string, string> */
    private static function app(string $register): array
    {
        return [
            'bootstrap/providers.php' => self::providers('AppServiceProvider'),
            'app/Providers/AppServiceProvider.php' => self::provider('AppServiceProvider', $register),
        ];
    }

    public function testTheReturnTypeIsTheAbstractAndTheBodyIsTheImplementation(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        $this->app->bind(static function (Application $app): Reader {
            return new EagerReader();
        });
PHP));

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testAnArrowFunctionWithAReturnTypeIsRead(): void
    {
        $table = $this->table(self::app('        $this->app->bind(static fn (): Reader => app(LazyReader::class));'));

        self::assertSame([[self::LAZY, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testAVariableFilledByAppAndReturnedIsFollowed(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        $this->app->bind(static function (Application $app): Reader {
            /** @var Reader $repository */
            $repository = app(EagerReader::class);
            if ($app->auth->check()) {
                $repository->setUser(auth()->user());
            }

            return $repository;
        });
PHP));

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testSingletonAndScopedKeepTheirKind(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        $this->app->singleton(static fn (): Reader => new EagerReader());
        app()->scoped(static fn (): Mailer => new LazyReader());
PHP));

        self::assertSame([[self::EAGER, 'singleton', false]], self::shape($table->resolve(self::READER))['candidates']);
        self::assertSame([[self::LAZY, 'scoped', false]], self::shape($table->resolve(self::MAILER))['candidates']);
    }

    public function testANullableReturnTypeNamesTheClass(): void
    {
        $table = $this->table(self::app('        $this->app->bind(static fn (): ?Reader => new EagerReader());'));

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testEveryNameOfAUnionReturnTypeIsBoundToTheClosure(): void
    {
        $table = $this->table(self::app('        $this->app->bind(static fn (): Reader|Mailer => new EagerReader());'));

        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::READER))['candidates']);
        self::assertSame([[self::EAGER, 'bind', false]], self::shape($table->resolve(self::MAILER))['candidates']);
    }

    public function testTwoBranchesOfTheBodyAreCandidatesAndConditional(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        $this->app->bind(static fn (Application $app): Reader => $app->isLocal() ? new LazyReader() : new EagerReader());
PHP));

        self::assertSame(
            [[self::LAZY, 'bind', true], [self::EAGER, 'bind', true]],
            self::shape($table->resolve(self::READER))['candidates'],
        );
    }

    public function testARegistrationInsideAnIfIsConditional(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        if (config('app.fast')) {
            $this->app->bind(static fn (): Reader => new EagerReader());
        }
PHP));

        self::assertContains([self::EAGER, 'bind', true], self::shape($table->resolve(self::READER))['candidates']);
    }

    public function testReadingTheAbstractDoesNotPoisonTheOtherBindings(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        $this->app->bind(static fn (): Reader => new EagerReader());
        $this->app->bind(Mailer::class, LazyReader::class);
PHP));

        self::assertSame('candidates', $table->resolve(self::MAILER)->kind->value);
        self::assertSame([[self::LAZY, 'bind', false]], self::shape($table->resolve(self::MAILER))['candidates']);
    }

    public function testABodyThatCannotBeReadIsUnresolvableForThatAbstractOnly(): void
    {
        $table = $this->table(self::app(<<<'PHP'
        $this->app->bind(static fn (): Reader => app(config('readers.default')));
        $this->app->bind(Mailer::class, LazyReader::class);
PHP));

        $reader = $table->resolve(self::READER);

        self::assertSame('unresolvable', $reader->kind->value);
        self::assertStringStartsWith('unresolvable:', (string) $reader->reason);
        self::assertSame('candidates', $table->resolve(self::MAILER)->kind->value);
    }

    public function testAConcreteReturnTypeIsNotTheImplementation(): void
    {
        // the closure may return a subclass: the declared class is an upper bound, not the answer
        $table = $this->table(self::app('        $this->app->bind(static fn (): EagerReader => app(config(\'readers.default\')));'));

        self::assertSame('unresolvable', $table->resolve(self::EAGER)->kind->value);
    }

    /** @return array<string, array{0: string}> */
    public static function returnTypesThatNameNoClass(): array
    {
        return [
            'no return type' => [''],
            'object' => [': object'],
            'mixed' => [': mixed'],
            'string' => [': string'],
            'self' => [': self'],
            'static' => [': static'],
            'intersection' => [': Reader&Mailer'],
            'built-in in a union' => [': Reader|string'],
        ];
    }

    #[DataProvider('returnTypesThatNameNoClass')]
    public function testAReturnTypeThatNamesNoClassLeavesTheAbstractUnknown(string $returnType): void
    {
        $table = $this->table(self::app('        $this->app->bind(static function ()' . $returnType . ' { return new EagerReader(); });'));

        // the abstract is unknown, so it may be any interface: nothing is resolved
        foreach ([self::READER, self::MAILER] as $abstract) {
            $r = $table->resolve($abstract);
            self::assertSame('unresolvable', $r->kind->value, $abstract);
            self::assertStringStartsWith('unresolvable:', (string) $r->reason);
        }
    }

    public function testAClosureWithASecondArgumentIsNotFollowed(): void
    {
        $table = $this->table(self::app('        $this->app->bind(static fn (): Reader => new EagerReader(), LazyReader::class);'));

        self::assertSame('unresolvable', $table->resolve(self::READER)->kind->value);
    }

    public function testTheIfFormsDoNotTakeAClosureAlone(): void
    {
        $table = $this->table(self::app('        $this->app->bindIf(static fn (): Reader => new EagerReader());'));

        self::assertSame('unresolvable', $table->resolve(self::READER)->kind->value);
    }

    public function testTheReasonNamesTheReturnType(): void
    {
        $table = $this->table(self::app('        $this->app->bind(static function () { return new EagerReader(); });'));

        self::assertStringContainsString('return type', (string) $table->resolve(self::READER)->reason);
    }
}
