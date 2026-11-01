<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker\Data;

use PeakSim\Runtime\Tests\Docker\Data\Support\GenerationCase;
use PeakSim\Runtime\Tests\Docker\Support\ProbeApp;
use PHPUnit\Framework\Attributes\Group;

/**
 * The profile reaching the inspection (the step of a run that turns every route into a task), on the verification app
 * and a real MySQL: an argument the tool's own rules cannot fill is filled from the profile, one the profile explains
 * is recorded with its reason, the profile's admin is the login, and a run without a profile is unchanged.
 * (The profile is read from a test directory here; `inspect.php --profile=` reads the named file of profiles/.)
 */
#[Group('in-stack')]
final class ProfileInspectionDockerTest extends GenerationCase
{
    private const SCALE = 100;

    private static ?ProbeApp $probe = null;

    private static string $analysis = '';

    public static function setUpBeforeClass(): void
    {
        self::requireInsideStack();
        self::$probe = ProbeApp::create('/app', 'inspect');
        self::$analysis = sys_get_temp_dir() . '/peak-inspect-analysis-' . getmypid() . '.json';
        file_put_contents(self::$analysis, json_encode(['routes' => [
            self::route('/probe/songs/{song}'),
            self::route('/probe/dropbox/{key}'),
            self::route('/probe/things/{thing}'),
            self::route('/probe/signed/{token}'),
            self::route('/probe/numbers/{n}', 'PeakProbe\\NumberController@show'),
            self::route('/probe/missing'),
        ]], JSON_THROW_ON_ERROR));
        [$exit, , $err] = self::php(__DIR__ . '/Support/build_with_profile.php', [(string) self::SCALE], self::env());
        self::assertSame(0, $exit, $err);
    }

    public static function tearDownAfterClass(): void
    {
        self::drop(self::SCALE);
        self::$probe?->remove();
        @unlink(self::$analysis);
    }

    /** @return array<string, string> */
    private static function env(): array
    {
        return ['PEAK_MEASURE_APP_ROOT' => self::$probe->root, 'PEAK_MEASURE_STATIC_JSON' => self::$analysis];
    }

    /** @return array<string, mixed> */
    private static function route(string $uri, string $action = 'Closure'): array
    {
        return [
            'route' => $uri, 'methods' => ['GET', 'HEAD'], 'action' => $action, 'kind' => 'controller',
            'query' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'memory' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'n_table' => null, 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => [],
        ];
    }

    /** @return array<string, array<string, mixed>> the tasks by route */
    private static function inspect(?string $profile): array
    {
        [$exit, $out, $err] = self::php(__DIR__ . '/Support/inspect_with_profile.php', array_filter([(string) self::SCALE, $profile]), self::env());
        self::assertSame(0, $exit, $err . $out);
        $result = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);

        $byRoute = [];
        foreach ($result['tasks'] as $task) {
            $byRoute[$task['spec']['uri']] = $task;
        }

        return $byRoute;
    }

    public function testAnArgumentTheProfileNamesIsFilledFromTheFirstRowOfItsColumn(): void
    {
        $email = (string) self::scalar(self::pdo(self::SCALE), 'SELECT email FROM customers ORDER BY id LIMIT 1');

        $tasks = self::inspect('routes');

        self::assertNull($tasks['/probe/songs/{song}']['fail_class']);
        self::assertSame('/probe/songs/' . rawurlencode($email), $tasks['/probe/songs/{song}']['uri']);
    }

    public function testAnArgumentTheProfileExplainsIsRecordedWithItsReasonAndAnotherOneStaysUnfilled(): void
    {
        $tasks = self::inspect('routes');

        self::assertSame('external_call_blocked', $tasks['/probe/dropbox/{key}']['fail_class']);
        self::assertStringContainsString('dropbox_key', (string) $tasks['/probe/dropbox/{key}']['fail_detail']);
        self::assertNull($tasks['/probe/dropbox/{key}']['uri']);
        self::assertSame('unfilled_param', $tasks['/probe/things/{thing}']['fail_class']);
    }

    public function testWithoutAProfileNoneOfThemIsFilled(): void
    {
        $tasks = self::inspect(null);

        foreach (['/probe/songs/{song}', '/probe/dropbox/{key}', '/probe/things/{thing}'] as $route) {
            self::assertSame('unfilled_param', $tasks[$route]['fail_class'], $route);
            self::assertNull($tasks[$route]['uri'], $route);
        }
        self::assertNull($tasks['/probe/missing']['fail_class'], 'a route with no parameter is prepared as before');
        self::assertSame('/probe/missing', $tasks['/probe/missing']['uri']);
    }

    public function testTheLoginIsTheRowOfTheKeyTheProfileNamesOrTheFirstRowWithoutOne(): void
    {
        foreach ([[null, 'App\\Models\\Customer:1'], [2, 'App\\Models\\Customer:2'], [987654, null]] as [$id, $expected]) {
            [$exit, $out, $err] = self::php(__DIR__ . '/Support/login_user.php', array_filter([(string) self::SCALE, $id === null ? null : (string) $id], static fn ($a) => $a !== null), self::env());

            self::assertSame(0, $exit, $err . $out);
            self::assertSame(['user' => $expected], json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR), 'id ' . var_export($id, true));
        }
    }

    public function testTheInspectionScriptReadsTheNamedProfileAndSaysWhichOneItUsed(): void
    {
        [$exit, $out, $err] = self::php(self::RUNTIME . '/bin/inner/inspect.php', ['--scale=' . self::SCALE, '--profile=fixtures'], self::env());
        self::assertSame(0, $exit, $err);
        self::assertSame('fixtures', json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR)['profile']);

        [$exit, $out, $err] = self::php(self::RUNTIME . '/bin/inner/inspect.php', ['--scale=' . self::SCALE], self::env());
        self::assertSame(0, $exit, $err);
        self::assertNull(json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR)['profile']);
    }

    public function testTheInspectionScriptStopsOnAProfileThatDoesNotExist(): void
    {
        [$exit, , $err] = self::php(self::RUNTIME . '/bin/inner/inspect.php', ['--scale=' . self::SCALE, '--profile=ghost'], self::env());

        self::assertSame(13, $exit);
        self::assertStringContainsString('profile "ghost" does not exist', $err);
    }

    public function testARouteTheProfileExplainsAsAWholeIsRecordedWithItsClassWithoutFillingAnything(): void
    {
        $tasks = self::inspect('routes');

        self::assertSame('signature_required', $tasks['/probe/signed/{token}']['fail_class']);
        self::assertStringContainsString('signed_links', (string) $tasks['/probe/signed/{token}']['fail_detail']);
        self::assertNull($tasks['/probe/signed/{token}']['uri']);
        self::assertSame('unfilled_param', self::inspect(null)['/probe/signed/{token}']['fail_class'], 'without the profile it is an argument nothing could fill');
    }

    public function testAnArgumentTheToolMadeUpIsMarkedAsAGuessAndOneTakenFromARowIsNot(): void
    {
        $tasks = self::inspect('routes');

        self::assertSame('/probe/numbers/1', $tasks['/probe/numbers/{n}']['uri']);
        self::assertSame(['n'], $tasks['/probe/numbers/{n}']['guessed']);
        self::assertSame([], $tasks['/probe/songs/{song}']['guessed']);
    }
}
