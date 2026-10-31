<?php

declare(strict_types=1);

namespace PeakSim\Input;

/**
 * Asks a live artisan: `<artisan_command> route:list --json` and `<artisan_command> model:show <FQCN> --json`.
 * The artisan command is whatever reaches the app, e.g. `php artisan` or `docker compose exec app php artisan`.
 *
 * Order of trust (artisan-compat §3.4): the exit code first; only for exit 0 is standard output read, and only as
 * strict JSON. Laravel prints the body of an exception to standard output, and a PHP warning can land in front of
 * the JSON with exit 0, so standard error is never mixed into what is parsed.
 *
 * Nothing runs until it is asked for; answers (also "no model info") are kept.
 */
final class CommandArtisanSource implements ArtisanSource
{
    private const EXCERPT_BYTES = 2048;
    private const STDOUT_HEAD_BYTES = 300;

    private readonly ProcessRunner $runner;

    /** @var list<RouteEntry>|null */
    private ?array $routes = null;

    /** @var array<string, ModelInfo|null> keyed by class without a leading backslash */
    private array $models = [];

    /** @var list<string> */
    private array $diagnostics = [];

    public function __construct(
        private readonly string $appRoot,
        private readonly string $artisanCommand = 'php artisan',
        ?ProcessRunner $runner = null,
        private readonly int $timeoutSeconds = 120,
    ) {
        $this->runner = $runner ?? new ProcOpenRunner();
    }

    public function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }

        $label = 'route:list --json';
        $result = $this->run($label);
        if ($result->exitCode !== 0) {
            throw new ArtisanFailedException($this->failureMessage($label, $result));
        }
        try {
            return $this->routes = ArtisanPayload::routes(ArtisanPayload::decode($result->stdout));
        } catch (\JsonException | \UnexpectedValueException $e) {
            throw new ArtisanOutputNotJsonException($this->notJsonMessage($label, $result, $e->getMessage()), 0, $e);
        }
    }

    public function modelInfo(string $class): ?ModelInfo
    {
        $fqcn = ltrim($class, '\\');
        if (array_key_exists($fqcn, $this->models)) {
            return $this->models[$fqcn];
        }

        $label = "model:show {$fqcn} --json";
        $result = $this->run('model:show ' . escapeshellarg($fqcn) . ' --json');
        if ($result->exitCode !== 0) {
            // The caller makes a MODEL_INFO_UNAVAILABLE gap (the route turns unknown). The reason is kept here.
            $this->diagnostics[] = $this->failureMessage($label, $result)
                . "\nmodel:show needs a local database the app can reach, with the schema migrated; or use a pre-exported directory (--export-dir).";

            return $this->models[$fqcn] = null;
        }

        try {
            $info = ArtisanPayload::model(ArtisanPayload::decode($result->stdout));
        } catch (\JsonException | \UnexpectedValueException $e) {
            throw new ArtisanOutputNotJsonException($this->notJsonMessage($label, $result, $e->getMessage()), 0, $e);
        }
        if (strcasecmp($info->class, $fqcn) !== 0) {
            $this->diagnostics[] = "{$label} answered for {$info->class}, not {$fqcn}; it is not used.";

            return $this->models[$fqcn] = null;
        }

        return $this->models[$fqcn] = $info;
    }

    /**
     * Why model:show gave no information, one text per model. Empty when all went well.
     *
     * @return list<string>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** @param string $arguments e.g. `route:list --json` (already shell-escaped) */
    private function run(string $arguments): ProcessResult
    {
        return $this->runner->run($this->artisanCommand . ' ' . $arguments, $this->appRoot, $this->timeoutSeconds);
    }

    private function failureMessage(string $label, ProcessResult $result): string
    {
        $message = "artisan {$label} failed with exit code {$result->exitCode}.";
        if (trim($result->stderr) !== '') {
            $message .= "\nstderr (first " . self::EXCERPT_BYTES . " bytes):\n" . self::excerpt($result->stderr, self::EXCERPT_BYTES);
        }
        if (trim($result->stdout) !== '') {
            $message .= "\nstdout (first " . self::EXCERPT_BYTES . " bytes; Laravel prints the body of an exception here):\n"
                . self::excerpt($result->stdout, self::EXCERPT_BYTES);
        }

        return $message;
    }

    private function notJsonMessage(string $label, ProcessResult $result, string $reason): string
    {
        $message = "artisan {$label} exited with code 0 but its standard output is not valid JSON ({$reason})."
            . "\nThe PHP version may be outside the range supported by the target Laravel."
            . "\nstart of stdout:\n" . self::excerpt($result->stdout, self::STDOUT_HEAD_BYTES);
        if (trim($result->stderr) !== '') {
            $message .= "\nstderr (first " . self::EXCERPT_BYTES . " bytes):\n" . self::excerpt($result->stderr, self::EXCERPT_BYTES);
        }

        return $message
            . "\nHint: set artisan_command (--artisan-command) to `php -d display_errors=stderr artisan` so that PHP warnings go to stderr, not into the JSON.";
    }

    /** At most $bytes bytes, no terminal decoration, never half of a UTF-8 character. */
    private static function excerpt(string $text, int $bytes): string
    {
        $text = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text);
        $cut = substr($text, 0, $bytes);
        for ($i = 0; $i < 3 && preg_match('//u', $cut) !== 1 && $cut !== ''; $i++) {
            $cut = substr($cut, 0, -1);
        }
        if (preg_match('//u', $cut) !== 1) {
            $cut = (string) preg_replace('/[^\x09\x0A\x20-\x7E]/', '?', $cut);
        }

        return rtrim($cut);
    }
}
