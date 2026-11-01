<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Blade;

use PeakSim\Model\Axis;
use PeakSim\Model\Gap;
use PeakSim\Model\GapCode;
use PeakSim\Model\Site;
use PhpParser\Error as PhpParserError;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * Turns a Blade template into PHP source that the engine can walk like any other code.
 * A pure function of its two string arguments; it does not use illuminate/view.
 *
 * Only the minimal set S1 of design doc section 1.11 is read. Anything else becomes a
 * BLADE_UNSUPPORTED gap (an unknown directive may run code we cannot see), never a silent skip.
 *
 * What the output looks like:
 * - The text between tags is dropped, but its newlines are kept, so a statement made from
 *   template line N sits on line N of the PHP. Evidence lines in a report are template lines.
 * - There is no closing tag. Statements follow the directive that made them on the same line.
 * - `@include('x', data)` becomes `__peak_include('x', data);` and `@extends('x')` becomes
 *   `__peak_extends('x');`. Both are for the view handler to resolve (see TranspiledView::$includes).
 * - Section, push and once bodies are plain statements in place: the body is walked once.
 * - Directives that only print markup (`@csrf`, `@vite`, `@lang`, ...) produce nothing, except that
 *   an argument containing a variable or a call is kept as an array literal so a lazy load
 *   hidden in it is still seen.
 * - Blocks are always balanced in the output; a stray or missing end directive is a gap.
 */
final class BladeToPhp
{
    /** Directives that never take parenthesised arguments; text after them is left alone. */
    private const NO_ARGS = [
        'else', 'endif', 'endunless', 'endisset', 'endempty', 'endforeach', 'endfor', 'endwhile',
        'endforelse', 'endswitch', 'default', 'csrf', 'endsection', 'show', 'stop', 'append',
        'overwrite', 'endpush', 'endprepend', 'endonce', 'parent', 'endphp', 'once',
    ];

    /** Directives whose only effect is markup output. Their arguments are kept only if they compute something. */
    private const MARKUP_ONLY = [
        'csrf', 'method', 'vite', 'lang', 'push', 'endpush', 'prepend', 'endprepend', 'stack', 'once',
        'endonce', 'section', 'endsection', 'show', 'stop', 'append', 'overwrite', 'yield', 'parent',
    ];

    private const IF_FAMILY = ['if', 'unless', 'isset', 'ifempty'];

    private string $out = '<?php ';
    private int $i = 0;
    private int $line = 1;
    private int $forelseCount = 0;

    /** @var list<Gap> */
    private array $gaps = [];

    /** @var list<IncludeRef> */
    private array $includes = [];

    /** @var list<array{kind: string, line: int, else: bool, empty: bool, id: int}> */
    private array $stack = [];

    private function __construct(private readonly string $name, private readonly string $src)
    {
    }

    /** @param string $name the template's name or path; it becomes the file of every gap */
    public static function transpile(string $name, string $source): TranspiledView
    {
        return (new self($name, str_replace("\r\n", "\n", $source)))->run();
    }

    private function run(): TranspiledView
    {
        $n = strlen($this->src);
        while ($this->i < $n) {
            $len = strcspn($this->src, '{@<', $this->i);
            if ($len > 0) {
                $this->dropText($this->i + $len);
                continue;
            }
            $handled = match ($this->src[$this->i]) {
                '{' => $this->brace(),
                '@' => $this->at(),
                default => $this->componentTag(),
            };
            if (!$handled) {
                $this->dropText($this->i + 1);
            }
        }

        $this->closeUnclosedBlocks();
        usort($this->gaps, static fn (Gap $a, Gap $b) => $a->site->line <=> $b->site->line);

        return new TranspiledView($this->out, $this->gaps, $this->includes);
    }

    // ---- {{ }}, {!! !!}, {{-- --}} ----

    private function brace(): bool
    {
        $s = $this->src;
        $line = $this->line;

        if (substr_compare($s, '{{--', $this->i, 4) === 0) {
            $close = strpos($s, '--}}', $this->i + 4);
            if ($close === false) {
                $this->gap($line, 'unterminated Blade comment');
                $this->dropText(strlen($s));
            } else {
                $this->dropText($close + 4);
            }

            return true;
        }

        if (substr_compare($s, '{{', $this->i, 2) === 0) {
            return $this->echoTag('}}', 2, $line);
        }

        if (substr_compare($s, '{!!', $this->i, 3) === 0) {
            return $this->echoTag('!!}', 3, $line);
        }

        return false;
    }

    private function echoTag(string $close, int $openLen, int $line): bool
    {
        $end = $this->findClose($close, $this->i + $openLen);
        if ($end === null) {
            $this->gap($line, 'unterminated Blade echo');
            $this->dropText($this->i + $openLen);

            return true;
        }

        $expr = substr($this->src, $this->i + $openLen, $end - $this->i - $openLen);
        $raw = $this->take($end + strlen($close));
        $this->emit(trim($expr) === '' ? '' : "echo {$expr};", $raw);

        return true;
    }

