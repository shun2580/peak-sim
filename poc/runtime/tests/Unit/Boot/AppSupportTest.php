<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Boot;

use PeakSim\Runtime\Boot\AppSupport;
use PeakSim\Runtime\Boot\EmptyVite;
use PeakSim\Runtime\Tests\Unit\Boot\Stubs\FakeApp;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/FakeApp.php';

/**
 * What the tool does for any Laravel app that has a package, instead of a profile that says it: a throw-away key for
 * Passport and an empty asset helper for Vite. Both are kept in memory; neither touches the database.
 */
final class AppSupportTest extends TestCase
{
    private const PASSPORT = 'Laravel\\Passport\\Passport';

    private const VITE = 'Illuminate\\Foundation\\Vite';

    /** @param list<string> $present class names the app has */
    private static function has(array $present): \Closure
    {
        return static fn (string $class): bool => in_array($class, $present, true);
    }

    public function testAnAppWithPassportAndNoKeyGetsAThrowAwayKeyPairInMemory(): void
    {
        $app = new FakeApp();

        $report = AppSupport::apply($app, self::has([self::PASSPORT]));

        $public = $app->config()->get('passport.public_key');
        $private = $app->config()->get('passport.private_key');
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $public);
        $this->assertStringContainsString('PRIVATE KEY-----', $private);
        $this->assertContains('passport_key', $report);
    }

    public function testThePassportKeyIsNeverWrittenToAFile(): void
    {
        $before = glob(sys_get_temp_dir() . '/*') ?: [];
        AppSupport::apply(new FakeApp(), self::has([self::PASSPORT]));

        $this->assertSame($before, glob(sys_get_temp_dir() . '/*') ?: []);
    }

    public function testAKeyTheAppAlreadyHasIsLeftAlone(): void
    {
        $app = new FakeApp(['passport.public_key' => 'the apps own']);

        $report = AppSupport::apply($app, self::has([self::PASSPORT]));

        $this->assertSame('the apps own', $app->config()->get('passport.public_key'));
        $this->assertNotContains('passport_key', $report);
    }

    public function testAnAppWithoutPassportGetsNoKey(): void
    {
        $app = new FakeApp();

        $report = AppSupport::apply($app, self::has([]));

        $this->assertFalse($app->config()->has('passport.public_key'));
        $this->assertSame([], $report);
    }

    public function testAnAppWithViteGetsTheEmptyAssetHelper(): void
    {
        $app = new FakeApp();

        $report = AppSupport::apply($app, self::has([self::VITE]));

        $this->assertInstanceOf(EmptyVite::class, $app->instances[self::VITE]);
        $this->assertContains('vite_assets', $report);
    }

    public function testAnAppWithoutViteIsNotGivenOne(): void
    {
        $app = new FakeApp();

        AppSupport::apply($app, self::has([]));

        $this->assertSame([], $app->instances);
    }

    public function testTheEmptyAssetHelperAnswersEveryCallWithNothingAndNeverFails(): void
    {
        $vite = new EmptyVite();

        $this->assertSame('', (string) $vite(['resources/js/app.js']));
        $this->assertSame('', (string) $vite);
        $this->assertSame('', $vite->asset('resources/img/a.png'));
        $this->assertSame('', $vite->content('resources/css/a.css'));
        $this->assertSame($vite, $vite->useBuildDirectory('build'));
        $this->assertSame($vite, $vite->useHotFile('x'));
        $this->assertSame($vite, $vite->withEntryPoints(['a']));
        $this->assertSame($vite, $vite->useScriptTagAttributes(['defer' => true]));
        $this->assertSame([], $vite->preloadedAssets());
    }

    public function testNothingTheStepsDoTouchesTheDatabaseOrTheEvents(): void
    {
        $app = new FakeApp();

        AppSupport::apply($app, self::has([self::PASSPORT, self::VITE]));

        $this->assertSame([], array_values(array_diff(array_unique($app->asked), ['config'])));
        $this->assertSame([], $app->services['events']->listeners);
        $this->assertSame(0, $app->services['db']->level);
    }
}
