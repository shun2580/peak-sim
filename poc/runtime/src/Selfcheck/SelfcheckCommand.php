<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Selfcheck;

/**
 * The PHP half of bin/selfcheck. Sub-commands, each a step the shell script calls between the existing commands:
 *
 *   seal  --dir=<dir> --files=<a,b>        hash the estimate files written in <dir> and record the order (EstimateSeal::seal)
 *   gate  --dir=<dir>                      the only way a measurement may start (EstimateSeal::beginMeasurement)
 *   marked --sealed=<dir>                  the marked routes of the sealed list, comma separated
 *   judge --sealed=<dir> --measured=<dir|attempts.jsonl> --out=<file> [--extra=<dir>] [--explain=<explain.json>]
 *         [--app=<name>] [--now=<iso time>]
 *                                          verify the order, compare the sealed estimate with the measurement, write the result
 *
 * Exit codes: 0 done; 2 wrong usage; 3 an input cannot be read; 20 the order is violated (nothing is written, nothing starts).
 */
final class SelfcheckCommand
{
    public const EXIT_USAGE = 2;

    public const EXIT_INPUT = 3;

    public const EXIT_ORDER = 20;

    private const USAGE = <<<'TXT'
usage: selfcheck seal  --dir=<dir> --files=<a,b> [--now=<iso time>]
       selfcheck gate  --dir=<dir> [--now=<iso time>]
       selfcheck marked --sealed=<dir>
       selfcheck judge --sealed=<dir> --measured=<dir|attempts.jsonl> --out=<file> [--extra=<dir>] [--explain=<explain.json>] [--app=<name>] [--now=<iso time>]
TXT;

    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(private readonly mixed $out, private readonly mixed $err)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $sub = array_shift($args);
        $allowed = [
            'seal' => ['dir', 'files', 'now'],
            'gate' => ['dir', 'now'],
            'judge' => ['sealed', 'measured', 'out', 'extra', 'explain', 'app', 'now'],
            'marked' => ['sealed'],
        ];
        if ($sub === null || !isset($allowed[$sub])) {
            return $this->usage('the first argument is seal, gate or judge');
        }
        $opts = array_fill_keys($allowed[$sub], null);
        foreach ($args as $arg) {
            $matched = false;
            foreach (array_keys($opts) as $name) {
                if (str_starts_with($arg, '--' . $name . '=')) {
                    $opts[$name] = substr($arg, strlen($name) + 3);
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return $this->usage('unknown argument: ' . $arg);
            }
        }
        try {
            $now = new \DateTimeImmutable(($opts['now'] ?? '') !== '' ? (string) $opts['now'] : 'now');
        } catch (\Exception) {
            return $this->usage('--now is not a time');
        }

        try {
            return match ($sub) {
                'seal' => $this->seal($opts, $now),
                'gate' => $this->gate($opts, $now),
                'marked' => $this->marked($opts),
                default => $this->judge($opts, $now),
            };
        } catch (SelfcheckOrderViolation $e) {
            fwrite($this->err, 'selfcheck ' . $sub . ': refused, the order is not kept: ' . $e->getMessage() . "\n");

            return self::EXIT_ORDER;
        }
    }