    /** Finds $close at or after $from, skipping PHP string literals so `{{ '}}' }}` works. */
    private function findClose(string $close, int $from): ?int
    {
        $s = $this->src;
        $n = strlen($s);
        $len = strlen($close);
        $quote = null;
        for ($j = $from; $j < $n; $j++) {
            $c = $s[$j];
            if ($quote !== null) {
                if ($c === '\\') {
                    $j++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === $close[0] && substr_compare($s, $close, $j, $len) === 0) {
                return $j;
            }
        }

        // an apostrophe in a place that is not a string would hide the end tag; fall back to the first one
        $plain = strpos($s, $close, $from);

        return $plain === false ? null : $plain;
    }

    // ---- <x-...> and <livewire:...> ----

    private function componentTag(): bool
    {
        if (preg_match('/\G<(?:x-[\w.:-]+|livewire:[\w.:-]+)/', $this->src, $m, 0, $this->i) !== 1) {
            return false;
        }
        $this->gap($this->line, "unsupported Blade component {$m[0]}");
        $this->take($this->i + strlen($m[0]));

        return true;
    }

    // ---- @directives ----

    private function at(): bool
    {
        $s = $this->src;
        $i = $this->i;

        if (($s[$i + 1] ?? '') === '{' && ($s[$i + 2] ?? '') === '{') {
            // @{{ ... }} prints the braces as they are
            $close = strpos($s, '}}', $i + 3);
            $this->dropText($close === false ? strlen($s) : $close + 2);

            return true;
        }
        if ($this->prevIsWord()) {
            return false; // a@b.com
        }
        if (($s[$i + 1] ?? '') === '@') {
            $this->dropText($i + 2); // @@name prints @name

            return true;
        }
        if (preg_match('/\G\w+/', $s, $m, 0, $i + 1) !== 1) {
            return false;
        }

        $name = $m[0];
        $line = $this->line;
        $nameEnd = $i + 1 + strlen($name);

        if ($name === 'php') {
            return $this->phpDirective($nameEnd, $line);
        }
        if ($name === 'verbatim') {
            return $this->verbatim($nameEnd, $line);
        }

        if (in_array($name, self::NO_ARGS, true)) {
            [$args, $end] = [null, $nameEnd];
        } else {
            [$args, $end, $broken] = $this->args($nameEnd);
            if ($broken) {
                $this->gap($line, "unbalanced parentheses after @{$name}");
                $this->dropText($nameEnd);

                return true;
            }
        }

        $code = $this->compile($name, $args, $line);
        $this->emit($code, $this->take($end));

        return true;
    }

    private function compile(string $name, ?string $args, int $line): string
    {
        $hasArgs = $args !== null && trim($args) !== '';

        switch ($name) {
            case 'if':
            case 'unless':
            case 'isset':
                if (!$hasArgs) {
                    return $this->needsArgs($name, $line);
                }
                $this->open($name, $line);

                return match ($name) {
                    'if' => "if ({$args}) {",
                    'unless' => "if (! ({$args})) {",
                    default => "if (isset({$args})) {",
                };

            case 'elseif':
                if (!$hasArgs) {
                    return $this->needsArgs($name, $line);
                }
                if (!$this->elseAllowed($name, $line)) {
                    return '';
                }

                return "} elseif ({$args}) {";

            case 'else':
                if (!$this->elseAllowed($name, $line)) {
                    return '';
                }
                $this->stack[count($this->stack) - 1]['else'] = true;

                return '} else {';

            case 'empty':
                if ($hasArgs) {
                    $this->open('ifempty', $line);

                    return "if (empty({$args})) {";
                }
                $top = $this->top();
                if ($top === null || $top['kind'] !== 'forelse' || $top['empty']) {
                    $this->gap($line, 'unbalanced @empty');

                    return '';
                }
                $this->stack[count($this->stack) - 1]['empty'] = true;

                return "} if (\$__peak_empty{$top['id']}) {";

            case 'endif':
            case 'endunless':
            case 'endisset':
            case 'endempty':
                return $this->close(self::IF_FAMILY, $name, $line);

            case 'foreach':
            case 'for':
            case 'while':
                if (!$hasArgs) {
                    return $this->needsArgs($name, $line);
                }
                $this->open($name, $line);

                return "{$name} ({$args}) {";

            case 'endforeach':
            case 'endfor':
            case 'endwhile':
                return $this->close([substr($name, 3)], $name, $line);

            case 'forelse':
                if (!$hasArgs) {
                    return $this->needsArgs($name, $line);
                }
                $id = ++$this->forelseCount;
                $this->open('forelse', $line, $id);

                return "\$__peak_empty{$id} = true; foreach ({$args}) { \$__peak_empty{$id} = false;";

            case 'endforelse':
                return $this->close(['forelse'], $name, $line);

            case 'switch':
                if (!$hasArgs) {
                    return $this->needsArgs($name, $line);
                }
                $this->open('switch', $line);

                return "switch ({$args}) {";

            case 'case':
                if (!$hasArgs) {
                    return $this->needsArgs($name, $line);
                }

                return $this->inSwitch($name, $line) ? "case {$args}:" : '';

            case 'default':
                return $this->inSwitch($name, $line) ? 'default:' : '';

            case 'endswitch':
                return $this->close(['switch'], $name, $line);

            case 'break':
            case 'continue':
                return $hasArgs ? "if ({$args}) { {$name}; }" : "{$name};";

            case 'endphp':
                $this->gap($line, 'unbalanced @endphp');

                return '';

            case 'include':
            case 'extends':
                return $this->includeDirective($name, $args, $line);

            case 'json':
                return $hasArgs ? "echo json_encode({$args});" : $this->needsArgs($name, $line);
        }

        if (in_array($name, self::MARKUP_ONLY, true)) {
            // keep an argument that computes something, e.g. @lang('k', ['n' => $o->customer->name])
            return $args !== null && preg_match('/[$(]/', $args) === 1 ? "[{$args}];" : '';
        }

        $this->gap($line, "unsupported Blade directive @{$name}");

        return '';
    }

    private function includeDirective(string $name, ?string $args, int $line): string
    {
        if ($args === null || trim($args) === '') {
            return $this->needsArgs($name, $line);
        }
        [$first, $rest] = $this->splitFirstArgument($args);

        $viewName = null;
        if (preg_match('/^\s*(?:\'([^\'\\\\]*)\'|"([^"\\\\$]*)")\s*$/', $first, $m) === 1) {
            $viewName = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
        }
        $this->includes[] = new IncludeRef($name === 'extends' ? IncludeRef::EXTENDS : IncludeRef::INCLUDE, $viewName, $line);

        if ($name === 'extends') {
            return "__peak_extends({$first});";
        }

        return "__peak_include({$first}, " . ($rest ?? '[]') . ');';
    }

    /** Splits "a, b, c" at the first comma that is not inside a string or bracket. @return array{string, ?string} */
    private function splitFirstArgument(string $args): array
    {
        $n = strlen($args);
        $depth = 0;
        $quote = null;
        for ($j = 0; $j < $n; $j++) {
            $c = $args[$j];
            if ($quote !== null) {
                if ($c === '\\') {
                    $j++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $rest = substr($args, $j + 1);

                return [substr($args, 0, $j), trim($rest) === '' ? null : $rest];
            }
        }

        return [$args, null];
    }

    private function phpDirective(int $nameEnd, int $line): bool
    {
        $s = $this->src;
        $q = $nameEnd + strspn($s, " \t", $nameEnd);

        if (($s[$q] ?? '') === '(') {
            $close = $this->matchParen($q);
            if ($close === null) {
                $this->gap($line, 'unbalanced parentheses after @php');
                $this->dropText($nameEnd);

                return true;
            }
            $inner = substr($s, $q + 1, $close - $q - 1);
            $this->emit(trim($inner) === '' ? '' : "({$inner});", $this->take($close + 1));

            return true;
        }

        $end = strpos($s, '@endphp', $nameEnd);
        if ($end === false) {
            $this->gap($line, 'unterminated @php block');
            $this->dropText($nameEnd);

            return true;
        }
        $body = substr($s, $nameEnd, $end - $nameEnd);
        $code = $body;
        if (trim($body) === '') {
            $code = '';
        } elseif (self::needsSemicolon($body)) {
            $code .= ';'; // `@php $a = 1 @endphp` is valid Blade: PHP reads the closing tag as a `;`
        }
        $this->emit($code, $this->take($end + strlen('@endphp')));

        return true;
    }

    /**
     * Whether the body of an @php block needs the `;` that the closing tag implies in PHP.
     * A body ending in `;` is complete. A body ending in `}` is complete only when it is a block statement
     * (`foreach (...) { ... }`); one that ends an expression (`$a = match (...) { ... }`) is not. Told apart by
     * trying the parser: it must read the body as it is, or fail on it and read it with the `;`.
     * A body neither way readable (e.g. the `}` that closes a brace opened in an earlier block) is left as it was.
     */
    private static function needsSemicolon(string $body): bool
    {
        if (preg_match('/;\s*$/', $body) === 1) {
            return false;
        }
        if (preg_match('/}\s*$/', $body) !== 1) {
            return true;
        }
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromString('8.2'));
        try {
            $parser->parse('<?php ' . $body);

            return false;
        } catch (PhpParserError) {
        }
        try {
            $parser->parse('<?php ' . $body . ';');

            return true;
        } catch (PhpParserError) {
            return false;
        }
    }

    private function verbatim(int $nameEnd, int $line): bool
    {
        $end = strpos($this->src, '@endverbatim', $nameEnd);
        if ($end === false) {
            $this->gap($line, 'unterminated @verbatim block');
            $this->dropText($nameEnd);

            return true;
        }
        $this->dropText($end + strlen('@endverbatim'));

        return true;
    }

    // ---- blocks ----

    private function open(string $kind, int $line, int $id = 0): void
    {
        $this->stack[] = ['kind' => $kind, 'line' => $line, 'else' => false, 'empty' => false, 'id' => $id];
    }

    /** @param list<string> $kinds */
    private function close(array $kinds, string $directive, int $line): string
    {
        $top = $this->top();
        if ($top === null || !in_array($top['kind'], $kinds, true)) {
            $this->gap($line, "unbalanced @{$directive}");

            return '';
        }
        array_pop($this->stack);

        return '}';
    }

    /** @return array{kind: string, line: int, else: bool, empty: bool, id: int}|null */
    private function top(): ?array
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
    }

