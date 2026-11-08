<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

use PeakSim\Runtime\Config\MeasureRefused;

/**
 * The gate for every statement that comes out of a capture file. The only thing such a statement may be, when it is sent,
 * is ONE statement that starts with `EXPLAIN FORMAT=JSON` and explains a data statement (SELECT, WITH, INSERT, UPDATE,
 * DELETE, REPLACE). Anything else is refused before it is sent, with `MeasureRefused` (exit code 10):
 *
 *  - a statement that does not start with `EXPLAIN FORMAT=JSON` (a DELETE or an UPDATE sent as it is would change the data);
 *  - `EXPLAIN FORMAT=JSON` of anything but a data statement (`EXPLAIN FORMAT=JSON EXPLAIN ...`, `... DROP ...`);
 *  - more than one statement (a `;` outside a quoted text, a quoted identifier or a comment);
 *  - a `/*! ... *\/` or `/*M! ... *\/` comment, which the server runs as SQL.
 *
 * It reads the text with a small tokenizer that knows the three quotes (`'`, `"`, backtick: a doubled quote and a
 * backslash inside a text are skipped) and the three comments (`-- `, `#`, `/* *\/`), so a `;` inside a literal is not a
 * second statement. A text it cannot finish reading (an unclosed quote or comment) is refused: the server could read it
 * another way.
 *
 * This is one of two doors (the other is `StatsStatements`, which sends statements written in the tool and never takes
 * a text from outside). A text from a capture file goes through this one only: `ExplainSender` calls `assertSendable`
 * before it prepares anything.
 */
final class ExplainGate
{
    public const PREFIX = 'EXPLAIN FORMAT=JSON ';

    /** The first word of what may be explained. */
    private const EXPLAINABLE = ['select', 'with', 'insert', 'update', 'delete', 'replace'];

    /**
     * @throws MeasureRefused
     */
    public static function assertSendable(string $sql): void
    {
        if (!str_starts_with($sql, self::PREFIX)) {
            throw new MeasureRefused(sprintf('refused: only a statement that starts with EXPLAIN FORMAT=JSON may be sent (got %s)', self::head($sql)));
        }
        $body = substr($sql, strlen(self::PREFIX));

        $code = self::codeOnly($body); // the text with quoted texts and comments blanked out
        if (str_contains($code, ';')) {
            throw new MeasureRefused(sprintf('refused: more than one statement (a ; outside a quoted text) in %s', self::head($sql)));
        }
        $first = preg_match('/^\s*(?:\(\s*)*([A-Za-z]+)/', $code, $m) === 1 ? strtolower($m[1]) : '';
        if (!in_array($first, self::EXPLAINABLE, true)) {
            throw new MeasureRefused(sprintf('refused: EXPLAIN FORMAT=JSON may be asked of SELECT, WITH, INSERT, UPDATE, DELETE or REPLACE only (got %s)', self::head($sql)));
        }
    }

    /** Whether the first word of the (unprefixed) statement is one that can be explained. */
    public static function isExplainableKind(string $sql): bool
    {
        $first = preg_match('/^\s*(?:\(\s*)*([A-Za-z]+)/', $sql, $m) === 1 ? strtolower($m[1]) : '';

        return in_array($first, self::EXPLAINABLE, true);
    }

    /**
     * The statement with every quoted text, quoted name and comment replaced by a space; what is left is the code the
     * server reads as statements. With `$keepQuotedNames` a name in backticks is left as it is (the tables a statement
     * names are read from it); the texts and the comments are blanked either way.
     *
     * @throws MeasureRefused for an unclosed quote or comment, and for a comment the server executes
     */
    public static function codeOnly(string $sql, bool $keepQuotedNames = false): string
    {
        $out = '';
        $n = strlen($sql);
        $i = 0;
        while ($i < $n) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $end = self::skipQuoted($sql, $i, $c);
                // quoted names can be kept (to read table names); a quoted text never is
                $out .= $c === '`' && $keepQuotedNames ? substr($sql, $i, $end - $i) : ' ';
                $i = $end;
                continue;
            }
            if ($c === '#') {
                $i = self::skipLine($sql, $i);
                $out .= ' ';
                continue;
            }
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-' && (self::isSpace($sql[$i + 2] ?? "\n") || $i + 2 >= $n)) {
                $i = self::skipLine($sql, $i);
                $out .= ' ';
                continue;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $third = $sql[$i + 2] ?? '';
                if ($third === '!' || ($third === 'M' && ($sql[$i + 3] ?? '') === '!')) {
                    throw new MeasureRefused('refused: a /*! or /*M! comment is run by the server as SQL');
                }
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new MeasureRefused('refused: a comment is not closed');
                }
                $i = $end + 2;
                $out .= ' ';
                continue;
            }
            $out .= $c;
            ++$i;
        }

        return $out;
    }

    private static function skipQuoted(string $sql, int $start, string $quote): int
    {
        $n = strlen($sql);
        $i = $start + 1;
        while ($i < $n) {
            $c = $sql[$i];
            if ($c === '\\' && $quote !== '`') {
                $i += 2;
                continue;
            }
            if ($c === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2; // a doubled quote is a quote inside the text
                    continue;
                }

                return $i + 1;
            }
            ++$i;
        }

        throw new MeasureRefused('refused: a quoted text is not closed');
    }

    private static function skipLine(string $sql, int $start): int
    {
        $end = strpos($sql, "\n", $start);

        return $end === false ? strlen($sql) : $end + 1;
    }

    private static function isSpace(string $c): bool
    {
        return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r";
    }

    /** Keeps a refusal message on one short, safe line (the text is untrusted). */
    private static function head(string $sql): string
    {
        return json_encode(substr($sql, 0, 60), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '""';
    }
}
