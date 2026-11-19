<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\Options;
use PeakSim\Runtime\Measure\Profile;
use PeakSim\Runtime\Simulate\Predict\PredictionReport;
use PHPUnit\Framework\TestCase;

/**
 * A database is built, then ANALYZEd where a tool asks for it, and never has the app's own rebuild of a table run on it
 * (decisions.md D229 and D230: the lab's generated bodies hold no links to each other, so a rebuild would empty `references`
 * at every count and every route that reads it would stop depending on the count). These tests try that without a database:
 * what the profile declares, what a profile may declare, which files could start a rebuild, and what the output note says.
 */
final class BuildHasNoRebuildTest extends TestCase
{
    public function test_the_bookstack_profile_declares_no_rebuild_and_the_two_routes_that_rewrite_a_table_come_last(): void
    {
        $profile = Profile::load('bookstack');

        $this->assertArrayNotHasKey('rebuild', $profile);
        $this->assertSame(
            ['PUT /books/{slug}', 'POST /settings/maintenance/regenerate-references'],
            array_column($profile['state_changing'], 'route'),
            'the routes that change the state of a table other routes read, in the order they are measured: after every other route',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function everyProfile(): iterable
    {
        foreach (['fixtures', 'firefly', 'koel', 'bookstack'] as $name) {
            yield $name => [$name];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyProfile')]
    public function test_no_profile_has_a_rebuild_so_no_build_runs_one(string $name): void
    {
        $this->assertArrayNotHasKey('rebuild', Profile::load($name));
    }

    public function test_a_profile_that_declares_a_rebuild_is_refused_because_nothing_reads_that_key(): void
    {
        $dir = sys_get_temp_dir() . '/peak-no-rebuild-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/bad.php', "<?php return ['rebuild' => [['name' => 'n', 'command' => 'a:b', 'tables' => ['t'], 'why' => 'w']]];");

        try {
            Profile::load('bad', $dir);
            $this->fail('a profile with a rebuild was accepted');
        } catch (BuildFailure $e) {
            $this->assertStringContainsString('a key nothing reads: rebuild', $e->getMessage());
        } finally {
            unlink($dir . '/bad.php');
            rmdir($dir);
        }
    }

    public function test_a_capture_of_the_bookstack_profile_is_not_refused_for_asking_nothing_about_analyze(): void
    {
        $this->assertFalse(class_exists('PeakSim\\Runtime\\Measure\\RebuildStep'));
        $this->assertFalse(method_exists(Options::class, 'refusalForProfile'));
        $options = Options::parse(['--tap-dir=/out/capture-taps', '--profile=bookstack']);

        $this->assertSame('bookstack', $options->profile);
        $this->assertFalse($options->analyzeAfterBuild, 'the default of a capture is as it was: ANALYZE only when it is asked for');
    }

    /** @return list<string> the files that build a database or start one, relative to the repository root */
    private static function buildFiles(): array
    {
        $root = dirname(__DIR__, 5);
        $files = [];
        foreach (['poc/lab/bin', 'poc/runtime/bin', 'poc/runtime/src/Measure', 'poc/runtime/src/Capture'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($files);

        return $files;
    }

    /** Every script and class that builds a database or starts a build is read: none names the app's rebuild command or the step that ran it. */
    public function test_no_script_or_class_that_builds_a_database_starts_the_apps_rebuild(): void
    {
        $root = dirname(__DIR__, 5);
        $files = self::buildFiles();
        $this->assertContains('poc/lab/bin/build-db', $files);
        $this->assertContains('poc/runtime/bin/capture', $files);
        $this->assertContains('poc/runtime/bin/inner/build-db.php', $files);
        $this->assertContains('poc/runtime/bin/measure', $files);
        $this->assertContains('poc/runtime/bin/variance-run', $files);
        $this->assertContains('poc/runtime/bin/explain-at-scale', $files);
        $this->assertContains('poc/runtime/src/Measure/DatabaseBuild.php', $files);
        $this->assertContains('poc/lab/bin/measure-extrapolation', $files);

        $callers = [];
        foreach ($files as $file) {
            $text = (string) file_get_contents($root . '/' . $file);
            foreach (['regenerate-references', 'regenerate_references', 'RebuildStep', 'refusalForProfile', "\$info['rebuild']", "['rebuild']"] as $needle) {
                if (str_contains($text, $needle)) {
                    $callers[] = $file . ' names ' . $needle;
                }
            }
        }

        $this->assertSame([], $callers, 'the files that could start the rebuild of a table');
    }

    public function test_the_output_note_says_the_two_databases_must_be_in_the_same_maintenance_state_without_asking_for_a_rebuild(): void
    {
        $note = PredictionReport::ESTIMATE_BASIS_NOTE;

        $this->assertStringContainsString('same maintenance state', $note);
        $this->assertStringContainsString('captured on', $note);
        $this->assertStringContainsString('on both or on neither', $note);
        $this->assertStringContainsString('ANALYZE TABLE', $note);
        $this->assertStringNotContainsString('finished', $note, 'the sentence no longer asks for the rebuild processes to have finished');
    }
}
