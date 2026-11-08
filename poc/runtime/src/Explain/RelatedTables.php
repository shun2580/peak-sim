<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

/**
 * The table names a statement mentions after FROM, JOIN, UPDATE, INTO (and in a comma list after FROM), read from the
 * text of a captured statement. They are hints only: `StatsStatements::relevant` keeps the ones that are tables of the
 * database, and only those names are ever used.
 */
final class RelatedTables
{
    /** @return list<string> */
    public static function of(string $sql): array
    {
        try {
            $code = ExplainGate::codeOnly($sql, true);
        } catch (\Throwable) {
            return [];
        }
        $names = [];
        $name = '`?([A-Za-z0-9_]+)`?';
        if (preg_match_all('/\b(?:from|join|update|into)\s+(?:`?[A-Za-z0-9_]+`?\s*\.\s*)?' . $name . '/i', $code, $m) > 0) {
            $names = $m[1];
        }
        // from a, b, c
        if (preg_match_all('/\bfrom\s+((?:`?[A-Za-z0-9_]+`?(?:\s+(?:as\s+)?`?[A-Za-z0-9_]+`?)?\s*,\s*)+`?[A-Za-z0-9_]+`?)/i', $code, $lists) > 0) {
            foreach ($lists[1] as $list) {
                foreach (explode(',', $list) as $part) {
                    if (preg_match('/^\s*`?([A-Za-z0-9_]+)`?/', $part, $one) === 1) {
                        $names[] = $one[1];
                    }
                }
            }
        }

        return array_values(array_unique(array_map(static fn (string $n): string => $n, $names)));
    }
}
