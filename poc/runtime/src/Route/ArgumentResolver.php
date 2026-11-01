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
 *  3. the `where` constraint: its first alternative when it is a list of literals, else the first sample it matches
 *  4. a declared `int` type: 1
 * A value that breaks the `where` constraint is not used. The row picked is the first one whatever the scale, so the
 * number of children of that row is the same at every N and only M can move it. An optional parameter that cannot be
 * filled is left out; any other is recorded with its name and a reason.
 */
final class ArgumentResolver
{
    private const WHERE_SAMPLES = ['1', 'a', 'abc', 'x1', '0', '1a', '00000000-0000-4000-8000-000000000000', '01ARZ3NDEKTSV4RRFFQ69G5FAV', '2026-01-01'];

    public function __construct(private readonly RowLookup $lookup)
    {
    }

    public function resolve(RouteSpec $spec): Resolution
    {
        $values = [];
        $unfilled = [];
        $omitted = [];

        foreach ($spec->parameters as $name => $p) {
            [$value, $reason] = $this->one($name, $p, $values);

            if ($value !== null && $p['where'] !== null && ! self::matches($p['where'], (string) $value)) {
                [$value, $reason] = [null, 'violates_where'];
            }
            if ($value !== null) {
                $values[$name] = $value;
            } elseif ($p['optional']) {
                $omitted[] = $name;
            } else {
                $unfilled[] = ['param' => $name, 'reason' => $reason];
            }
        }

        return new Resolution($values, $unfilled, $omitted);
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
                return [null, 'no_table_for_model'];
            }
            $where = [];
            if ($p['scope'] !== null && isset($chosen[$p['scope']['parent']])) {
                $where = [$p['scope']['fk'] => $chosen[$p['scope']['parent']]];
            }
            $value = $this->lookup->firstValue($table, $p['field'] ?? $this->lookup->routeKeyColumn($p['model']), $where);

            return $value === null ? [null, 'no_row:' . $table] : [$value, ''];
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

        if ($p['where'] !== null) {
            $picked = self::pickFromWhere($p['where']);

            return $picked === null ? [null, 'where_unsatisfiable'] : [$picked, ''];
        }

        if ($p['type'] === 'int') {
            return [1, ''];
        }

        return [null, 'no_rule_to_fill'];
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
