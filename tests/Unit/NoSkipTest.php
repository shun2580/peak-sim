<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SKIP = FAIL. A test that can be skipped or left incomplete hides a gap, so none may exist in src/ or tests/.
 * Checks that need a real artisan (PHP 8.3) live in phpunit.live.xml, not behind a skip.
 */
final class NoSkipTest extends TestCase
{
    public function testNoSourceOrTestFileCanSkipOrMarkIncomplete(): void
    {
        $root = dirname(__DIR__, 2);
        $self = realpath(__FILE__);
        $found = [];
        $scanned = 0;

        foreach (['src', 'tests'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || $file->getRealPath() === $self) {
                    continue;
                }
                $scanned++;
                $text = (string) file_get_contents($file->getPathname());
                foreach (self::forbidden() as $needle) {
                    if (str_contains($text, $needle)) {
                        $found[] = substr($file->getPathname(), strlen($root) + 1) . ' contains ' . $needle;
                    }
                }
            }
        }

        self::assertGreaterThan(0, $scanned, 'the scan must see files');
        self::assertSame([], $found);
    }

    /**
     * Built from pieces so this file does not spell out the words it forbids.
     *
     * @return list<string>
     */
    private static function forbidden(): array
    {
        return [
            'markTest' . 'Skipped',
            'markTest' . 'Incomplete',
            '@' . 'requires',
            '#[' . 'Requires',
        ];
    }
}
