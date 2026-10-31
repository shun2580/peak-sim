<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Blade;

use PeakSim\Analysis\Blade\BladeToPhp;
use PeakSim\Analysis\Blade\TranspiledView;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard;

/** Helpers shared by the Blade transpiler tests. */
trait BladeAssertions
{
    private const VIEW = 'resources/views/sample.blade.php';

    private static function transpile(string $source): TranspiledView
    {
        return BladeToPhp::transpile(self::VIEW, $source);
    }

    /** Parses PHP the way the engine will (PHP 8.2 grammar); a syntax error fails the test. @return list<\PhpParser\Node\Stmt> */
    private static function parse(string $php): array
    {
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromString('8.2'));

        return $parser->parse($php) ?? [];
    }

    /** The normalised code of the parsed PHP, so tests do not depend on spacing or brace style. */
    private static function code(string $php): string
    {
        return (new Standard())->prettyPrint(self::parse($php));
    }

    private function assertTranspilesTo(string $expectedPhp, string $template): void
    {
        $view = self::transpile($template);

        $this->assertSame(
            self::code('<?php ' . $expectedPhp),
            self::code($view->php),
            "template: {$template}",
        );
        $this->assertSame([], $view->gaps, "unexpected gaps for: {$template}");
    }

    /** @return list<string> the gap messages */
    private static function gapMessages(TranspiledView $view): array
    {
        return array_map(static fn ($g) => $g->message, $view->gaps);
    }
}
