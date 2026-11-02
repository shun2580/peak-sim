<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/**
 * Fills the `{parameters}` of a route with values that exist in the measurement database.
 *
 * For each parameter, in URI order (a parent before its scoped child), the first rule that gives a value wins:
 *  1. an implicit model binding: the route key (or the binding field) of the first row of the model's table; a
 *     scoped child takes the first row whose foreign key points at the parent row already chosen
 *  2. a name ending in `id` (`orderId`, `category_id`): the primary key of the first row of the table the prefix names
 *  3. a name that is itself a table (`category`, `piggyBank`, `orders`; singular or plural): the primary key of its first
 *     row, when that value does not break the parameter's `where` constraint
 *  4. the `where` constraint: its first alternative when it is a list of literals, else the first sample it matches
 *  5. a declared `int` type: 1
 *
 * An app profile (`arguments`, `unfillable`; see `profiles/`) adds what the rules cannot reach:
 *  - `arguments`: a parameter name => where its value comes from, the first row of a table's column (`table`,
 *    `column`) or a literal (`value`). It is tried after the implicit model binding (the app's own declaration, which
 *    a profile does not override) and before the guesses from a name, a constraint or a type.
 *  - `unfillable`: what could not be prepared, with a reason from the fixed vocabulary. It only explains: a
 *    parameter nothing could fill, whose name an entry lists in `params` (in the routes the entry names, when it names
 *    any: see `ProfileRoutes`), is recorded with that entry's reason as its class. It never stops a parameter the
 *    rules did fill. (An entry about a whole route is applied before this, by the task builder.)
 *
 * A value made up rather than read from a row (a declared `int` becomes 1, a `where` pattern gives a sample that
 * matches it; the first of a list of literals is the app's own value and does not count) is listed in `Resolution::$guessed`: the app may not have such a thing.
 *
 * A value that breaks the `where` constraint is not used. The row picked is the first one whatever the scale, so the
 * number of children of that row is the same at every N and only M can move it. An optional parameter that cannot be
 * filled is left out; any other is recorded with its name and a reason.
 */
final class ArgumentResolver
{
    /** The "reason" of a value that was made up, not read from a row (the second element of `one()` for a value). */
    private const GUESS = 'guess';

    private const WHERE_SAMPLES = ['1', 'a', 'abc', 'x1', '0', '1a', '00000000-0000-4000-8000-000000000000', '01ARZ3NDEKTSV4RRFFQ69G5FAV', '2026-01-01'];

    /** @param array<string, mixed> $profile the app's profile (empty when none was asked for) */
    public function __construct(private readonly RowLookup $lookup, private readonly array $profile = [])
    {
    }

    public function resolve(RouteSpec $spec): Resolution
    {
        $values = [];
        $unfilled = [];
        $omitted = [];
        $guessed = [];

        foreach ($spec->parameters as $name => $p) {
            [$value, $reason] = $this->one($name, $p, $values);

            if ($value !== null && $p['where'] !== null && ! self::matches($p['where'], (string) $value)) {
                [$value, $reason] = [null, 'violates_where'];
            }
            if ($value !== null) {
                $values[$name] = $value;
                if ($reason === self::GUESS) {
                    $guessed[] = $name;
                }
            } elseif ($p['optional']) {
                $omitted[] = $name;
            } else {
                $unfilled[] = $this->explained($name, $reason, $spec);
            }
        }

        return new Resolution($values, $unfilled, $omitted, $guessed);
    }

