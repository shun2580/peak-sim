<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Capture;

/**
 * A small lexer for the SQL an app sends through its query builder: enough to tell identifiers, `?` placeholders, string
 * and number literals and punctuation apart. It is not a SQL parser and does not try to be one.
 *
 * Each token is `[kind, text, offset]`; kind is one of `ident` (bare or backticked, with the quotes taken off), `param`
 * (`?`), `string`, `number`, `punct` (one character) or `word` (not used: bare words are idents, the caller compares them
 * to keywords in lower case).
 */
final class SqlTokens
{
    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public static function lex(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;
        while ($i < $length) {
            $c = $sql[$i];
            if (ctype_space($c)) {
                $i++;

                continue;
            }
            if ($c === '?') {
                $tokens[] = ['param', '?', $i];
                $i++;

                continue;
            }
            if ($c === "'" || $c === '"') {
                $end = self::endOfString($sql, $i, $c);
                $tokens[] = ['string', substr($sql, $i, $end - $i), $i];
                $i = $end;

                continue;
            }
            if ($c === '`') {
                $end = strpos($sql, '`', $i + 1);
                $end = $end === false ? $length : $end + 1;
                $tokens[] = ['ident', substr($sql, $i + 1, max(0, $end - $i - 2)), $i];
                $i = $end;

                continue;
            }
            if (ctype_digit($c) || ($c === '-' && $i + 1 < $length && ctype_digit($sql[$i + 1]) && self::startsOperand($tokens))) {
                $start = $i;
                $i++;
                while ($i < $length && (ctype_digit($sql[$i]) || $sql[$i] === '.')) {
                    $i++;
                }
                if ($i < $length && (ctype_alpha($sql[$i]) || $sql[$i] === '_')) {
                    // `1st`, `0x1f`, `2fa`: an identifier that starts with a digit, not a number
                    while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '_' || $sql[$i] === '$')) {
                        $i++;
                    }
                    $tokens[] = ['ident', substr($sql, $start, $i - $start), $start];

                    continue;
                }
                $tokens[] = ['number', substr($sql, $start, $i - $start), $start];

                continue;
            }
            if (ctype_alpha($c) || $c === '_' || $c === '@') {
                $start = $i;
                $i++;
                while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '_' || $sql[$i] === '$')) {
                    $i++;
                }
                $tokens[] = ['ident', substr($sql, $start, $i - $start), $start];

                continue;
            }
            // two-character comparison operators stay together, so `<=` is not `<` then `=`
            $two = substr($sql, $i, 2);
            if (in_array($two, ['<=', '>=', '<>', '!=', '||', '&&', ':='], true)) {
                $tokens[] = ['punct', $two, $i];
                $i += 2;

                continue;
            }
            $tokens[] = ['punct', $c, $i];
            $i++;
        }

        return $tokens;
    }

    /** The offset just after the string that starts at `$from` (a quote character is doubled or escaped inside). */
    private static function endOfString(string $sql, int $from, string $quote): int
    {
        $length = strlen($sql);
        $i = $from + 1;
        while ($i < $length) {
            if ($sql[$i] === '\\') {
                $i += 2;

                continue;
            }
            if ($sql[$i] === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $i += 2;

                    continue;
                }

                return $i + 1;
            }
            $i++;
        }

        return $length;
    }

    /**
     * Whether a `-` here is a sign (after an operator, `(`, `,` or a keyword), not a subtraction.
     *
     * @param list<array{0: string, 1: string, 2: int}> $tokens
     */
    private static function startsOperand(array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }
        $last = $tokens[array_key_last($tokens)];

        return $last[0] === 'punct' && $last[1] !== ')' || ($last[0] === 'ident' && in_array(strtolower($last[1]), ['and', 'or', 'limit', 'offset', 'in', 'between', 'then', 'else', 'when', 'like', 'not', 'is'], true));
    }
}
