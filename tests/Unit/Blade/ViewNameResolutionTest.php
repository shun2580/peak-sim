<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Blade;

use PeakSim\Analysis\Blade\ViewHandlerParents;
use PeakSim\Analysis\Blade\ViewHandlerTemplates;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;
use PeakSim\Source\AppSourceIndex;
use PeakSim\Tests\Unit\Engine\Support\TempApp;

/**
 * Which file a view name points at. In Laravel a "/" in a view name is as valid as a "." (`view('locations/print')`
 * is resources/views/locations/print.blade.php); what must never be read is anything outside the view directory:
 * a ".." step, an absolute path, a package-qualified name, a NUL byte, or a symbolic link leading out.
 */
final class ViewNameResolutionTest extends ViewHandlerCase
{
    private const LOOP_ROW = "@foreach (\$orders as \$order)\n    <p>{{ \$order->customer->name }}</p>\n@endforeach";

    /** @param array<string, string> $files */
    private function locate(string $name, array $files, ?string $viewPath = null): ?string
    {
        $app = new TempApp($files);

        return (new ViewHandlerTemplates($app->root, $viewPath, new ViewHandlerParents()))
            ->locate($name, new AppSourceIndex($app->root));
    }

    // ----- a "/" in the name is a directory step (these were wrongly refused) ----------------------------

    public function testASlashNameIsADirectoryPath(): void
    {
        $found = $this->locate('locations/print', [self::VIEW . 'locations/print.blade.php' => 'x']);

        self::assertSame('resources/views/locations/print.blade.php', $found);
    }

    public function testASlashNameWithSeveralStepsIsADirectoryPath(): void
    {
        $found = $this->locate('admin/orders/index', [self::VIEW . 'admin/orders/index.blade.php' => 'x']);

        self::assertSame('resources/views/admin/orders/index.blade.php', $found);
    }

    public function testTheSlashAndDotFormsPointAtTheSameTemplate(): void
    {
        $files = [self::VIEW . 'a/b.blade.php' => 'x'];

        self::assertSame($this->locate('a.b', $files), $this->locate('a/b', $files));
        self::assertSame('resources/views/a/b.blade.php', $this->locate('a/b', $files));
    }

    public function testASlashNameWithADotAfterItIsAnotherDirectoryStep(): void
    {
        $found = $this->locate('a/b.c', [self::VIEW . 'a/b/c.blade.php' => 'x']);

        self::assertSame('resources/views/a/b/c.blade.php', $found);
    }

    public function testADotNameWithASlashAfterItIsAnotherDirectoryStep(): void
    {
        $found = $this->locate('a.b/c', [self::VIEW . 'a/b/c.blade.php' => 'x']);

        self::assertSame('resources/views/a/b/c.blade.php', $found);
    }

    public function testASlashNameIsLookedUpInEveryConfiguredPath(): void
    {
        $config = "<?php\nreturn ['paths' => [resource_path('themes/plain'), resource_path('views')]];\n";
        $found = $this->locate('a/b', ['config/view.php' => $config, self::VIEW . 'a/b.blade.php' => 'x']);

        self::assertSame('resources/views/a/b.blade.php', $found);
    }

    public function testASlashNameIsLookedUpUnderAnExplicitViewPath(): void
    {
        $found = $this->locate('a/b', ['templates/a/b.blade.php' => 'x'], 'templates');

        self::assertSame('templates/a/b.blade.php', $found);
    }

    public function testASlashNameThatHasNoFileIsStillNotFound(): void
    {
        self::assertNull($this->locate('a/missing', [self::VIEW . 'a/b.blade.php' => 'x']));
    }

    public function testADottedNameStillWorks(): void
    {
        $found = $this->locate('admin.orders.index', [self::VIEW . 'admin/orders/index.blade.php' => 'x']);

        self::assertSame('resources/views/admin/orders/index.blade.php', $found);
    }

    public function testAPlainNameStillWorks(): void
    {
        self::assertSame('resources/views/t.blade.php', $this->locate('t', [self::VIEW . 't.blade.php' => 'x']));
    }

