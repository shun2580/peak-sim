<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/**
 * Builds the body of a write request from the validation rules of its FormRequest (the first of three tries; the
 * others are `fromErrorKeys`, driven by the 422 answer).
 *
 * Only required fields are sent in the first body (`required*`, `present`, `accepted`): an optional field adds a
 * chance to fail validation and no query the route would not have anyway. Values are deterministic; `$i` is the
 * row index that goes into strings.
 *
 *   rule                         value
 *   string / none of the below   x_{i}              (padded to min:, cut to max:, exactly size:)
 *   integer, numeric             1                  (min: and max: respected; digits:n -> n ones)
 *   boolean                      1
 *   email                        user_{i}@example.test
 *   in:a,b                       the first value
 *   exists:table,col             col of the first row of table (field left out and reported when there is none)
 *   unique:table,col             peak_new_x_{i} / peak_new_{i}@example.test / 900000+{i}
 *   array                        []                 (with `f.*` and `f.*.g` rules: one element)
 *   confirmed                    also {field}_confirmation with the same value
 *   same:other                   the value of other
 *   file, image, mimes:..        a FileField
 *   url, uuid, ip, date, json, accepted ... fixed samples
 */
final class BodyBuilder
{
    private const ROUNDS = 3;

    public function __construct(private readonly RowLookup $lookup)
    {
    }

    /**
     * @param array<string, mixed> $rules field => rule string or list of rules
     */
    public function build(array $rules, int $i = 1): BodyBuild
    {
        $parsed = [];
        $unsupported = [];
        foreach ($rules as $field => $rule) {
            $parsed[(string) $field] = self::parse($rule, $unsupported);
        }

        $fields = [];
        $unfilled = [];
        $same = [];

        foreach ($parsed as $field => $tokens) {
            if (str_contains($field, '*')) {
                continue;
            }
            if (isset($tokens['prohibited']) || ! self::isRequired($tokens)) {
                continue;
            }
            $value = $this->value($field, $tokens, $i, $unfilled);
            if ($value === null) {
                continue;
            }
            if (isset($tokens['same'][0])) {
                $same[$field] = $tokens['same'][0];
            }
            $fields[$field] = $value;
            if (isset($tokens['confirmed'])) {
                $fields[$field . '_confirmation'] = $value;
            }
        }
        foreach ($same as $field => $other) {
            if (array_key_exists($other, $fields)) {
                $fields[$field] = $fields[$other];
            }
        }

        foreach ($fields as $field => $value) {
            if ($value === [] && isset($parsed[$field]['array'])) {
                $element = $this->wildcard($field, $parsed, $i, $unfilled);
                if ($element !== null) {
                    $fields[$field] = [$element];
                }
            }
        }

        return new BodyBuild($fields, $unfilled, array_values(array_unique($unsupported)));
    }

