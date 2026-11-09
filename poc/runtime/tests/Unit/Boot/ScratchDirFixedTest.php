<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Boot;

use PeakSim\Runtime\Boot\BootFailure;
use PeakSim\Runtime\Boot\ScratchDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `ScratchDir::fixed()`: the directory the lab's php-fpm workers share. */
final class ScratchDirFixedTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/scratch-fixed-test-' . bin2hex(random_bytes(3));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        $dir = $this->base . '/peak-measure-lab';
        if (is_dir($dir)) {
            (ScratchDir::fixed($dir))->remove();
        }
        @rmdir($this->base);
    }

    public function testTwoCallsGiveTheSamePathAndTheSameDirectories(): void
    {
        $path = $this->base . '/peak-measure-lab';
        $a = ScratchDir::fixed($path);
        file_put_contents($a->viewsPath() . '/compiled.php', '<?php');
        $b = ScratchDir::fixed($path);

        self::assertSame($a->path, $b->path);
        self::assertFileExists($b->viewsPath() . '/compiled.php', 'a second call keeps what the first left (the compiled views)');
        foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/public'] as $sub) {
            self::assertDirectoryExists($b->storagePath() . '/' . $sub);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function refusedPaths(): iterable
    {
        yield 'relative' => ['peak-measure-lab'];
        yield 'dot dot' => ['/tmp/../peak-measure-lab'];
        yield 'last part without the prefix' => ['/tmp/lab'];
        yield 'the prefix only in a parent' => ['/tmp/peak-measure-x/lab'];
    }

    #[DataProvider('refusedPaths')]
    public function testRefusesAPathThatIsNotOneOfItsOwn(string $path): void
    {
        $this->expectException(BootFailure::class);
        ScratchDir::fixed($path);
    }

    public function testRemoveStillWorksOnAFixedDirectory(): void
    {
        $dir = ScratchDir::fixed($this->base . '/peak-measure-lab');
        file_put_contents($dir->storagePath() . '/logs/x.log', 'x');
        $dir->remove();
        self::assertDirectoryDoesNotExist($dir->path);
    }
}
