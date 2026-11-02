<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Data\MorphLink;
use PeakSim\Runtime\Measure\MorphDiscovery;
use PeakSim\Runtime\Tests\Stubs\Models\Bill;
use PeakSim\Runtime\Tests\Stubs\Models\Note;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/MorphModels.php';

final class MorphDiscoveryTest extends TestCase
{
    /** @param list<MorphLink> $links @return list<string> */
    private static function lines(array $links): array
    {
        $out = array_map(static fn (MorphLink $l): string => sprintf('%s.%s/%s <- %s(%s) %s', $l->table, $l->idColumn, $l->typeColumn, $l->parentTable, $l->parentKey, $l->typeValue), $links);
        sort($out);

        return $out;
    }

    public function testAMorphManyOneAndToManyEachBecomeALinkFromTheChildColumnsToTheParent(): void
    {
        $links = MorphDiscovery::discover([Bill::class]);

        $this->assertSame([
            'attachments.attachable_id/attachable_type <- bills(id) ' . Bill::class,
            'locations.locatable_id/locatable_type <- bills(id) ' . Bill::class,
            'taggables.taggable_id/taggable_type <- bills(id) ' . Bill::class,
        ], self::lines($links));
    }

    public function testTheSameChildTableCanBelongToSeveralParentTypes(): void
    {
        $links = MorphDiscovery::discover([Bill::class, Note::class]);

        $attachments = array_values(array_filter(self::lines($links), static fn (string $l): bool => str_starts_with($l, 'attachments.')));
        $this->assertSame([
            'attachments.attachable_id/attachable_type <- bills(id) ' . Bill::class,
            'attachments.attachable_id/attachable_type <- notes(id) ' . Note::class,
        ], $attachments);
    }

    public function testAMethodThatNeedsAnArgumentThatIsStaticOrThatThrowsIsLeftAlone(): void
    {
        // Bill also has plain() (not a morph), withArgument($x), broken() and a static make(): none adds a link and
        // none stops the others.
        $this->assertCount(3, MorphDiscovery::discover([Bill::class]));
    }

    public function testAClassThatIsNotAModelOrNotThereIsLeftAlone(): void
    {
        $this->assertSame([], MorphDiscovery::discover(['No\\Such\\Model', \stdClass::class]));
    }

    public function testTheResultIsTheSameForTheSameModelsInAnyOrder(): void
    {
        $this->assertEquals(MorphDiscovery::discover([Bill::class, Note::class]), MorphDiscovery::discover([Note::class, Bill::class]));
    }

    public function testClassesInADirectoryAreNamedFromTheirPaths(): void
    {
        $dir = sys_get_temp_dir() . '/peak-morph-' . bin2hex(random_bytes(4));
        mkdir($dir . '/Sub', 0777, true);
        file_put_contents($dir . '/Alpha.php', '<?php');
        file_put_contents($dir . '/Sub/Beta.php', '<?php');
        file_put_contents($dir . '/notes.txt', '');
        try {
            $this->assertSame(['App\\Models\\Alpha', 'App\\Models\\Sub\\Beta'], MorphDiscovery::classNamesIn($dir, 'App\\Models\\'));
        } finally {
            unlink($dir . '/Alpha.php');
            unlink($dir . '/Sub/Beta.php');
            unlink($dir . '/notes.txt');
            rmdir($dir . '/Sub');
            rmdir($dir);
        }
    }
}
