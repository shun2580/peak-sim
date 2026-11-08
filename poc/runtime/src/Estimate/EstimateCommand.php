<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Estimate;

/**
 * bin/estimate --capture=<capture.json> --explain=<explain.json> --candidate=H1|H2|H3 [--at=<N>] [--predictions=<predictions.json>]
 * prints the estimate of every route on standard output. Exit codes: 0 done; 2 wrong usage or an unreadable input.
 */
final class EstimateCommand
{
    private const USAGE = 'usage: bin/estimate --capture=<capture.json> --explain=<explain.json> --candidate=H1|H2|H3 [--at=<N>] [--predictions=<predictions.json>] > estimate.json';

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
        $opts = ['capture' => null, 'explain' => null, 'candidate' => null, 'at' => null, 'predictions' => null];
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
        foreach (['capture', 'explain', 'candidate'] as $required) {
            if ($opts[$required] === null || $opts[$required] === '') {
                return $this->usage('--' . $required . ' is required');
            }
        }
        if (!in_array($opts['candidate'], RowEstimator::CANDIDATES, true)) {
            return $this->usage('--candidate must be one of ' . implode(', ', RowEstimator::CANDIDATES));
        }
        if ($opts['at'] !== null && preg_match('/^[1-9][0-9]*$/', $opts['at']) !== 1) {
            return $this->usage('--at must be a positive integer');
        }
        $decoded = [];
        foreach (['capture', 'explain', 'predictions'] as $name) {
            if ($opts[$name] === null) {
                $decoded[$name] = null;
                continue;
            }
            $raw = is_file($opts[$name]) ? file_get_contents($opts[$name]) : false;
            if ($raw === false) {
                return $this->usage('--' . $name . ' is not a readable file');
            }
            try {
                $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return $this->usage('--' . $name . ' is not JSON: ' . $e->getMessage());
            }
            if (!is_array($value)) {
                return $this->usage('--' . $name . ' is not a JSON object');
            }
            $decoded[$name] = $value;
        }
        try {
            $result = RowEstimator::estimate($decoded['capture'] ?? [], $decoded['explain'] ?? [], (string) $opts['candidate'], $opts['at'] === null ? null : (int) $opts['at'], $decoded['predictions']);
        } catch (\InvalidArgumentException $e) {
            return $this->usage($e->getMessage());
        }
        fwrite($this->out, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return 0;
    }

    private function usage(string $message): int
    {
        fwrite($this->err, "bin/estimate: {$message}\n" . self::USAGE . "\n");

        return 2;
    }
}
