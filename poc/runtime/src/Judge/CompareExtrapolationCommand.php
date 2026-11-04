<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Judge;

/**
 * bin/compare-extrapolation --predictions=<predictions.json> --measured=<run record dir or its attempts.jsonl>
 *                           [--format=json|markdown]
 * Prints the comparison and the pass / fail of the extrapolation test. Exit codes: 0 done (whatever the verdict);
 * 2 wrong usage or an unreadable input.
 */
final class CompareExtrapolationCommand
{
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
        $predictionsPath = null;
        $measured = null;
        $format = 'json';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--predictions=')) {
                $predictionsPath = substr($arg, 14);
            } elseif (str_starts_with($arg, '--measured=')) {
                $measured = substr($arg, 11);
            } elseif (str_starts_with($arg, '--format=')) {
                $format = substr($arg, 9);
            } else {
                return $this->usage('unknown argument: ' . $arg);
            }
        }
        if ($predictionsPath === null || $predictionsPath === '' || $measured === null || $measured === '') {
            return $this->usage('--predictions and --measured are required');
        }
        if ($format !== 'json' && $format !== 'markdown') {
            return $this->usage('--format takes json or markdown');
        }
        $attemptsFile = is_dir($measured) ? rtrim($measured, '/') . '/attempts.jsonl' : $measured;
        $name = basename(is_dir($measured) ? rtrim($measured, '/') : $measured);
        $predictionsRaw = is_file($predictionsPath) ? file_get_contents($predictionsPath) : false;
        $attemptsRaw = is_file($attemptsFile) ? file_get_contents($attemptsFile) : false;
        if ($predictionsRaw === false) {
            return $this->usage('--predictions is not a readable file');
        }
        if ($attemptsRaw === false) {
            return $this->usage('--measured has no readable attempts.jsonl');
        }
        try {
            $predictions = json_decode($predictionsRaw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($predictions) || !isset($predictions['at'], $predictions['routes'])) {
                throw new \InvalidArgumentException('the predictions have no "at" or "routes"');
            }
            $attempts = [];
            foreach (preg_split('/\R/', $attemptsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
                $attempts[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = ExtrapolationJudge::judge($predictions, $attempts, $name);
        } catch (\Throwable $e) {
            return $this->usage('cannot read the inputs: ' . $e->getMessage());
        }
        fwrite($this->out, $format === 'markdown'
            ? ExtrapolationJudge::toMarkdown($result)
            : json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return 0;
    }

    private function usage(string $message): int
    {
        fwrite($this->err, "bin/compare-extrapolation: {$message}\nusage: bin/compare-extrapolation --predictions=<predictions.json> --measured=<dir> [--format=json|markdown]\n");

        return 2;
    }
}