    private function elseAllowed(string $directive, int $line): bool
    {
        $top = $this->top();
        if ($top === null || !in_array($top['kind'], self::IF_FAMILY, true) || $top['else']) {
            $this->gap($line, "unbalanced @{$directive}");

            return false;
        }

        return true;
    }

    private function inSwitch(string $directive, int $line): bool
    {
        $top = $this->top();
        if ($top === null || $top['kind'] !== 'switch') {
            $this->gap($line, "@{$directive} outside @switch");

            return false;
        }

        return true;
    }

    private function closeUnclosedBlocks(): void
    {
        foreach (array_reverse($this->stack) as $block) {
            $this->gap($block['line'], 'unclosed Blade block ' . $this->openerName($block['kind']));
            $this->out .= '} ';
        }
        $this->stack = [];
    }

    private function openerName(string $kind): string
    {
        return '@' . ($kind === 'ifempty' ? 'empty' : $kind);
    }

    private function needsArgs(string $directive, int $line): string
    {
        $this->gap($line, "@{$directive} needs arguments");

        return '';
    }

    // ---- scanning helpers ----

    /** @return array{?string, int, bool} the text inside the parentheses, the end offset, and whether they were unbalanced */
    private function args(int $nameEnd): array
    {
        $q = $nameEnd + strspn($this->src, " \t", $nameEnd);
        if (($this->src[$q] ?? '') !== '(') {
            return [null, $nameEnd, false];
        }
        $close = $this->matchParen($q);
        if ($close === null) {
            return [null, $nameEnd, true];
        }

        return [substr($this->src, $q + 1, $close - $q - 1), $close + 1, false];
    }

