<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * The DB container's CPU utilization over a window, from the cgroup's `cpu.stat`:
 *
 *     utilization = delta(usage_usec) / (delta(time) x CPUs)         with CPUs = 2 (the DB container's limit)
 *
 * `cpu.stat` is read inside the DB container (`/sys/fs/cgroup/cpu.stat` on cgroup v2) a few seconds apart for the whole
 * scenario; the usage at the two edges of the kept window is interpolated between the two samples around each edge. The
 * window is the whole kept part (8 minutes), never an instant. If `usage_usec` cannot be read, or the samples do not
 * cover the window, this throws and the caller stops.
 */
final class DbUtilization
{
    public const CPUS = 2;

    /** @throws CpuStatUnreadable the text has no `usage_usec` line */
    public static function parseUsageUsec(string $cpuStat): int
    {
        if (preg_match('/^usage_usec[ \t]+(\d+)[ \t]*$/m', $cpuStat, $m) !== 1) {
            throw new CpuStatUnreadable('cpu.stat has no usage_usec line; stop and ask, do not use an instantaneous figure instead');
        }

        return (int) $m[1];
    }

    /**
     * Parses the sampler's file: one line per sample, `<epoch seconds, decimal> <usage_usec>`.
     *
     * @return list<array{t: float, usage_usec: int}>
     *
     * @throws CpuStatUnreadable a line is malformed
     */
    public static function parseSamples(string $text): array
    {
        $samples = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^(\d+(?:\.\d+)?)[ \t]+(\d+)$/', trim($line), $m) !== 1) {
                throw new CpuStatUnreadable(sprintf('malformed sample line: %s', $line));
            }
            $samples[] = ['t' => (float) $m[1], 'usage_usec' => (int) $m[2]];
        }

        return $samples;
    }

    /**
     * @param list<array{t: float, usage_usec: int}> $samples in time order
     *
     * @return array{utilization: float, percent: float, delta_usage_usec: float, delta_sec: float, cpus: int}
     *
     * @throws CpuStatUnreadable the samples do not cover the window, go back in time, or the usage goes down
     */
    public static function fromSamples(array $samples, float $windowStart, float $windowEnd, int $cpus = self::CPUS): array
    {
        if ($windowEnd <= $windowStart || $cpus < 1) {
            throw new \InvalidArgumentException('the window must have a positive length and there must be at least one CPU');
        }
        for ($i = 1; $i < count($samples); ++$i) {
            if ($samples[$i]['t'] <= $samples[$i - 1]['t']) {
                throw new CpuStatUnreadable('the samples are not in time order');
            }
            if ($samples[$i]['usage_usec'] < $samples[$i - 1]['usage_usec']) {
                throw new CpuStatUnreadable('usage_usec went down (the container was restarted?)');
            }
        }
        $deltaUsec = self::usageAt($samples, $windowEnd) - self::usageAt($samples, $windowStart);
        $deltaSec = $windowEnd - $windowStart;
        $utilization = $deltaUsec / ($deltaSec * 1_000_000 * $cpus);

        return ['utilization' => $utilization, 'percent' => $utilization * 100, 'delta_usage_usec' => $deltaUsec, 'delta_sec' => $deltaSec, 'cpus' => $cpus];
    }

    /** @param list<array{t: float, usage_usec: int}> $samples */
    private static function usageAt(array $samples, float $t): float
    {
        $n = count($samples);
        if ($n < 2 || $t < $samples[0]['t'] || $t > $samples[$n - 1]['t']) {
            throw new CpuStatUnreadable(sprintf('the samples do not cover time %.3f', $t));
        }
        for ($i = 1; $i < $n; ++$i) {
            $a = $samples[$i - 1];
            $b = $samples[$i];
            if ($t <= $b['t']) {
                return $a['usage_usec'] + ($b['usage_usec'] - $a['usage_usec']) * (($t - $a['t']) / ($b['t'] - $a['t']));
            }
        }

        throw new CpuStatUnreadable('unreachable');
    }
}
