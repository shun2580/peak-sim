<?php

declare(strict_types=1);

namespace PeakSim\Cli;

/**
 * Reads the command line: `analyze <app-root> [--artisan-command=…|--export-dir=…] [--format=table|json]
 * [--route=<uri>] [--max-depth=20] [--stats]`. Options take their value after `=`; `--export-dir` and
 * `--artisan-command` may also take it as the next argument. Anything not understood is a UsageError: a mistyped option must not be ignored, or the user reads an answer to a question they did not ask.
 */
final class Arguments
{
    public const DEFAULT_MAX_DEPTH = 20;

    /** Options whose value may be the next argument (`--export-dir <dir>`), as the output schema's examples show. */
    private const SPACE_SEPARATED = ['--export-dir', '--artisan-command'];

    /** @param list<string> $args the arguments after the program name */
    public static function isHelp(array $args): bool
    {
        return $args !== [] && in_array($args[0], ['--help', '-h', 'help'], true);
    }

    /**
     * @param list<string> $args the arguments after the program name
     * @throws UsageError
     */
    public static function parse(array $args): Command
    {
        if ($args === []) {
            throw new UsageError('no command given');
        }
        if ($args[0] !== 'analyze') {
            throw new UsageError("unknown command: {$args[0]}");
        }

        $roots = [];
        $options = [];
        $rest = array_slice($args, 1);
        for ($i = 0; $i < count($rest); $i++) {
            $arg = $rest[$i];
            if ($arg === '--stats') {
                $options['stats'] = true;
            } elseif (in_array($arg, self::SPACE_SEPARATED, true)) {
                $value = $rest[$i + 1] ?? null;
                if ($value === null || str_starts_with($value, '--')) {
                    throw new UsageError("{$arg} needs a value: {$arg} <value>");
                }
                $options = self::option($arg . '=' . $value, $options);
                $i++;
            } elseif (str_starts_with($arg, '--')) {
                $options = self::option($arg, $options);
            } else {
                $roots[] = $arg;
            }
        }

        if (count($roots) !== 1) {
            throw new UsageError($roots === [] ? 'missing <app-root>' : 'only one <app-root> can be given');
        }
        if (isset($options['artisan-command'], $options['export-dir'])) {
            throw new UsageError('--artisan-command and --export-dir are mutually exclusive: give one of them');
        }

        $format = $options['format'] ?? Command::FORMAT_TABLE;
        if (!in_array($format, [Command::FORMAT_TABLE, Command::FORMAT_JSON], true)) {
            throw new UsageError("--format must be table or json, not \"{$format}\"");
        }

        return new Command(
            $roots[0],
            $options['artisan-command'] ?? null,
            $options['export-dir'] ?? null,
            $format,
            $options['route'] ?? null,
            self::maxDepth($options['max-depth'] ?? null),
            ($options['stats'] ?? false) === true,
        );
    }

    /**
     * @param array<string, string|bool> $options
     * @return array<string, string|bool>
     */
    private static function option(string $arg, array $options): array
    {
        $parts = explode('=', substr($arg, 2), 2);
        $name = $parts[0];
        if (!in_array($name, ['artisan-command', 'export-dir', 'format', 'route', 'max-depth'], true)) {
            throw new UsageError("unknown option: {$arg}");
        }
        if (!isset($parts[1])) {
            throw new UsageError("--{$name} needs a value: --{$name}=<value>");
        }
        if ($parts[1] === '') {
            throw new UsageError("--{$name} is empty");
        }
        $options[$name] = $parts[1];

        return $options;
    }

    private static function maxDepth(string|bool|null $value): int
    {
        if ($value === null) {
            return self::DEFAULT_MAX_DEPTH;
        }
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw new UsageError('--max-depth must be a whole number of 1 or more');
        }

        return (int) $value;
    }
}