    /**
     * The retry body after a 422: the keys of `errors` filled with a value of a guessed type, one type per round
     * (1 string, 2 integer, 3 array). A dotted key (`items.0.sku`) nests.
     *
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function fromErrorKeys(array $keys, int $round): array
    {
        if ($round < 1 || $round > self::ROUNDS) {
            throw new \InvalidArgumentException('a retry body is built for round 1 to 3');
        }
        $value = match ($round) {
            1 => 'x_1',
            2 => 1,
            default => [],
        };

        $body = [];
        foreach ($keys as $key) {
            $ref = &$body;
            $parts = explode('.', $key);
            $last = array_pop($parts);
            foreach ($parts as $part) {
                $part = ctype_digit($part) ? (int) $part : $part;
                if (! isset($ref[$part]) || ! is_array($ref[$part])) {
                    $ref[$part] = [];
                }
                $ref = &$ref[$part];
            }
            $ref[ctype_digit($last) ? (int) $last : $last] = $value;
            unset($ref);
        }

        return $body;
    }

    /**
     * @param array<string, array<int|string, list<string>>> $tokens
     * @param list<array{field: string, reason: string}> $unfilled
     */
    private function value(string $field, array $tokens, int $i, array &$unfilled): mixed
    {
        if (isset($tokens['file']) || isset($tokens['image']) || isset($tokens['mimes']) || isset($tokens['mimetypes']) || isset($tokens['extensions'])) {
            return new FileField(isset($tokens['image']) ? 'image' : 'file');
        }
        if (isset($tokens['array'])) {
            return [];
        }
        if (isset($tokens['exists'])) {
            return $this->existing($field, $tokens['exists'], $unfilled);
        }

        $unique = isset($tokens['unique']);
        $numeric = isset($tokens['integer']) || isset($tokens['int']) || isset($tokens['numeric']) || isset($tokens['decimal']);

        if (isset($tokens['in'][0])) {
            return $tokens['in'][0];
        }

        if ($numeric) {
            if ($unique) {
                return 900000 + $i;
            }

            return self::bounded(1, $tokens);
        }
        if (isset($tokens['digits'][0])) {
            return str_repeat('1', max(1, (int) $tokens['digits'][0]));
        }
        if (isset($tokens['boolean']) || isset($tokens['bool'])) {
            return 1;
        }
        if (isset($tokens['email'])) {
            return $unique ? sprintf('peak_new_%d@example.test', $i) : sprintf('user_%d@example.test', $i);
        }
        $fixed = self::fixedSample($tokens);
        if ($fixed !== null) {
            return $fixed;
        }

        $base = isset($tokens['alpha_num']) || isset($tokens['alpha_dash']) ? 'abc1' : (isset($tokens['alpha']) ? 'abc' : sprintf('x_%d', $i));
        if ($unique) {
            $base = 'peak_new_' . $base;
        }

        return self::sized($base, $tokens);
    }

    /**
     * @param array<string, array<int|string, list<string>>> $parsed
     * @param list<array{field: string, reason: string}> $unfilled
     */
    private function wildcard(string $field, array $parsed, int $i, array &$unfilled): mixed
    {
        if (isset($parsed[$field . '.*'])) {
            return $this->value($field . '.*', $parsed[$field . '.*'], $i, $unfilled);
        }
        $element = [];
        $prefix = $field . '.*.';
        foreach ($parsed as $key => $tokens) {
            if (! str_starts_with($key, $prefix) || str_contains(substr($key, strlen($prefix)), '*')) {
                continue;
            }
            if (isset($tokens['prohibited']) || ! self::isRequired($tokens)) {
                continue;
            }
            $value = $this->value($key, $tokens, $i, $unfilled);
            if ($value !== null) {
                $element[substr($key, strlen($prefix))] = $value;
            }
        }

        return $element === [] ? null : $element;
    }

    /**
     * @param list<string> $args
     * @param list<array{field: string, reason: string}> $unfilled
     */
    private function existing(string $field, array $args, array &$unfilled): string|int|null
    {
        $target = $args[0] ?? '';
        $column = ($args[1] ?? '') !== '' ? $args[1] : $field;
        if (str_contains($target, '\\')) {
            $table = $this->lookup->tableForModel($target);
        } else {
            $table = str_contains($target, '.') ? substr($target, (int) strrpos($target, '.') + 1) : $target;
        }

        $value = $table === null || $table === '' ? null : $this->lookup->firstValue($table, $column);
        if ($value === null) {
            $unfilled[] = ['field' => $field, 'reason' => 'exists_no_row:' . ($table ?? $target)];
        }

        return $value;
    }

