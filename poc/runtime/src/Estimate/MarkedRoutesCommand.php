<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Estimate;

/**
 * bin/marked-routes --explain=<explain.json> prints the marked routes (MarkedRoutes) on standard output. With --estimate=<estimate.json> (the H1_RANGE estimate) it adds,
 * under `offset_chunk_fit`, the routes that carry that mark (MarkedRoutes::offsetChunkFit); without it the output is as it was.
 * Exit codes: 0 done; 2 wrong usage or an unreadable input.
 */
final class MarkedRoutesCommand
{
    private const USAGE = 'usage: bin/marked-routes --explain=<explain.json> [--estimate=<estimate.json>] > marked-routes.json';

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
        $path = null;
        $estimatePath = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--estimate=')) {
                $estimatePath = substr($arg, strlen('--estimate='));
            } elseif (str_starts_with($arg, '--explain=')) {
                $path = substr($arg, strlen('--explain='));
            } else {
                return $this->usage('unknown argument: ' . $arg);
            }
        }
        if ($path === null || $path === '') {
            return $this->usage('--explain is required');
        }
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            return $this->usage('--explain is not a readable file');
        }
        try {
            $explain = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->usage('--explain is not JSON: ' . $e->getMessage());
        }
        if (!is_array($explain)) {
            return $this->usage('--explain is not a JSON object');
        }
        $out = MarkedRoutes::list($explain);
        if ($estimatePath !== null) {
            $rawEstimate = $estimatePath !== '' && is_file($estimatePath) ? file_get_contents($estimatePath) : false;
            if ($rawEstimate === false) {
                return $this->usage('--estimate is not a readable file');
            }
            try {
                $estimate = json_decode($rawEstimate, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return $this->usage('--estimate is not JSON: ' . $e->getMessage());
            }
            if (!is_array($estimate)) {
                return $this->usage('--estimate is not a JSON object');
            }
            $out['offset_chunk_fit'] = MarkedRoutes::offsetChunkFit($estimate);
        }
        fwrite($this->out, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return 0;
    }

    private function usage(string $message): int
    {
        fwrite($this->err, "bin/marked-routes: {$message}\n" . self::USAGE . "\n");

        return 2;
    }
}
