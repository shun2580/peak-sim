<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Judge;

/**
 * bin/predict --fits=<fits.json> --at=<N>   prints predictions.json on standard output.
 * Exit codes: 0 done; 2 wrong usage or an unreadable input.
 */
final class PredictCommand
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
        $fitsPath = null;
        $at = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--fits=')) {
                $fitsPath = substr($arg, 7);
            } elseif (str_starts_with($arg, '--at=')) {
                $at = substr($arg, 5);
            } else {
                return $this->usage('unknown argument: ' . $arg);
            }
        }
        if ($fitsPath === null || $fitsPath === '') {
            return $this->usage('--fits is required');
        }
        if ($at === null || preg_match('/^[1-9][0-9]*$/', $at) !== 1) {
            return $this->usage('--at must be a positive integer');
        }
        $raw = is_file($fitsPath) ? file_get_contents($fitsPath) : false;
        if ($raw === false) {
            return $this->usage('--fits is not a readable file');
        }
        try {
            $fits = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($fits) || !isset($fits['routes']) || !is_array($fits['routes'])) {
                throw new \InvalidArgumentException('the file has no routes');
            }
            $result = Predictor::predict($fits, (int) $at);
        } catch (\Throwable $e) {
            return $this->usage('cannot read the fits: ' . $e->getMessage());
        }
        fwrite($this->out, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return 0;
    }

    private function usage(string $message): int
    {
        fwrite($this->err, "bin/predict: {$message}\nusage: bin/predict --fits=<fits.json> --at=<N> > predictions.json\n");

        return 2;
    }
}