    public function testASlashNameIsWalkedLikeADottedOne(): void
    {
        $views = ['admin/orders/index.blade.php' => self::LOOP_ROW];
        $s = $this->walk("return view('admin/orders/index', ['orders' => Order::get()]);", $views);

        self::assertSame([self::VIEW . 'admin/orders/index.blade.php:2'], $this->eventSites($s, EventKind::LazyLoad));
        self::assertSame([], $s->gaps);
        $this->assertNoUnknownWithoutGap();
    }

    public function testAMissingSlashViewIsStillAGapAtTheCall(): void
    {
        $s = $this->walk("return view('admin/none', ['orders' => Order::get()]);", ['admin/orders.blade.php' => 'x']);

        self::assertSame([GapCode::VIEW_NOT_FOUND], $this->gapCodes($s));
        $this->assertNoUnknownWithoutGap();
    }

    // ----- what must never be read (these hold before and after the fix) -------------------------------

    public function testADotDotStepIsRefusedEvenWhenTheFileIsThere(): void
    {
        $files = ['resources/secret.blade.php' => 'x', self::VIEW . 'a/t.blade.php' => 'x', self::VIEW . 't.blade.php' => 'x'];

        self::assertNull($this->locate('../secret', $files));
        self::assertNull($this->locate('a/../t', $files));
        self::assertNull($this->locate('a/../../secret', $files));
        self::assertNull($this->locate('..', $files));
        self::assertNull($this->locate('a/..', $files));
        self::assertNull($this->locate('..secret', $files));
        self::assertNull($this->locate('a..t', $files));
        self::assertNull($this->locate('./t', $files));
    }

    public function testADotDotNameInADottedFormIsRefused(): void
    {
        $files = ['resources/secret.blade.php' => 'x'];

        self::assertNull($this->locate('...secret', $files));
        self::assertNull($this->locate('.secret', $files));
        self::assertNull($this->locate('a.', $files));
    }

    public function testAnAbsolutePathIsRefusedEvenWhenItResolvesUnderTheViewDirectory(): void
    {
        $files = [self::VIEW . 'resources/views/t.blade.php' => 'x', self::VIEW . 't.blade.php' => 'x'];

        self::assertNull($this->locate('/resources/views/t', $files));
        self::assertNull($this->locate('/t', $files));
        self::assertNull($this->locate('//t', $files));
    }

    public function testTheAbsolutePathOfARealViewIsRefused(): void
    {
        $app = new TempApp([self::VIEW . 't.blade.php' => 'x']);
        $templates = new ViewHandlerTemplates($app->root, null, new ViewHandlerParents());
        $source = new AppSourceIndex($app->root);

        self::assertNull($templates->locate($app->root . '/resources/views/t', $source));
        self::assertNull($templates->locate('/etc/hostname', $source));
    }

    public function testAPackageQualifiedNameIsRefused(): void
    {
        $files = [
            self::VIEW . 'vendor/pkg/x.blade.php' => 'x',
            self::VIEW . 'pkg/x.blade.php' => 'x',
            self::VIEW . 'pkg::x.blade.php' => 'x',
        ];

        self::assertNull($this->locate('pkg::x', $files));
        self::assertNull($this->locate('pkg::a/x', $files));
        self::assertNull($this->locate('pkg:x', $files));
        self::assertNull($this->locate('::x', $files));
    }

    public function testANulByteIsRefused(): void
    {
        $files = [self::VIEW . 't.blade.php' => 'x', 'resources/secret.blade.php' => 'x'];

        self::assertNull($this->locate("t\0", $files));
        self::assertNull($this->locate("t\0/../../secret", $files));
        self::assertNull($this->locate("\0", $files));
    }

