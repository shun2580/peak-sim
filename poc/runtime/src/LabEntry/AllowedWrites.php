<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabEntry;

/**
 * The tables the lab's entry script may write (poc/lab/README.md, safety condition 6). The BookStack lab has always
 * allowed one, `views`; another app's lab names its own tables in a file and points the entry at it, so the rule is the same
 * and only the list differs.
 *
 * The file is a list of table names, one to a line. A `#` starts a comment, blank lines are skipped, and a name is letters, digits
 * and underscores (so a name that carries a schema, a quote or a space is an error, not a name that is guessed at). The names are
 * case sensitive, as the lab's server is. A file that names nothing allows no write at all. No file named means `views` alone.
 * poc/lab/bin/check-writes and poc/lab/bin/check-tables read the same file in the same way (one test holds the three together).
 */
final class AllowedWrites
{
    /** What the lab allowed before a list could be given. */
    public const DEFAULT = ['views'];

    /**
     * @return list<string>
     *
     * @throws \InvalidArgumentException a line that is not a table name
     */
    public static function parse(string $text): array
    {
        $names = [];
        foreach (preg_split('/\R/', $text) ?: [] as $number => $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_]+$/D', $line) !== 1) {
                throw new \InvalidArgumentException(sprintf('line %d is not a table name: %s', $number + 1, mb_substr($line, 0, 60)));
            }
            $names[$line] = true;
        }

        return array_map('strval', array_keys($names));
    }

    /**
     * @return list<string>
     *
     * @throws \InvalidArgumentException the file cannot be read, or holds a line that is not a table name
     */
    public static function fromFile(string $path): array
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new \InvalidArgumentException('the list of tables the lab may write cannot be read: ' . $path);
        }

        return self::parse($text);
    }

    /**
     * The list the environment points at, or `views` alone when it points at none.
     *
     * @param array<string, string> $env
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException a file is named and cannot be read (it fails closed: the entry does not start)
     */
    public static function fromEnv(array $env): array
    {
        $path = (string) ($env['PEAK_LAB_ALLOWED_WRITES_FILE'] ?? '');

        return $path === '' ? self::DEFAULT : self::fromFile($path);
    }
}
