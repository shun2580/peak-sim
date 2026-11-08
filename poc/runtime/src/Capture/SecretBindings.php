<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Capture;

/**
 * Keeps what a statement was bound to out of a record when the value is a secret.
 *
 * A bound value has no name, only a place (the n-th `?`), so the column it is compared with or written to is found from
 * the SQL: `col = ?`, `col in (?, ?)`, `col between ? and ?`, `col like ?`, `update t set col = ?` and
 * `insert into t (a, b) values (?, ?)`. A value whose column name matches `PATTERN` is replaced by `[redacted]`. A `?`
 * whose column cannot be found is replaced as well when the statement names a secret column anywhere (so a shape this
 * does not understand fails closed). A string literal written into the SQL next to a secret column is replaced too.
 *
 * Only values go; the SQL text and the other values are kept, so a plan can still be asked for with them.
 */
final class SecretBindings
{
    public const REDACTED = '[redacted]';

    /** A column whose name has one of these in it holds a secret. */
    public const PATTERN = '/password|passwd|passphrase|pwd|secret|token|api_?key|credential|recovery_?code|private_?key|otp|two_?factor|authorization|bearer|cookie|signature|salt|cvv/i';

    private const COMPARE = ['=', '<', '>', '<=', '>=', '<>', '!=', 'like', 'regexp', 'rlike', 'is'];

