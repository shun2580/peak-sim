<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Selfcheck;

/**
 * The order of a self-check, kept in code: the estimate is written to its files first and sealed, and only then may a
 * measurement start (docs/simulator-spec.md 7.1 and 8.1).
 *
 * Sealing records the sha256 of every estimate file in `seal.json` and writes one line to `order.log`. Starting the
 * measurement goes through {@see self::beginMeasurement()}, which refuses (SelfcheckOrderViolation) unless the seal exists,
 * is the first line of the order record's story (`estimate_sealed` before anything else), and every file still has the hash
 * that was recorded. The judge opens the same files through {@see self::verify()} again, so the estimate that is compared with
 * the measurement is the one that was sealed.
 *
 * `bin/selfcheck` calls beginMeasurement through `selfcheck gate` before it starts `bin/measure`; a measurement that
 * did not go through it does not exist as far as the order record is concerned, and the judge refuses a run whose
 * record has no `measure_started` line after the `estimate_sealed` line.
 */
final class EstimateSeal
{
    public const SCHEMA_VERSION = '0.1';

    public const SEAL_FILE = 'seal.json';

    public const ORDER_LOG = 'order.log';

    public const EVENT_SEALED = 'estimate_sealed';

    public const EVENT_MEASURE_STARTED = 'measure_started';

    /**
     * Hashes the files that must already be written in $dir, records them, and appends `estimate_sealed` to the order log.
     * A directory that is already sealed is never sealed again (the seal would no longer say what came first).
     *
     * @param list<string> $files names of files directly in $dir (no path parts)
     *
     * @return array<string, mixed> the seal as written
     */
    public static function seal(string $dir, array $files, \DateTimeImmutable $now): array
    {
        if ($files === []) {
            throw new \InvalidArgumentException('nothing to seal');
        }
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException('not a directory: ' . $dir);
        }
        if (is_file($dir . '/' . self::SEAL_FILE)) {
            throw new SelfcheckOrderViolation('already sealed; a seal is written once');
        }
        $hashes = [];
        foreach ($files as $name) {
            if ($name === '' || basename($name) !== $name || $name === self::SEAL_FILE || $name === self::ORDER_LOG) {
                throw new \InvalidArgumentException('not a plain file name: ' . $name);
            }
            $hash = is_file($dir . '/' . $name) ? hash_file('sha256', $dir . '/' . $name) : false;
            if ($hash === false) {
                throw new SelfcheckOrderViolation('cannot seal: ' . $name . ' is not written yet');
            }
            $hashes[$name] = $hash;
        }
        ksort($hashes);
        $seal = [
            'schema_version' => self::SCHEMA_VERSION,
            'sealed_at' => $now->format(\DATE_ATOM),
            'sha256' => $hashes,
        ];
        self::writeAtomically($dir . '/' . self::SEAL_FILE, self::encode($seal));
        self::appendLog($dir, self::EVENT_SEALED, $now, $hashes);

