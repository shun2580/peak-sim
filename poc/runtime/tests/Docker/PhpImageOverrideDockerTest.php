<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

/**
 * An app that needs PHP extensions the default image lacks: build an image of its own under another name, run the
 * sealed stack in it, and show that (a) the extensions are there, (b) the shared default image is untouched, and
 * (c) the safety settings are the same as with the default image.
 */
final class PhpImageOverrideDockerTest extends StackTestCase
{
    private static string $image = '';

    private static string $sharedBefore = '';

    private static string $buildOut = '';

    private static function imageId(string $name): string
    {
        [$code, $out] = self::exec(['docker', 'image', 'inspect', '--format', '{{.Id}}', $name]);

        return $code === 0 ? trim($out) : '';
    }

    public static function setUpBeforeClass(): void
    {
        self::requireDocker();
        $version = (string) (getenv('PHP_VERSION') ?: '8.3');
        self::$image = 'peak-measure-php-t' . self::randomHex(4) . ':' . $version;
        self::$sharedBefore = self::imageId('peak-measure-php:' . $version);

        $build = self::bin('stack', ['build-image', self::$image], ['PEAK_PHP_EXTENSIONS' => 'gd,xsl', 'PHP_VERSION' => $version]);
        self::$buildOut = self::transcript('bin/stack build-image ' . self::$image, [$build[0], substr($build[1], -2000), substr($build[2], -2000)]);
        self::assertSame(0, $build[0], "build-image failed:\n" . self::$buildOut);

        self::bringUp('-img', 'measure.compose.yaml', ['PEAK_PHP_IMAGE' => self::$image]);
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownStack();
        if (self::$image !== '') {
            self::exec(['docker', 'rmi', self::$image]);
        }
    }

    public function testTheContainerRunsInTheOverrideImageAndHasTheExtensions(): void
    {
        $ids = self::exec(['docker', 'ps', '-q', '--filter', 'label=com.docker.compose.project=' . self::$project, '--filter', 'label=com.docker.compose.service=measure-php']);
        $container = trim($ids[1]);
        $this->assertNotSame('', $container);
        $image = self::exec(['docker', 'inspect', '--format', '{{.Config.Image}}', $container]);
        $this->assertSame(self::$image, trim($image[1]));

        $modules = self::exec(['docker', 'compose', '-p', self::$project, '-f', self::runtimeDir() . '/compose/' . self::$composeFile, 'exec', '-T', 'measure-php', 'php', '-r', 'echo json_encode([extension_loaded("gd"), extension_loaded("xsl"), extension_loaded("pdo_mysql"), extension_loaded("pcntl")]);']);
        $this->assertSame('[true,true,true,true]', trim($modules[1]), 'gd and xsl are added; the default set is still there');
        self::evidence('image-override', 'extensions.txt', self::$buildOut . "\n" . self::transcript('php -r extension_loaded gd,xsl,pdo_mysql,pcntl', $modules));
    }

    public function testTheSharedDefaultImageIsUntouched(): void
    {
        $version = (string) (getenv('PHP_VERSION') ?: '8.3');
        $this->assertSame(self::$sharedBefore, self::imageId('peak-measure-php:' . $version), 'building and running the override must not change the shared tag');
    }

    public function testTheSafetySettingsAreTheSameInTheOverrideImage(): void
    {
        $ids = self::exec(['docker', 'ps', '-q', '--filter', 'label=com.docker.compose.project=' . self::$project, '--filter', 'label=com.docker.compose.service=measure-php']);
        $container = trim($ids[1]);
        $inspect = self::exec(['docker', 'inspect', '--format', 'ro={{.HostConfig.ReadonlyRootfs}} caps={{.HostConfig.CapDrop}} sec={{.HostConfig.SecurityOpt}} user={{.Config.User}} ports={{len .HostConfig.PortBindings}} priv={{.HostConfig.Privileged}}', $container]);
        $this->assertSame('ro=true caps=[ALL] sec=[no-new-privileges:true] user=1000:1000 ports=0 priv=false', trim($inspect[1]));

        $env = self::exec(['docker', 'inspect', '--format', '{{range .Config.Env}}{{println .}}{{end}}', $container]);
        $this->assertStringNotContainsString('ROOT_PASSWORD', $env[1], 'the root password never reaches the PHP container');

        $preflight = self::bin('preflight');
        $this->assertSame(0, $preflight[0], $preflight[1] . $preflight[2]);
        $this->assertStringContainsString('PREFLIGHT OK', $preflight[1]);
        $this->assertStringContainsString('internal=true', $preflight[1]);
        $this->assertSame(0, substr_count($preflight[1], 'FAIL '));
        self::evidence('image-override', 'safety-and-preflight.txt', self::transcript('docker inspect (php container)', $inspect) . "\n" . self::transcript('bin/preflight', $preflight));
    }

    public function testAnOverrideThatIsNotOnThisMachineIsRefusedAndNothingIsPulledOrStarted(): void
    {
        $missing = 'peak-measure-php-nope' . self::randomHex(4) . ':8.3';
        $result = self::exec(['bash', self::runtimeDir() . '/bin/stack', 'up'], [
            'PEAK_PHP_IMAGE' => $missing, 'PEAK_COMPOSE_PROJECT' => self::$project . '-miss',
        ]);

        $this->assertNotSame(0, $result[0]);
        $this->assertStringContainsString('build-image', $result[2]);
        $containers = self::exec(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project=' . self::$project . '-miss']);
        $this->assertSame('', trim($containers[1]), 'no container is created for a missing image');
        $this->assertSame('', self::imageId($missing));
    }
}