    public static function isSecretColumn(string $column): bool
    {
        return preg_match(self::PATTERN, $column) === 1;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<mixed>
     */
    public static function redactBindings(string $sql, array $bindings): array
    {
        $tokens = SqlTokens::lex($sql);
        if (!self::mentionsSecret($tokens)) {
            return $bindings;
        }
        $columns = self::slotColumns($tokens);
        $out = [];
        $n = 0;
        foreach ($tokens as $i => $token) {
            if ($token[0] !== 'param') {
                continue;
            }
            $column = $columns[$i] ?? null;
            if (array_key_exists($n, $bindings)) {
                $out[$n] = ($column === null || self::isSecretColumn($column)) ? self::REDACTED : $bindings[$n];
            }
            $n++;
        }
        // bindings beyond the placeholders found (should not happen): redact, never guess
        foreach ($bindings as $k => $value) {
            if (!array_key_exists($k, $out)) {
                $out[$k] = self::REDACTED;
            }
        }
        ksort($out);

        return array_values($out);
    }

    /** The SQL with a string literal that belongs to a secret column replaced by `'[redacted]'`. */
    public static function redactSql(string $sql): string
    {
        $tokens = SqlTokens::lex($sql);
        if (!self::mentionsSecret($tokens)) {
            return $sql;
        }
        $columns = self::slotColumns($tokens);
        $out = '';
        $at = 0;
        foreach ($tokens as $i => $token) {
            if ($token[0] !== 'string') {
                continue;
            }
            $column = $columns[$i] ?? null;
            if ($column === null || !self::isSecretColumn($column)) {
                continue;
            }
            $out .= substr($sql, $at, $token[2] - $at) . "'" . self::REDACTED . "'";
            $at = $token[2] + strlen($token[1]);
        }

        return $out . substr($sql, $at);
    }

    /** @param list<array{0: string, 1: string, 2: int}> $tokens */
    private static function mentionsSecret(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token[0] === 'ident' && self::isSecretColumn($token[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * For every value slot (a `?`, a string or a number), the column it belongs to, by token index. A slot with no
     * column found has no entry.
     *
     * @param list<array{0: string, 1: string, 2: int}> $tokens
     *
     * @return array<int, string>
     */
    private static function slotColumns(array $tokens): array
    {
        $columns = [];
        $count = count($tokens);
        $first = $count > 0 ? strtolower($tokens[0][1]) : '';
        if (($first === 'insert' || $first === 'replace')) {
            $columns = self::insertColumns($tokens);
        }

        foreach ($tokens as $i => $token) {
            if (!in_array($token[0], ['param', 'string', 'number'], true) || isset($columns[$i])) {
                continue;
            }
            $column = self::columnBefore($tokens, $i);
            if ($column !== null) {
                $columns[$i] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param list<array{0: string, 1: string, 2: int}> $tokens
     */
    private static function columnBefore(array $tokens, int $i): ?string
    {
        $j = $i - 1;
        // a list: `col in (?, ?, ?)`: walk back over values and commas to the opening parenthesis
        while ($j >= 0 && (in_array($tokens[$j][0], ['param', 'string', 'number'], true) || ($tokens[$j][0] === 'punct' && $tokens[$j][1] === ','))) {
            $j--;
        }
        if ($j >= 0 && $tokens[$j][0] === 'punct' && $tokens[$j][1] === '(' && $j < $i - 1) {
            $j--;
            if ($j >= 0 && strtolower($tokens[$j][1]) === 'in') {
                return self::identBefore($tokens, $j - 1 >= 0 && strtolower($tokens[$j - 1][1]) === 'not' ? $j - 2 : $j - 1);
            }

            return null;
        }
        $j = $i - 1;
        if ($j < 0) {
            return null;
        }
        $word = strtolower($tokens[$j][1]);
        if ($tokens[$j][0] === 'punct' && $tokens[$j][1] === '(' ) {
            return null;
        }
        // `col between ? and ?`: the first value follows `between`, the second follows `and`
        if ($word === 'and' && $j >= 2 && in_array($tokens[$j - 1][0], ['param', 'string', 'number'], true) && strtolower($tokens[$j - 2][1]) === 'between') {
            return self::identBefore($tokens, $j - 3);
        }
        if ($word === 'between') {
            return self::identBefore($tokens, $j - 1);
        }
        if ($word === 'like' && $j >= 1 && strtolower($tokens[$j - 1][1]) === 'not') {
            return self::identBefore($tokens, $j - 2);
        }
        if (in_array($word, self::COMPARE, true)) {
            return self::identBefore($tokens, $j - 1);
        }

        return null;
    }

    /**
     * The column name that ends at token `$j`: `col`, `t.col` (the part after the dot), or `fn(col)` / `col)` (a function
     * around the column).
     *
     * @param list<array{0: string, 1: string, 2: int}> $tokens
     */
    private static function identBefore(array $tokens, int $j): ?string
    {
        while ($j >= 0 && $tokens[$j][0] === 'punct' && $tokens[$j][1] === ')') {
            $j--;
        }

        return $j >= 0 && $tokens[$j][0] === 'ident' ? $tokens[$j][1] : null;
    }

    /**
     * `insert into t (a, b) values (?, ?), (?, ?)` : the slot at position p of a tuple belongs to column p of the list.
     *
     * @param list<array{0: string, 1: string, 2: int}> $tokens
     *
     * @return array<int, string>
     */
    private static function insertColumns(array $tokens): array
    {
        $count = count($tokens);
        $names = [];
        $i = 0;
        while ($i < $count && !($tokens[$i][0] === 'punct' && $tokens[$i][1] === '(') && strtolower($tokens[$i][1]) !== 'values' && strtolower($tokens[$i][1]) !== 'set') {
            $i++;
        }
        if ($i >= $count || $tokens[$i][1] !== '(') {
            return [];
        }
        for ($i++; $i < $count && $tokens[$i][1] !== ')'; $i++) {
            if ($tokens[$i][0] === 'ident') {
                $names[] = $tokens[$i][1];
            }
        }
        while ($i < $count && strtolower($tokens[$i][1]) !== 'values') {
            $i++;
        }
        $columns = [];
        $position = -1;
        $depth = 0;
        for ($i++; $i < $count; $i++) {
            [$kind, $text] = $tokens[$i];
            if ($kind === 'punct' && $text === '(') {
                $depth++;
                if ($depth === 1) {
                    $position = 0;
                }

                continue;
            }
            if ($kind === 'punct' && $text === ')') {
                $depth--;
                if ($depth === 0) {
                    $position = -1;
                }

                continue;
            }
            if ($depth === 1 && $kind === 'punct' && $text === ',') {
                $position++;

                continue;
            }
            if ($depth === 1 && in_array($kind, ['param', 'string', 'number'], true) && isset($names[$position])) {
                $columns[$i] = $names[$position];
            }
        }

        return $columns;
    }
}
