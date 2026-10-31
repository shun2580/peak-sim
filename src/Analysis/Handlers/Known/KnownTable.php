<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Handlers\Known;

/**
 * The allow-list file (resources/known-calls.php), loaded and checked.
 *
 * Loading fails unless every entry states its basis and its reason, so a call cannot be listed without saying why.
 * Lookups ignore case and a leading backslash. The table is the same whatever order the file lists things in:
 * entries and refusals are sorted on load.
 */
final class KnownTable
{
    private const SECTIONS = ['functions', 'methods', 'static', 'refused'];

    private const ENTRY_KEYS = ['returns', 'args', 'basis', 'why', 'delegate', 'stores_closures'];

    private static ?self $default = null;

    /**
     * @param array<string, KnownEntry> $entries key 'function:<name>', 'method:<class>::<method>' or 'static:<class>::<method>'
     * @param list<array{function?: string, class?: string, methods?: list<string>|string, why: string}> $refusals
     */
    private function __construct(
        private readonly array $entries,
        private readonly array $refusals,
    ) {
    }

    public static function default(): self
    {
        return self::$default ??= self::fromFile(dirname(__DIR__, 4) . '/resources/known-calls.php');
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('the allow-list file does not exist: ' . basename($path));
        }
        $data = (static fn (): mixed => require $path)();
        if (!is_array($data)) {
            throw new \InvalidArgumentException('the allow-list file must return an array');
        }

        return self::fromArray($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (array_keys($data) as $section) {
            if (!in_array($section, self::SECTIONS, true)) {
                throw new \InvalidArgumentException("unknown section '{$section}' in the allow-list");
            }
        }

        $entries = [];
        foreach (self::map($data['functions'] ?? [], 'functions') as $name => $raw) {
            $entries['function:' . self::name($name)] = self::entry($raw, "function {$name}()");
        }
        foreach (['methods' => 'method', 'static' => 'static'] as $section => $kind) {
            foreach (self::map($data[$section] ?? [], $section) as $class => $methods) {
                foreach (self::map($methods, "{$section}.{$class}") as $method => $raw) {
                    $entries[$kind . ':' . self::name($class) . '::' . self::name($method)] = self::entry($raw, "{$class}::{$method}");
                }
            }
        }
        ksort($entries, SORT_STRING);

        $refusals = self::parseRefusals($data['refused'] ?? []);
        $table = new self($entries, $refusals);
        $table->assertNothingIsListedAndRefused();

        return $table;
    }

    /** @return array<string, KnownEntry> sorted by key */
    public function all(): array
    {
        return $this->entries;
    }

    /** @return list<array{function?: string, class?: string, methods?: list<string>|string, why: string}> */
    public function refusals(): array
    {
        return $this->refusals;
    }

    public function forFunction(string $name): ?KnownEntry
    {
        return $this->entries['function:' . self::name($name)] ?? null;
    }

    public function forMethod(string $class, string $method): ?KnownEntry
    {
        return $this->lookup('method', $class, $method);
    }

    public function forStatic(string $class, string $method): ?KnownEntry
    {
        return $this->lookup('static', $class, $method);
    }

    /** The reason a function is kept out on purpose, or null. */
    public function refusedFunction(string $name): ?string
    {
        $name = self::name($name);
        foreach ($this->refusals as $refusal) {
            if (isset($refusal['function']) && $refusal['function'] === $name) {
                return $refusal['why'];
            }
        }

        return null;
    }

    /** The reason a method (or static call) is kept out on purpose, or null. */
    public function refusedMethod(string $class, string $method): ?string
    {
        $class = self::name($class);
        $method = self::name($method);
        foreach ($this->refusals as $refusal) {
            if (!isset($refusal['class']) || $refusal['class'] !== $class) {
                continue;
            }
            $methods = $refusal['methods'] ?? '*';
            if ($methods === '*' || in_array($method, (array) $methods, true)) {
                return $refusal['why'];
            }
        }

        return null;
    }

    private function lookup(string $kind, string $class, string $method): ?KnownEntry
    {
        $class = self::name($class);

        return $this->entries["{$kind}:{$class}::" . self::name($method)] ?? $this->entries["{$kind}:{$class}::*"] ?? null;
    }

