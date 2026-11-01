<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Compose;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The way to run the measurement in a different PHP image (an app that needs extensions the default image lacks), and
 * the way to build such an image. No Docker needed: `docker` is a stand-in that records what it was asked to do.
 *
 * What must hold: the default is as before; an override never builds (so it cannot overwrite any image); building
 * refuses the shared tag; and the safety settings of the compose do not depend on the image.
 */
final class PhpImageOverrideTest extends TestCase
{
    private string $dir = '';

    private string $log = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-img-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0777, true);
        $this->log = $this->dir . '/docker.log';
        // The stand-in records every call. `docker image inspect <name>` succeeds when FAKE_IMAGE_EXISTS=1.
        file_put_contents($this->dir . '/bin/docker', <<<'SH'
            #!/usr/bin/env bash
            printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"
            if [ "${1:-}" = "image" ] && [ "${2:-}" = "inspect" ]; then
              [ "${FAKE_IMAGE_EXISTS:-0}" = "1" ] && exit 0
              echo "Error: No such image: ${3:-}" >&2
              exit 1
            fi
            exit 0
            SH);
        chmod($this->dir . '/bin/docker', 0755);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/bin/*') ?: [] as $file) {
            unlink($file);
        }
        @unlink($this->log);
        @rmdir($this->dir . '/bin');
        @rmdir($this->dir);
    }

    private static function runtimeDir(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function stack(array $args, array $env = []): array
    {
        $process = proc_open(['bash', self::runtimeDir() . '/bin/stack', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge([
            'PATH' => $this->dir . '/bin:/usr/bin:/bin',
            'HOME' => $this->dir,
            'PEAK_OUT_DIR' => $this->dir . '/out',
            'FAKE_DOCKER_LOG' => $this->log,
        ], $env));
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /** @return list<string> */
    private function calls(): array
    {
        return is_file($this->log) ? array_values(array_filter(explode("\n", (string) file_get_contents($this->log)))) : [];
    }

    /** @return list<string> */
    private function callsStarting(string $word): array
    {
        return array_values(array_filter($this->calls(), static fn (string $c): bool => str_starts_with($c, $word)));
    }

    public function testWithoutAnOverrideTheStackIsBuiltAndBroughtUpAsBefore(): void
    {
        [$code] = $this->stack(['up']);

        $this->assertSame(0, $code);
        $up = $this->callsStarting('compose');
        $this->assertCount(1, $up);
        $this->assertStringContainsString('up -d --build --wait', $up[0]);
        $this->assertStringNotContainsString('--no-build', $up[0]);
        $this->assertSame([], $this->callsStarting('image'), 'no image check without an override');
        $this->assertSame([], $this->callsStarting('build'));
    }

    public function testWithAnOverrideTheStackIsBroughtUpWithoutBuildingAnything(): void
    {
        [$code] = $this->stack(['up'], ['PEAK_PHP_IMAGE' => 'peak-measure-php-koel:8.3', 'FAKE_IMAGE_EXISTS' => '1']);

        $this->assertSame(0, $code);
        $up = $this->callsStarting('compose');
        $this->assertCount(1, $up);
        $this->assertStringContainsString('--no-build', $up[0]);
        $this->assertStringNotContainsString('--build ', $up[0] . ' ', 'an override must never build: building would overwrite the image of that name');
        $this->assertContains('image inspect peak-measure-php-koel:8.3', $this->calls());
    }

    public function testAnOverrideThatDoesNotExistLocallyStopsBeforeAnythingIsStartedOrPulled(): void
    {
        [$code, , $err] = $this->stack(['up'], ['PEAK_PHP_IMAGE' => 'peak-measure-php-koel:8.3', 'FAKE_IMAGE_EXISTS' => '0']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('peak-measure-php-koel:8.3', $err);
        $this->assertStringContainsString('build-image', $err, 'the message says how to make the image');
        $this->assertSame([], $this->callsStarting('compose'), 'nothing is started when the image is missing');
        $this->assertSame([], $this->callsStarting('pull'));
    }

    public function testTheOverrideAppliesToAnyUpNotOnlyToBinStack(): void
    {
        // bin/measure brings the stack up through the same helper; its `up -d --build --wait` must not build either.
        $script = 'source ' . escapeshellarg(self::runtimeDir() . '/bin/_stack.sh') . '; compose up -d --build --wait';
        $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'PATH' => $this->dir . '/bin:/usr/bin:/bin', 'HOME' => $this->dir, 'PEAK_OUT_DIR' => $this->dir . '/out',
            'FAKE_DOCKER_LOG' => $this->log, 'PEAK_PHP_IMAGE' => 'peak-measure-php-koel:8.3', 'FAKE_IMAGE_EXISTS' => '1',
        ]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $code = proc_close($process);

        $this->assertSame(0, $code);
        $up = $this->callsStarting('compose');
        $this->assertCount(1, $up);
        $this->assertStringContainsString('--no-build', $up[0]);
        $this->assertStringNotContainsString(' --build', $up[0]);
    }

    public function testBuildImageBuildsUnderTheGivenNameWithTheGivenExtensions(): void
    {
        [$code, $out] = $this->stack(['build-image', 'peak-measure-php-koel:8.3'], ['PEAK_PHP_EXTENSIONS' => 'gd,xsl']);

        $this->assertSame(0, $code, $out);
        $builds = $this->callsStarting('build');
        $this->assertCount(1, $builds);
        $this->assertStringContainsString('-t peak-measure-php-koel:8.3', $builds[0]);
        $this->assertStringContainsString('--build-arg PHP_EXTENSIONS=gd,xsl', $builds[0]);
        $this->assertStringContainsString('--build-arg PHP_VERSION=8.3', $builds[0]);
        $this->assertStringContainsString('-f ' . self::runtimeDir() . '/compose/Dockerfile.measure', $builds[0]);
    }

    public function testBuildImageUsesTheRequestedPhpVersion(): void
    {
        [$code] = $this->stack(['build-image', 'peak-measure-php-koel:8.2'], ['PEAK_PHP_EXTENSIONS' => 'gd', 'PHP_VERSION' => '8.2']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('--build-arg PHP_VERSION=8.2', $this->callsStarting('build')[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedNames(): iterable
    {
        yield 'the shared tag' => ['peak-measure-php:8.3'];
        yield 'the other shared tag' => ['peak-measure-php:8.2'];
        yield 'the shared repository with another tag' => ['peak-measure-php:koel'];
        yield 'the shared repository without a tag' => ['peak-measure-php'];
        yield 'a base image' => ['php:8.3-cli'];
        yield 'the DB image' => ['mysql:8.4.7'];
        yield 'another trial image' => ['peak-trial-koel-php:8.3'];
        yield 'a registry path' => ['docker.io/library/php:8.3'];
        yield 'with a space' => ['peak-measure-php-koel :8.3'];
        yield 'with a flag in front' => ['--network=host'];
        yield 'empty' => [''];
    }

    #[DataProvider('refusedNames')]
    public function testBuildImageRefusesNamesThatCouldOverwriteAnotherImage(string $name): void
    {
        [$code, , $err] = $this->stack(['build-image', $name], ['PEAK_PHP_EXTENSIONS' => 'gd']);

        $this->assertSame(2, $code, $err);
        $this->assertSame([], $this->callsStarting('build'), 'nothing is built under a refused name');
    }

    public function testBuildImageNeedsAName(): void
    {
        [$code] = $this->stack(['build-image'], ['PEAK_PHP_EXTENSIONS' => 'gd']);

        $this->assertSame(2, $code);
        $this->assertSame([], $this->callsStarting('build'));
    }

    /** @return iterable<string, array{string}> */
    public static function badExtensionLists(): iterable
    {
        yield 'a command' => ['gd; rm -rf /tmp/x'];
        yield 'a substitution' => ['$(id)'];
        yield 'a space' => ['gd xsl'];
        yield 'a trailing comma' => ['gd,'];
        yield 'upper case' => ['GD'];
        yield 'a path' => ['../gd'];
    }

    #[DataProvider('badExtensionLists')]
    public function testBuildImageRefusesAnExtensionListThatIsNotPlainNames(string $list): void
    {
        [$code] = $this->stack(['build-image', 'peak-measure-php-koel:8.3'], ['PEAK_PHP_EXTENSIONS' => $list]);

        $this->assertSame(2, $code, 'the list goes into a RUN line, so only plain names are accepted');
        $this->assertSame([], $this->callsStarting('build'));
    }

    public function testBuildImageNeverTouchesTheShareTagEvenWhenTheOverrideVariableNamesIt(): void
    {
        [$code] = $this->stack(['build-image', 'peak-measure-php-koel:8.3'], ['PEAK_PHP_EXTENSIONS' => 'gd', 'PEAK_PHP_IMAGE' => 'peak-measure-php:8.3']);

        $this->assertSame(0, $code);
        foreach ($this->calls() as $call) {
            $this->assertStringNotContainsString('-t peak-measure-php:8.3', $call);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function composeFiles(): iterable
    {
        yield 'sealed' => ['measure.compose.yaml'];
        yield 'leaky (test only)' => ['measure.leaky.compose.yaml'];
    }

    #[DataProvider('composeFiles')]
    public function testTheComposeTakesTheImageFromTheOverrideAndKeepsTheSharedDefault(string $file): void
    {
        $text = (string) file_get_contents(self::runtimeDir() . '/compose/' . $file);

        $this->assertStringContainsString('image: ${PEAK_PHP_IMAGE:-peak-measure-php:${PHP_VERSION:-8.3}}', $text);
    }

    public function testTheSealedComposeStillHoldsEverySafetySettingWhateverTheImage(): void
    {
        $lines = array_map('trim', explode("\n", (string) file_get_contents(self::runtimeDir() . '/compose/measure.compose.yaml')));
        $code = array_values(array_filter($lines, static fn (string $l): bool => $l !== '' && $l[0] !== '#'));

        $this->assertContains('internal: true', $code);
        $this->assertContains('read_only: true', $code);
        $this->assertContains('cap_drop: [ALL]', $code);
        $this->assertContains('security_opt: ["no-new-privileges:true"]', $code);
        $this->assertContains('user: "1000:1000"', $code);
        $this->assertContains('- ${PEAK_REPO_ROOT:?set by bin/_stack.sh}:/peak-sim:ro', $code);
        foreach ($code as $line) {
            $this->assertStringStartsNotWith('ports:', $line, 'no port may be published');
            $this->assertStringStartsNotWith('privileged:', $line);
            $this->assertStringStartsNotWith('network_mode:', $line);
            $this->assertStringStartsNotWith('entrypoint:', $line, 'the image must not be able to change what the container starts');
        }
        $this->assertContains('PEAK_MEASURE_DB_PASSWORD: ${PEAK_MEASURE_DB_PASSWORD:?set by bin/_stack.sh}', $code);
        foreach ($code as $line) {
            $this->assertStringNotContainsString('ROOT_PASSWORD', str_contains($line, 'measure-php') ? $line : '');
        }
    }

    public function testTheDockerfileKeepsTheDefaultBuildAndAddsExtensionsOnlyWhenAsked(): void
    {
        $text = (string) file_get_contents(self::runtimeDir() . '/compose/Dockerfile.measure');

        $this->assertStringContainsString('ARG PHP_EXTENSIONS=""', $text);
        $this->assertStringContainsString('docker-php-ext-install pdo_mysql zip bcmath intl exif pcntl', $text, 'the default set is unchanged');
        // gd and xsl are the two the first app needed; each brings its libraries from the Debian mirror of the base image.
        $this->assertStringContainsString('libxslt1-dev', $text);
        $this->assertStringContainsString('libpng-dev', $text);
        $this->assertStringContainsString('docker-php-ext-configure gd', $text);
        $this->assertStringNotContainsString('curl ', str_replace('default_socket_timeout', '', $text), 'nothing is fetched from outside the base image and its apt source');
        $this->assertStringNotContainsString('install-php-extensions', $text);
    }
}