    public function testOtherCharactersAreNotPartOfAViewName(): void
    {
        $files = [self::VIEW . 't.blade.php' => 'x', self::VIEW . 'a b.blade.php' => 'x', self::VIEW . 't~.blade.php' => 'x'];

        self::assertNull($this->locate("t\n", $files));
        self::assertNull($this->locate('a b', $files));
        self::assertNull($this->locate('t\\', $files));
        self::assertNull($this->locate('a\\..\\t', $files));
        self::assertNull($this->locate('t*', $files));
        self::assertNull($this->locate('', $files));
        self::assertNull($this->locate('/', $files));
    }

    public function testADotDotNameIsAGapWhenWalked(): void
    {
        $s = $this->walk("return view('../secret', []);", [], ['resources/secret.blade.php' => 'x']);

        self::assertSame([GapCode::VIEW_NOT_FOUND], $this->gapCodes($s));
    }

    public function testAnAbsoluteNameIsAGapWhenWalked(): void
    {
        $s = $this->walk("return view('/resources/views/t', []);", ['resources/views/t.blade.php' => 'x', 't.blade.php' => 'x']);

        self::assertSame([GapCode::VIEW_NOT_FOUND], $this->gapCodes($s));
    }

    public function testAPackageNameIsAGapWhenWalked(): void
    {
        $s = $this->walk("return view('pkg::x', []);", [], ['resources/views/vendor/pkg/x.blade.php' => 'x']);

        self::assertSame([GapCode::VIEW_NOT_FOUND], $this->gapCodes($s));
    }

    // ----- symbolic links: only a file that really lies under the view directory is read ---------------

    public function testAFileLinkLeadingOutOfTheViewDirectoryIsNotRead(): void
    {
        $outside = new TempApp(['x.blade.php' => 'secret']);
        $app = new TempApp([self::VIEW . 'real.blade.php' => 'x']);
        symlink($outside->root . '/x.blade.php', $app->root . '/' . self::VIEW . 'leak.blade.php');
        $templates = new ViewHandlerTemplates($app->root, null, new ViewHandlerParents());
        $source = new AppSourceIndex($app->root);

        self::assertNull($templates->locate('leak', $source));
        self::assertSame('resources/views/real.blade.php', $templates->locate('real', $source));
    }

    public function testADirectoryLinkLeadingOutOfTheViewDirectoryIsNotRead(): void
    {
        $outside = new TempApp(['x.blade.php' => 'secret', 'deep/y.blade.php' => 'secret']);
        $app = new TempApp([self::VIEW . 'real.blade.php' => 'x']);
        symlink($outside->root, $app->root . '/' . self::VIEW . 'ext');
        $templates = new ViewHandlerTemplates($app->root, null, new ViewHandlerParents());
        $source = new AppSourceIndex($app->root);

        self::assertNull($templates->locate('ext/x', $source));
        self::assertNull($templates->locate('ext.x', $source));
        self::assertNull($templates->locate('ext/deep/y', $source));
    }

    public function testALinkWithinTheViewDirectoryIsRead(): void
    {
        $app = new TempApp([self::VIEW . 'shared/real.blade.php' => 'x']);
        symlink($app->root . '/' . self::VIEW . 'shared/real.blade.php', $app->root . '/' . self::VIEW . 'alias.blade.php');
        symlink($app->root . '/' . self::VIEW . 'shared', $app->root . '/' . self::VIEW . 'dir');
        $templates = new ViewHandlerTemplates($app->root, null, new ViewHandlerParents());
        $source = new AppSourceIndex($app->root);

        self::assertSame('resources/views/alias.blade.php', $templates->locate('alias', $source));
        self::assertSame('resources/views/dir/real.blade.php', $templates->locate('dir/real', $source));
    }

    public function testALinkedViewDirectoryItselfIsRead(): void
    {
        $app = new TempApp(['real-templates/a/b.blade.php' => 'x']);
        symlink($app->root . '/real-templates', $app->root . '/templates');
        $templates = new ViewHandlerTemplates($app->root, 'templates', new ViewHandlerParents());
        $source = new AppSourceIndex($app->root);

        self::assertSame('templates/a/b.blade.php', $templates->locate('a/b', $source));
    }
}