    /**
     * @param array{optional: bool, where: ?string, model: ?string, field: ?string, type: ?string, scope: ?array{parent: string, fk: string}} $p
     * @param array<string, string|int> $chosen
     *
     * @return array{0: string|int|null, 1: string}
     */
    private function one(string $name, array $p, array $chosen): array
    {
        if ($p['model'] !== null) {
            $table = $this->lookup->tableForModel($p['model']);
            if ($table === null) {
                return $this->declared($name) ?? [null, 'no_table_for_model'];
            }
            $where = [];
            if ($p['scope'] !== null && isset($chosen[$p['scope']['parent']])) {
                $where = [$p['scope']['fk'] => $chosen[$p['scope']['parent']]];
            }
            $value = $this->lookup->firstValue($table, $p['field'] ?? $this->lookup->routeKeyColumn($p['model']), $where);

            return $value === null ? [null, 'no_row:' . $table] : [$value, ''];
        }

        $declared = $this->declared($name);
        if ($declared !== null) {
            return $declared;
        }

        if (preg_match('/^(.+?)_?[iI]d$/', $name, $m) === 1) {
            foreach (self::tableCandidates($m[1]) as $table) {
                if ($this->lookup->hasTable($table)) {
                    $value = $this->lookup->firstValue($table, $this->lookup->primaryKey($table) ?? 'id');
                    if ($value !== null) {
                        return [$value, ''];
                    }
                }
            }
        }

        // The name is itself a table (`{category}`, `{piggyBank}`, `{orders}`): the first row's primary key. A row whose key
        // breaks the `where` constraint is not used, and the constraint's own rule below gets its turn.
        foreach (self::tableCandidates($name) as $table) {
            if (!$this->lookup->hasTable($table)) {
                continue;
            }
            $value = $this->lookup->firstValue($table, $this->lookup->primaryKey($table) ?? 'id');
            if ($value !== null && ($p['where'] === null || self::matches($p['where'], (string) $value))) {
                return [$value, ''];
            }
        }

        if ($p['where'] !== null) {
            $picked = self::pickFromWhere($p['where']);

            // A list of literals (`daily|weekly`) is the app's own list of values; a sample of a pattern is a guess.
            $isList = preg_match('/[\[\](){}\\\\^$.*+?]/', $p['where']) !== 1;

            return $picked === null ? [null, 'where_unsatisfiable'] : [$picked, $isList ? '' : self::GUESS];
        }

        if ($p['type'] === 'int') {
            return [1, self::GUESS];
        }

        return [null, 'no_rule_to_fill'];
    }

    /**
     * What the profile says about a parameter, or null when it says nothing.
     *
     * @return array{0: string|int|null, 1: string}|null
     */
    private function declared(string $name): ?array
    {
        $how = $this->profile['arguments'][$name] ?? null;
        if (!is_array($how)) {
            return null;
        }
        if (array_key_exists('value', $how)) {
            return is_string($how['value']) || is_int($how['value']) ? [$how['value'], ''] : null;
        }
        if (!isset($how['table'], $how['column']) || !is_string($how['table']) || !is_string($how['column'])) {
            return null;
        }
        $value = $this->lookup->hasTable($how['table']) ? $this->lookup->firstValue($how['table'], $how['column']) : null;

        return $value === null ? [null, 'no_row:' . $how['table']] : [$value, ''];
    }

    /**
     * An unfilled parameter as it is recorded: with the profile's reason and class when an `unfillable` entry lists
     * the parameter, else as the rules left it.
     *
     * @return array{param: string, reason: string, class?: string}
     */
    private function explained(string $name, string $reason, RouteSpec $spec): array
    {
        foreach ((array) ($this->profile['unfillable'] ?? []) as $label => $entry) {
            if (is_array($entry) && in_array($name, (array) ($entry['params'] ?? []), true) && is_string($entry['reason'] ?? null)
                && ProfileRoutes::covers($entry, $spec->uri, $spec->middleware, $spec->action)) {
                return ['param' => $name, 'reason' => 'profile:' . $label, 'class' => $entry['reason']];
            }
        }

        return ['param' => $name, 'reason' => $reason];
    }

    /** @return list<string> */
    private static function tableCandidates(string $prefix): array
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $prefix));
        $plural = match (true) {
            (bool) preg_match('/[^aeiou]y$/', $snake) => substr($snake, 0, -1) . 'ies',
            (bool) preg_match('/(s|x|z|ch|sh)$/', $snake) => $snake . 'es',
            default => $snake . 's',
        };

        return array_values(array_unique([$snake, $snake . 's', $plural]));
    }

    private static function pickFromWhere(string $pattern): ?string
    {
        if (preg_match('/[\[\](){}\\\\^$.*+?]/', $pattern) !== 1) {
            return explode('|', $pattern)[0];
        }
        foreach (self::WHERE_SAMPLES as $sample) {
            if (self::matches($pattern, $sample)) {
                return $sample;
            }
        }

        return null;
    }

    private static function matches(string $pattern, string $value): bool
    {
        return @preg_match('~^(?:' . str_replace('~', '\\~', $pattern) . ')$~', $value) === 1;
    }
}
