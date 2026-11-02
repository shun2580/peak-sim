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
            'labelables.labelable_id/labelable_type <- bills(id) ' . Bill::class,
            'locations.locatable_id/locatable_type <- bills(id) ' . Bill::class,
            'taggables.taggable_id/taggable_type <- bills(id) ' . Bill::class,
        ], self::lines($links));
    }

    public function testAToManyRelationAlsoSaysWhichTableItsOtherKeyPointsAt(): void
    {
        $byTable = [];
        foreach (MorphDiscovery::discover([Bill::class]) as $link) {
            $byTable[$link->table] = $link;
        }

        // the pivot has no foreign key in the database, so the relation is the only place that says tag_id is a tags id
        $this->assertSame(['tag_id', 'tags', 'id'], [$byTable['taggables']->relatedColumn, $byTable['taggables']->relatedTable, $byTable['taggables']->relatedKey]);
        // a relation that does not say (or that throws when asked) leaves it out, and the link itself is still found
        $this->assertSame([null, null, null], [$byTable['labelables']->relatedColumn, $byTable['labelables']->relatedTable, $byTable['labelables']->relatedKey]);
        // a one-sided relation has no other key
        $this->assertNull($byTable['attachments']->relatedColumn);
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
        $this->assertCount(4, MorphDiscovery::discover([Bill::class]));
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

    public function testOnlyFilesThatDeclareATypeAreNamedWhenAskedFor(): void
    {
        $dir = sys_get_temp_dir() . '/peak-morph-' . bin2hex(random_bytes(4));
        mkdir($dir . '/Sub', 0777, true);
        $files = [
            '/Alpha.php' => '<?php namespace App; class Alpha {}',
            '/Sub/Beta.php' => "<?php\nnamespace App\\Sub;\nfinal readonly class Beta {}\n",
            '/Sub/Gamma.php' => '<?php interface Gamma {}',
            '/Sub/Delta.php' => '<?php trait Delta {}',
            '/Sub/Epsilon.php' => '<?php enum Epsilon: string { case A = "a"; }',
            // files that declare no type: functions only, a constant, an anonymous class, a ::class reference
            '/helpers.php' => '<?php if (!function_exists("peak_helper")) { function peak_helper(): string { return "x"; } }',
            '/Sub/consts.php' => '<?php return ["a" => 1];',
            '/Sub/Anon.php' => '<?php return new class { public function f(): int { return 1; } };',
            '/Sub/Ref.php' => '<?php return Foo::class;',
            '/Sub/Broken.php' => '<?php class {',
        ];
        foreach ($files as $path => $code) {
            file_put_contents($dir . $path, $code);
        }
        try {
            $this->assertSame(
                ['App\\Models\\Alpha', 'App\\Models\\Sub\\Beta', 'App\\Models\\Sub\\Delta', 'App\\Models\\Sub\\Epsilon', 'App\\Models\\Sub\\Gamma'],
                MorphDiscovery::classNamesIn($dir, 'App\\Models\\', true),
            );
            $this->assertCount(10, MorphDiscovery::classNamesIn($dir, 'App\\Models\\'), 'without the flag every .php file is still named');
        } finally {
            foreach (array_keys($files) as $path) {
                unlink($dir . $path);
            }
            rmdir($dir . '/Sub');
            rmdir($dir);
        }
    }

    /**
     * An app with no `app/Models` has its whole `app/` scanned. A file of plain functions (a helpers file the framework's
     * autoloader already loaded) must not be loaded a second time by the scan: that is a fatal error, which no catch
     * stops. Run in a process of its own so that a regression fails this test and not the whole run.
     */
    public function testAnAppWithoutAModelsDirectoryAndWithAFunctionFileIsScannedWithoutLoadingTheFunctionFile(): void
    {
        $root = sys_get_temp_dir() . '/peak-morph-app-' . bin2hex(random_bytes(4));
        mkdir($root . '/app/App', 0777, true);
        mkdir($root . '/app/Users', 0777, true);
        file_put_contents($root . '/app/App/helpers.php', '<?php namespace Fx\\App; function peak_probe_helper(): string { return "once"; }');
        file_put_contents($root . '/app/Users/Person.php', '<?php namespace Fx\\Users; class Person extends \\Illuminate\\Database\\Eloquent\\Model {}');
        file_put_contents($root . '/app/Users/PlainThing.php', '<?php namespace Fx\\Users; class PlainThing {}');
        $script = $root . '/probe.php';
        file_put_contents($script, '<?php
namespace Illuminate\\Database\\Eloquent { class Model {} }
namespace {
    require ' . var_export(dirname(__DIR__, 3) . '/bootstrap.php', true) . ';
    $root = ' . var_export($root, true) . ';
    // like Composer: PSR-4 for Fx\\ => app/, and the helpers file is loaded up front ("files" autoload)
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, "Fx\\\\")) {
            $file = $root . "/app/" . str_replace("\\\\", "/", substr($class, 3)) . ".php";
            if (is_file($file)) { require $file; }
        }
    });
    require_once $root . "/app/App/helpers.php";
    $app = new class($root) {
        public function __construct(private string $root) {}
        public function path(): string { return $this->root . "/app"; }
        public function getNamespace(): string { return "Fx\\\\"; }
    };
    echo json_encode(\\PeakSim\\Runtime\\Measure\\MorphDiscovery::appModels($app)), "\\n";
}
');
        try {
            $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            $code = proc_close($process);

            $this->assertSame(0, $code, "the scan must not die: $out $err");
            $this->assertSame('["Fx\\\\Users\\\\Person"]', trim($out));
        } finally {
            foreach (['/app/App/helpers.php', '/app/Users/Person.php', '/app/Users/PlainThing.php', '/probe.php'] as $f) {
                unlink($root . $f);
            }
            rmdir($root . '/app/App');
            rmdir($root . '/app/Users');
            rmdir($root . '/app');
            rmdir($root);
        }
    }
}