    /** Offset of the `)` that closes the `(` at $open; strings are skipped. */
    private function matchParen(int $open): ?int
    {
        $s = $this->src;
        $n = strlen($s);
        $depth = 0;
        $quote = null;
        for ($j = $open; $j < $n; $j++) {
            $c = $s[$j];
            if ($quote !== null) {
                if ($c === '\\') {
                    $j++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth === 0) {
                return $j;
            }
        }

        return null;
    }

    /** A directive cannot start right after a word character (that is an e-mail address). Bytes of multibyte text count as word characters. */
    private function prevIsWord(): bool
    {
        if ($this->i === 0) {
            return false;
        }
        $b = ord($this->src[$this->i - 1]);

        return $b >= 0x80 || ctype_alnum(chr($b)) || $b === 0x5F;
    }

    /** Consumes the template up to (not including) $end and returns it. */
    private function take(int $end): string
    {
        $raw = substr($this->src, $this->i, $end - $this->i);
        $this->line += substr_count($raw, "\n");
        $this->i = $end;

        return $raw;
    }

    /** Consumes text that produces no code; its newlines stay. */
    private function dropText(int $end): void
    {
        $this->emit('', $this->take($end));
    }

    /** Writes $code, then newlines so the output has as many as the consumed $raw template text. */
    private function emit(string $code, string $raw): void
    {
        if ($code !== '') {
            $this->out .= $code . ' ';
        }
        $missing = substr_count($raw, "\n") - substr_count($code, "\n");
        if ($missing > 0) {
            $this->out .= str_repeat("\n", $missing);
        }
    }

    private function gap(int $line, string $message): void
    {
        $this->gaps[] = new Gap(
            GapCode::BLADE_UNSUPPORTED,
            new Site($this->name, $line),
            $message,
            [Axis::Query, Axis::Memory],
            0,
            [],
        );
    }
}