    /** @param array<string, string|null> $o */
    private function seal(array $o, \DateTimeImmutable $now): int
    {
        if (($o['dir'] ?? '') === '' || ($o['files'] ?? '') === '') {
            return $this->usage('--dir and --files are required');
        }
        $seal = EstimateSeal::seal((string) $o['dir'], explode(',', (string) $o['files']), $now);
        fwrite($this->out, json_encode($seal, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");

        return 0;
    }

    /** @param array<string, string|null> $o */
    private function gate(array $o, \DateTimeImmutable $now): int
    {
        if (($o['dir'] ?? '') === '') {
            return $this->usage('--dir is required');
        }
        EstimateSeal::beginMeasurement((string) $o['dir'], $now);
        fwrite($this->out, "gate open\n");

        return 0;
    }

    /**
     * The marked routes of the sealed list, comma separated ("METHOD uri,METHOD uri"), the form bin/variance-run takes. Printed only
     * when the seal is intact; nothing is printed for a list with no marked route.
     *
     * @param array<string, string|null> $o
     */
    private function marked(array $o): int
    {
        if (($o['sealed'] ?? '') === '') {
            return $this->usage('--sealed is required');
        }
        $dir = rtrim((string) $o['sealed'], '/');
        EstimateSeal::verify($dir);
        try {
            $list = self::readJson($dir . '/marked-routes.json');
        } catch (\Throwable $e) {
            fwrite($this->err, 'selfcheck marked: cannot read the list: ' . $e->getMessage() . "\n");

            return self::EXIT_INPUT;
        }
        $names = [];
        foreach ((array) ($list['routes'] ?? []) as $r) {
            $names[] = (string) ((array) $r)['route'];
        }
        fwrite($this->out, implode(',', $names));

        return 0;
    }

    /** @param array<string, string|null> $o */
    private function judge(array $o, \DateTimeImmutable $now): int
    {
        foreach (['sealed', 'measured', 'out'] as $required) {
            if (($o[$required] ?? '') === '') {
                return $this->usage('--' . $required . ' is required');
            }
        }
        $sealedDir = rtrim((string) $o['sealed'], '/');
        $order = EstimateSeal::verifyOrder($sealedDir);

        try {
            $estimate = self::readJson($sealedDir . '/estimate.json');
            $marked = self::readJson($sealedDir . '/marked-routes.json');
            $measured = (string) $o['measured'];
            $attemptsFile = is_dir($measured) ? rtrim($measured, '/') . '/attempts.jsonl' : $measured;
            $attempts = self::readRecords($attemptsFile);
            $extra = [];
            if (($o['extra'] ?? '') !== '') {
                foreach (self::attemptFiles((string) $o['extra']) as $file) {
                    $extra[] = self::readRecords($file);
                }
            }
            $explainTotals = null;
            if (($o['explain'] ?? '') !== '') {
                $explain = self::readJson((string) $o['explain']);
                $explainTotals = is_array($explain['totals'] ?? null) ? $explain['totals'] : null;
            }
            $result = SelfcheckJudge::judge($estimate, $marked, $attempts, $extra, $explainTotals);
        } catch (\Throwable $e) {
            fwrite($this->err, 'selfcheck judge: cannot read the inputs: ' . $e->getMessage() . "\n");

            return self::EXIT_INPUT;
        }

        $result = [
            'schema_version' => SelfcheckJudge::SCHEMA_VERSION,
            'kind' => 'selfcheck-result',
            'app' => ($o['app'] ?? '') === '' ? ($estimate['app'] ?? null) : $o['app'],
            'target_scale' => $result['at'],
            'checked_at' => $now->format(\DATE_ATOM),
            'order' => [
                'estimate_sealed_at' => $order['sealed_at'],
                'measure_started_at' => $order['measure_started_at'],
                'sealed_sha256' => $order['seal']['sha256'],
            ],
        ] + $result;
        $json = json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n";
        if (file_put_contents((string) $o['out'], $json) === false) {
            fwrite($this->err, 'selfcheck judge: cannot write ' . $o['out'] . "\n");

            return self::EXIT_INPUT;
        }
        fwrite($this->out, SelfcheckReport::table($result));

        return 0;
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new \RuntimeException('not a readable file: ' . basename($path));
        }
        $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException(basename($path) . ' is not a JSON object');
        }

        return $data;
    }

    /**
     * One JSON record per line. The record of a route that answers with a file can have the body in front of the JSON text on the same
     * line, so each line is read from its first `{"route"`; a line without one is not a record.
     *
     * @return list<array<string, mixed>>
     */
    private static function readRecords(string $path): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new \RuntimeException('no readable ' . basename($path));
        }
        $records = [];
        foreach (preg_split('/\R/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $i = strpos($line, '{"route"');
            if ($i === false) {
                continue;
            }
            $records[] = json_decode(substr($line, $i), true, 512, \JSON_THROW_ON_ERROR);
        }

        return $records;
    }

    /** @return list<string> every attempt.jsonl under the directory, in a fixed order */
    private static function attemptFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new \RuntimeException('--extra is not a directory');
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getFilename() === 'attempt.jsonl') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function usage(string $message): int
    {
        fwrite($this->err, 'selfcheck: ' . $message . "\n" . self::USAGE . "\n");

        return self::EXIT_USAGE;
    }
}
