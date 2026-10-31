<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Binding;

use PeakSim\Binding\BindingResult;
use PeakSim\Binding\ProviderBindingTable;
use PeakSim\Source\AppSourceIndex;
use PHPUnit\Framework\TestCase;

/**
 * Builds a small throw-away app in the system temp directory, so each test shows the source it reads.
 */
abstract class BindingCase extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::remove($root);
        }
        $this->roots = [];
    }

    /**
     * @param array<string, string> $files app-relative path => contents
     * @return array{0: ProviderBindingTable, 1: array<string, string>}
     */
    protected function make(array $files): array
    {
        $files = $files + self::common();
        $root = sys_get_temp_dir() . '/peak-sim-binding-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        foreach ($files as $path => $text) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0777, true);
            }
            file_put_contents($root . '/' . $path, $text);
        }

        return [new ProviderBindingTable(new AppSourceIndex($root)), $files];
    }

    /** @param array<string, string> $files */
    protected function table(array $files): ProviderBindingTable
    {
        return $this->make($files)[0];
    }

    /** The files every case shares: an interface, two implementations and one consumer. */
    protected static function common(): array
    {
        return [
            'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "app/"}}}',
            'app/Contracts/Reader.php' => "<?php\nnamespace App\\Contracts;\n\ninterface Reader\n{\n}\n",
            'app/Contracts/Mailer.php' => "<?php\nnamespace App\\Contracts;\n\ninterface Mailer\n{\n}\n",
            'app/Contracts/BaseWriter.php' => "<?php\nnamespace App\\Contracts;\n\nabstract class BaseWriter\n{\n}\n",
            'app/Readers/EagerReader.php' => "<?php\nnamespace App\\Readers;\n\nuse App\\Contracts\\Reader;\n\nclass EagerReader implements Reader\n{\n}\n",
            'app/Readers/LazyReader.php' => "<?php\nnamespace App\\Readers;\n\nuse App\\Contracts\\Reader;\n\nclass LazyReader implements Reader\n{\n}\n",
            'app/Services/ReportService.php' => "<?php\nnamespace App\\Services;\n\nclass ReportService\n{\n}\n",
            'app/Services/ExportService.php' => "<?php\nnamespace App\\Services;\n\nclass ExportService\n{\n}\n",
        ];
    }

    /** A provider class with the given register() body and extra members. */
    protected static function provider(string $name, string $register, string $members = '', string $extends = 'ServiceProvider'): string
    {
        return <<<PHP
<?php
namespace App\\Providers;

use App\\Contracts\\Reader;
use App\\Contracts\\Mailer;
use App\\Readers\\EagerReader;
use App\\Readers\\LazyReader;
use App\\Services\\ReportService;
use App\\Services\\ExportService;
use Illuminate\\Contracts\\Foundation\\Application;
use Illuminate\\Support\\Facades\\App;
use Illuminate\\Support\\ServiceProvider;

class {$name} extends {$extends}
{
{$members}
    public function register(): void
    {
{$register}
    }
}

PHP;
    }

    /** bootstrap/providers.php listing the given providers in order. */
    protected static function providers(string ...$names): string
    {
        $list = implode('', array_map(static fn (string $n): string => "    App\\Providers\\{$n}::class,\n", $names));

        return "<?php\n\nreturn [\n{$list}];\n";
    }

    /**
     * @return array{kind: string, candidates: list<array{0: string, 1: string, 2: bool}>}
     */
    protected static function shape(BindingResult $r): array
    {
        $rows = [];
        foreach ($r->candidates as $c) {
            $rows[] = [$c->impl, $c->kind, $c->conditional];
        }

        return ['kind' => $r->kind->value, 'candidates' => $rows];
    }

    /** "file:line" of the first line that contains $needle. */
    /** @param array<string, string> $files */
    protected static function at(array $files, string $path, string $needle): string
    {
        foreach (explode("\n", $files[$path]) as $i => $line) {
            if (str_contains($line, $needle)) {
                return $path . ':' . ($i + 1);
            }
        }

        throw new \LogicException("{$needle} is not in {$path}");
    }

    private static function remove(string $path): void
    {
        $base = sys_get_temp_dir() . '/peak-sim-binding-';
        if (!str_starts_with($path, $base) || !is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