        return $seal;
    }

    /**
     * The seal as recorded, with the hash of every file checked again.
     *
     * @return array<string, mixed>
     *
     * @throws SelfcheckOrderViolation when there is no seal, or a sealed file is missing or changed
     */
    public static function verify(string $dir): array
    {
        $seal = self::readSeal($dir);
        foreach ($seal['sha256'] as $name => $recorded) {
            $now = is_file($dir . '/' . $name) ? hash_file('sha256', $dir . '/' . $name) : false;
            if ($now === false) {
                throw new SelfcheckOrderViolation('a sealed file is gone: ' . $name);
            }
            if (!hash_equals((string) $recorded, $now)) {
                throw new SelfcheckOrderViolation('a sealed file changed after it was sealed: ' . $name);
            }
        }

        return $seal;
    }

    /**
     * The only way a measurement is allowed to start: the seal must be there and intact, and `estimate_sealed` must be the
     * first thing in the order log. Writes `measure_started` (with the hashes) to the order log.
     *
     * @return array<string, mixed> the seal
     *
     * @throws SelfcheckOrderViolation
     */
    public static function beginMeasurement(string $dir, \DateTimeImmutable $now): array
    {
        $seal = self::verify($dir);
        $events = self::events($dir);
        if ($events === [] || $events[0]['event'] !== self::EVENT_SEALED) {
            throw new SelfcheckOrderViolation('the order log does not begin with ' . self::EVENT_SEALED);
        }
        foreach ($events as $e) {
            if ($e['event'] === self::EVENT_MEASURE_STARTED) {
                throw new SelfcheckOrderViolation('a measurement was already started on this record');
            }
        }
        /** @var array<string, string> $hashes */
        $hashes = $seal['sha256'];
        self::appendLog($dir, self::EVENT_MEASURE_STARTED, $now, $hashes);

        return $seal;
    }

    /**
     * Checks that the order record tells the story in the right order, and that the files are still the sealed ones.
     * The judge calls this before it reads the estimate.
     *
     * @return array{seal: array<string, mixed>, sealed_at: string, measure_started_at: string}
     *
     * @throws SelfcheckOrderViolation
     */
    public static function verifyOrder(string $dir): array
    {
        $seal = self::verify($dir);
        $events = self::events($dir);
        $sealedAt = null;
        $startedAt = null;
        foreach ($events as $i => $e) {
            if ($e['event'] === self::EVENT_SEALED && $sealedAt === null) {
                $sealedAt = $e;
                if ($i !== 0) {
                    throw new SelfcheckOrderViolation('something was logged before ' . self::EVENT_SEALED);
                }
            }
            if ($e['event'] === self::EVENT_MEASURE_STARTED && $startedAt === null) {
                if ($sealedAt === null) {
                    throw new SelfcheckOrderViolation(self::EVENT_MEASURE_STARTED . ' comes before ' . self::EVENT_SEALED);
                }
                $startedAt = $e;
            }
        }
        if ($sealedAt === null) {
            throw new SelfcheckOrderViolation('the order log has no ' . self::EVENT_SEALED);
        }
        if ($startedAt === null) {
            throw new SelfcheckOrderViolation('the order log has no ' . self::EVENT_MEASURE_STARTED . ': the measurement did not go through the gate');
        }
        if (new \DateTimeImmutable($startedAt['at']) < new \DateTimeImmutable($sealedAt['at'])) {
            throw new SelfcheckOrderViolation(self::EVENT_MEASURE_STARTED . ' is dated before ' . self::EVENT_SEALED);
        }
        if ($startedAt['sha256'] !== $sealedAt['sha256'] || $sealedAt['sha256'] !== $seal['sha256']) {
            throw new SelfcheckOrderViolation('the hashes in the order log are not those of the seal');
        }

        return ['seal' => $seal, 'sealed_at' => $sealedAt['at'], 'measure_started_at' => $startedAt['at']];
    }

    /** @return array<string, mixed> */
    private static function readSeal(string $dir): array
    {
        $path = $dir . '/' . self::SEAL_FILE;
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new SelfcheckOrderViolation('the estimate is not sealed: there is no ' . self::SEAL_FILE);
        }
        try {
            $seal = json_decode($raw, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SelfcheckOrderViolation(self::SEAL_FILE . ' is not JSON: ' . $e->getMessage());
        }
        if (!is_array($seal) || !isset($seal['sealed_at']) || !isset($seal['sha256']) || !is_array($seal['sha256']) || $seal['sha256'] === []) {
            throw new SelfcheckOrderViolation(self::SEAL_FILE . ' has no sealed_at or no hashes');
        }

        return $seal;
    }

    /**
     * @return list<array{event: string, at: string, sha256: array<string, string>}>
     */
    private static function events(string $dir): array
    {
        $path = $dir . '/' . self::ORDER_LOG;
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            return [];
        }
        $events = [];
        foreach (preg_split('/\R/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            // "<event> <iso time> <name>=<sha256> <name>=<sha256> ..."
            $parts = explode(' ', $line);
            $event = array_shift($parts);
            $at = array_shift($parts);
            $hashes = [];
            foreach ($parts as $p) {
                $eq = strpos($p, '=');
                if ($eq !== false) {
                    $hashes[substr($p, 0, $eq)] = substr($p, $eq + 1);
                }
            }
            ksort($hashes);
            $events[] = ['event' => (string) $event, 'at' => (string) $at, 'sha256' => $hashes];
        }

        return $events;
    }

    /** @param array<string, string> $hashes */
    private static function appendLog(string $dir, string $event, \DateTimeImmutable $now, array $hashes): void
    {
        $parts = [$event, $now->format(\DATE_ATOM)];
        foreach ($hashes as $name => $hash) {
            $parts[] = $name . '=' . $hash;
        }
        if (file_put_contents($dir . '/' . self::ORDER_LOG, implode(' ', $parts) . "\n", \FILE_APPEND | \LOCK_EX) === false) {
            throw new \RuntimeException('cannot write the order log');
        }
    }

    private static function writeAtomically(string $path, string $contents): void
    {
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents) === false || !rename($tmp, $path)) {
            throw new \RuntimeException('cannot write ' . $path);
        }
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
    }
}
