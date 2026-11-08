<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Capture;

/** Reads the per-statement records a run left in one directory (one file per route and scale; see `Driver::tapFile`). */
final class TapDirectory
{
    /**
     * @return array<string, array<int, array<string, mixed>>> route key ("METHOD uri") => scale => the record
     *
     * @throws \RuntimeException when a file is not a record
     */
    public static function read(string $dir): array
    {
        $files = glob(rtrim($dir, '/') . '/*.jsonl');
        if ($files === false) {
            return [];
        }
        sort($files);
        $taps = [];
        foreach ($files as $file) {
            $text = trim((string) file_get_contents($file));
            if ($text === '') {
                continue;
            }
            $doc = json_decode($text, true);
            if (!is_array($doc) || !isset($doc['route'], $doc['method'], $doc['scale'], $doc['statements']) || !is_array($doc['statements'])) {
                throw new \RuntimeException('not a statement record: ' . basename($file));
            }
            if (strtoupper((string) ($doc['axis'] ?? 'N')) !== 'N') {
                continue;
            }
            $taps[$doc['method'] . ' ' . $doc['route']][(int) $doc['scale']] = $doc;
        }
        ksort($taps);

        return $taps;
    }
}
