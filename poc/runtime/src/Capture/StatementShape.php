<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Capture;

/**
 * The type of a statement: its SQL text with the literals taken out, so that two texts that differ only in a value or in
 * the length of a list are the same type.
 *
 *  - every string and number literal becomes `?`;
 *  - whitespace is collapsed and a final `;` is dropped;
 *  - `in (?, ?, ?)` and `not in (?, ?)` become `in (?)` / `not in (?)`, whatever the length;
 *  - the repeated tuples of a multi-row `values (?, ?), (?, ?)` become one tuple.
 *
 * Nothing else is changed: identifier case, joins, column lists and the order of clauses stay, so two statements that
 * mean the same but are written differently are two types. The type has no value in it, so it is safe to write anywhere.
 */
final class StatementShape
{
    private const TUPLE = '\(\s*\?(?:\s*,\s*\?)*\s*\)';

    /** The pattern (the type) of a SQL text. */
    public static function pattern(string $sql): string
    {
        $out = '';
        $at = 0;
        foreach (SqlTokens::lex($sql) as [$kind, $text, $offset]) {
            if ($kind !== 'string' && $kind !== 'number') {
                continue;
            }
            $out .= substr($sql, $at, $offset - $at) . '?';
            $at = $offset + strlen($text);
        }
        $out .= substr($sql, $at);
        $out = preg_replace('/\s+/', ' ', $out) ?? $out;
        $out = rtrim(trim($out), "; ");

        return self::collapseLists($out);
    }

    private static function collapseLists(string $sql): string
    {
        $sql = preg_replace('/\b(not\s+in|in)\s*' . self::TUPLE . '/i', '$1 (?)', $sql) ?? $sql;

        return preg_replace('/(' . self::TUPLE . ')(?:\s*,\s*' . self::TUPLE . ')+/', '$1', $sql) ?? $sql;
    }
}