    /** @param array<string, array<int|string, list<string>>> $tokens */
    private static function isRequired(array $tokens): bool
    {
        foreach (array_keys($tokens) as $name) {
            if (str_starts_with((string) $name, 'required') || $name === 'present' || $name === 'accepted') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, array<int|string, list<string>>> $tokens */
    private static function fixedSample(array $tokens): ?string
    {
        return match (true) {
            isset($tokens['url']), isset($tokens['active_url']) => 'https://example.test/x',
            isset($tokens['uuid']) => '00000000-0000-4000-8000-000000000000',
            isset($tokens['ulid']) => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            isset($tokens['ipv6']) => '2001:db8::1',
            isset($tokens['ip']), isset($tokens['ipv4']) => '192.0.2.1',
            isset($tokens['mac_address']) => '00:00:5e:00:53:01',
            isset($tokens['json']) => '{}',
            isset($tokens['timezone']) => 'UTC',
            isset($tokens['accepted']) => 'yes',
            isset($tokens['date_format'][0]) => (new \DateTimeImmutable('2026-01-01 00:00:00'))->format($tokens['date_format'][0]),
            isset($tokens['date']), isset($tokens['date_equals']), isset($tokens['before']), isset($tokens['after']) => '2026-01-01',
            default => null,
        };
    }

    /** @param array<string, array<int|string, list<string>>> $tokens */
    private static function bounded(int $value, array $tokens): int
    {
        if (isset($tokens['between'][0])) {
            return (int) $tokens['between'][0];
        }
        if (isset($tokens['size'][0])) {
            return (int) $tokens['size'][0];
        }
        if (isset($tokens['min'][0])) {
            $value = max($value, (int) $tokens['min'][0]);
        }
        if (isset($tokens['max'][0])) {
            $value = min($value, (int) $tokens['max'][0]);
        }

        return $value;
    }

    /** @param array<string, array<int|string, list<string>>> $tokens */
    private static function sized(string $base, array $tokens): string
    {
        $length = strlen($base);
        if (isset($tokens['size'][0])) {
            $length = (int) $tokens['size'][0];
        } elseif (isset($tokens['between'][0])) {
            $length = max($length, (int) $tokens['between'][0]);
        } else {
            if (isset($tokens['min'][0])) {
                $length = max($length, (int) $tokens['min'][0]);
            }
            if (isset($tokens['max'][0])) {
                $length = min($length, (int) $tokens['max'][0]);
            }
        }

        return $length >= strlen($base) ? str_pad($base, $length, 'x') : substr($base, 0, max(0, $length));
    }

    /**
     * rule string or list -> name => args. Rules the builder cannot satisfy are added to $unsupported.
     *
     * @param list<string> $unsupported
     *
     * @return array<string, list<string>>
     */
    private static function parse(mixed $rule, array &$unsupported): array
    {
        $items = is_array($rule) ? $rule : [$rule];
        $out = [];

        foreach ($items as $item) {
            if (is_object($item) && ! $item instanceof \Stringable) {
                $unsupported[] = 'object_rule';

                continue;
            }
            if (! is_string($item) && ! $item instanceof \Stringable) {
                continue;
            }
            $text = (string) $item;
            foreach (self::splitRules($text) as $token) {
                [$name, $argText] = array_pad(explode(':', $token, 2), 2, '');
                $name = strtolower(trim($name));
                if ($name === '') {
                    continue;
                }
                $args = $argText === '' ? [] : array_map(static fn (string $a): string => trim($a, " \t\"'"), explode(',', $argText));
                $out[$name] = $args;
                if (in_array($name, ['regex', 'not_regex', 'gt', 'gte', 'lt', 'lte', 'different', 'current_password', 'password'], true)) {
                    $unsupported[] = $name;
                }
            }
        }

        return $out;
    }

    /**
     * 'a|b|regex:/x|y/' keeps a regex whole: the part after `regex:` runs to the end of its delimiter pair.
     *
     * @return list<string>
     */
    private static function splitRules(string $text): array
    {
        $parts = [];
        $current = '';
        $len = strlen($text);
        for ($k = 0; $k < $len; ++$k) {
            if ($text[$k] === '|') {
                $parts[] = $current;
                $current = '';
                if (preg_match('/^(not_)?regex:(.)/', substr($text, $k + 1), $m) === 1) {
                    $start = $k + 1;
                    $delimiter = $m[2];
                    $close = strpos($text, $delimiter, $start + strlen($m[0]));
                    if ($close !== false) {
                        $end = strpos($text, '|', $close);
                        $end = $end === false ? $len : $end;
                        $parts[] = substr($text, $start, $end - $start);
                        $k = $end;
                        $current = '';
                        if ($end >= $len) {
                            break;
                        }
                    }
                }

                continue;
            }
            $current .= $text[$k];
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