    private function assertNothingIsListedAndRefused(): void
    {
        foreach (array_keys($this->entries) as $key) {
            [$kind, $rest] = explode(':', $key, 2);
            $why = $kind === 'function'
                ? $this->refusedFunction($rest)
                : $this->refusedMethod(...explode('::', $rest, 2));
            if ($why !== null) {
                throw new \InvalidArgumentException("'{$key}' is both listed and refused; an allow-list entry may not be refused");
            }
        }
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value, string $where): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("'{$where}' must be a map");
        }

        return $value;
    }

    private static function name(string|int $name): string
    {
        return strtolower(ltrim((string) $name, '\\'));
    }

    private static function entry(mixed $raw, string $where): KnownEntry
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException("entry for {$where} must be a map");
        }
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, self::ENTRY_KEYS, true)) {
                throw new \InvalidArgumentException("unknown key '{$key}' in the entry for {$where}");
            }
        }
        $why = $raw['why'] ?? null;
        if (!is_string($why) || trim($why) === '') {
            throw new \InvalidArgumentException("the entry for {$where} gives no why: say why the call may be listed");
        }

        if (isset($raw['delegate'])) {
            $delegate = $raw['delegate'];
            if (!is_string($delegate) || trim($delegate) === '') {
                throw new \InvalidArgumentException("the delegate of {$where} must name a handler");
            }
            foreach (['returns', 'args', 'basis', 'stores_closures'] as $key) {
                if (array_key_exists($key, $raw)) {
                    throw new \InvalidArgumentException("a delegated entry has no answer of its own: remove '{$key}' from {$where}");
                }
            }

            return new KnownEntry(null, 0, null, false, null, trim($why), $delegate);
        }

        $basis = $raw['basis'] ?? null;
        if ($basis !== KnownEntry::BASIS_VERIFIED && $basis !== KnownEntry::BASIS_CONTRACT) {
            throw new \InvalidArgumentException("the entry for {$where} needs a basis of 'verified' or 'contract'");
        }
        [$min, $max] = self::arity($raw['args'] ?? [0, null], $where);
        $returns = self::returns($raw['returns'] ?? null, $min, $where);

        return new KnownEntry($returns, $min, $max, ($raw['stores_closures'] ?? false) === true, $basis, trim($why));
    }

    /** @return array{0: int, 1: int|null} */
    private static function arity(mixed $args, string $where): array
    {
        if (!is_array($args) || count($args) !== 2 || !array_key_exists(0, $args) || !array_key_exists(1, $args)) {
            throw new \InvalidArgumentException("args of {$where} must be [min, max]");
        }
        [$min, $max] = array_values($args);
        if (!is_int($min) || $min < 0 || ($max !== null && (!is_int($max) || $max < $min))) {
            throw new \InvalidArgumentException("args of {$where} must be [min, max] with 0 <= min <= max (max may be null)");
        }

        return [$min, $max];
    }

    private static function returns(mixed $returns, int $argsMin, string $where): string
    {
        if ($returns === 'plain') {
            return $returns;
        }
        if (is_string($returns) && preg_match('/^object:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $returns) === 1) {
            return $returns;
        }
        if (is_string($returns) && preg_match('/^arg:(\d+)(:([A-Za-z_][A-Za-z0-9_]*))?$/', $returns, $m) === 1) {
            // Without a name the argument must always be there; with one, a call that leaves it out gets a plain value.
            if (!isset($m[3]) && (int) $m[1] >= $argsMin) {
                throw new \InvalidArgumentException("returns '{$returns}' of {$where} points past the arguments that are always there (args min {$argsMin})");
            }

            return $returns;
        }

        throw new \InvalidArgumentException("returns of {$where} must be 'plain', 'object:<class>', 'arg:<n>' or 'arg:<n>:<name>'");
    }

    /**
     * @return list<array{function?: string, class?: string, methods?: list<string>|string, why: string}>
     */
    private static function parseRefusals(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException("'refused' must be a list");
        }
        $refusals = [];
        foreach ($raw as $i => $item) {
            $why = is_array($item) ? ($item['why'] ?? null) : null;
            if (!is_string($why) || trim($why) === '') {
                throw new \InvalidArgumentException("refused entry #{$i} gives no why");
            }
            if (isset($item['function'])) {
                $refusals[] = ['function' => self::name((string) $item['function']), 'why' => trim($why)];
            } elseif (isset($item['class'])) {
                $methods = $item['methods'] ?? '*';
                $methods = $methods === '*' ? '*' : array_values(array_map(self::name(...), (array) $methods));
                if (is_array($methods)) {
                    sort($methods, SORT_STRING);
                }
                $refusals[] = ['class' => self::name((string) $item['class']), 'methods' => $methods, 'why' => trim($why)];
            } else {
                throw new \InvalidArgumentException("refused entry #{$i} names nothing: give a function or a class");
            }
        }
        usort($refusals, static fn (array $a, array $b): int => [$a['function'] ?? '', $a['class'] ?? '', json_encode($a['methods'] ?? '*')]
            <=> [$b['function'] ?? '', $b['class'] ?? '', json_encode($b['methods'] ?? '*')]);

        return $refusals;
    }
}
