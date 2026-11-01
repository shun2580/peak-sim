<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Boot;

use PeakSim\Runtime\Boot\ScratchDir;
use PHPUnit\Framework\TestCase;

final class ScratchDirTest extends TestCase
{
    public function testCreatesTheDirectoriesLaravelNeeds(): void
    {
        $dir = ScratchDir::create('runidabc');
        try {
            foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/public'] as $sub) {
                self::assertDirectoryExists($dir->storagePath() . '/' . $sub);
            }
            self::assertDirectoryExists($dir->viewsPath());
        } finally {
            $dir->remove();
        }
    }

    public function testRemoveDeletesEverythingBelowIt(): void
    {
        $dir = ScratchDir::create('runidabc');
        file_put_contents($dir->storagePath() . '/logs/x.log', 'x');
        file_put_contents($dir->viewsPath() . '/compiled.php', '<?php');
        $dir->remove();
        self::assertDirectoryDoesNotExist($dir->path);
    }

    public function testTwoDirectoriesNeverShareAPath(): void
    {
        $a = ScratchDir::create('runidabc');
        $b = ScratchDir::create('runidabc');
        try {
            self::assertNotSame($a->path, $b->path);
        } finally {
            $a->remove();
            $b->remove();
        }
    }

    public function testRemoveLeavesAFolderThatIsNotItsOwnAlone(): void
    {
        $other = sys_get_temp_dir() . '/not-a-scratch-dir-' . bin2hex(random_bytes(3));
        mkdir($other);
        file_put_contents($other . '/keep.txt', 'keep');
        try {
            // A ScratchDir cannot be built for an arbitrary path (private constructor), so go through reflection
            // to prove that remove() itself checks the prefix.
            $ctor = (new \ReflectionClass(ScratchDir::class))->getConstructor();
            $scratch = (new \ReflectionClass(ScratchDir::class))->newInstanceWithoutConstructor();
            $ctor->setAccessible(true);
            $ctor->invoke($scratch, $other);
            $scratch->remove();
            self::assertFileExists($other . '/keep.txt');
        } finally {
            @unlink($other . '/keep.txt');
            @rmdir($other);
        }
    }
}
