<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Route;

use PeakSim\Runtime\Route\BodyBuilder;
use PeakSim\Runtime\Route\FileField;
use PeakSim\Runtime\Route\RowLookup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BodyBuilderTest extends TestCase
{
    private static function lookup(): RowLookup
    {
        return new class() implements RowLookup {
            public function tableForModel(string $modelClass): ?string
            {
                return $modelClass === 'App\\Models\\Category' ? 'categories' : null;
            }

            public function routeKeyColumn(string $modelClass): string
            {
                return 'id';
            }

            public function hasTable(string $table): bool
            {
                return true;
            }

            public function primaryKey(string $table): ?string
            {
                return 'id';
            }

            public function firstValue(string $table, string $column, array $where = []): string|int|null
            {
                return match ($table . '.' . $column) {
                    'categories.id' => 11,
                    'users.email' => 'users_email_1',
                    default => null,
                };
            }
        };
    }

    /**
     * @param array<string, mixed> $rules
     *
     * @return array<string, mixed>
     */
    private static function fields(array $rules, int $i = 1): array
    {
        return (new BodyBuilder(self::lookup()))->build($rules, $i)->fields;
    }

    /** @return array<string, array{string|list<string>, mixed}> */
    public static function ruleToValue(): array
    {
        return [
            'required string' => ['required|string', 'x_1'],
            'required with max' => ['required|string|max:255', 'x_1'],
            'required integer' => ['required|integer', 1],
            'integer with min' => ['required|integer|min:5', 5],
            'integer with max under 1' => ['required|integer|max:0', 0],
            'numeric' => ['required|numeric', 1],
            'digits' => ['required|digits:4', '1111'],
            'boolean' => ['required|boolean', 1],
            'email' => ['required|email', 'user_1@example.test'],
            'in takes the first value' => ['required|in:draft,published', 'draft'],
            'in with quotes (Rule::in)' => ['required|in:"draft","published"', 'draft'],
            'in on an integer rule keeps the listed value' => ['required|integer|in:3,4', '3'],
            'array' => ['required|array', []],
            'url' => ['required|url', 'https://example.test/x'],
            'uuid' => ['required|uuid', '00000000-0000-4000-8000-000000000000'],
            'date' => ['required|date', '2026-01-01'],
            'date_format' => ['required|date_format:Y/m/d', '2026/01/01'],
            'json' => ['required|json', '{}'],
            'ip' => ['required|ip', '192.0.2.1'],
            'accepted' => ['accepted', 'yes'],
            'string with min length is padded' => ['required|string|min:6', 'x_1xxx'],
            'string with max length is cut' => ['required|string|max:2', 'x_'],
            'alpha_num' => ['required|alpha_num', 'abc1'],
            'rules as an array of strings' => [['required', 'string', 'max:10'], 'x_1'],
            'a conditional required counts as required' => ['required_if:kind,a|string', 'x_1'],
        ];
    }

    /** @param string|list<string> $rule */
    #[DataProvider('ruleToValue')]
    public function test_a_rule_gives_a_value_that_passes_it(string|array $rule, mixed $expected): void
    {
        $this->assertSame(['f' => $expected], self::fields(['f' => $rule]));
    }

    public function test_the_row_index_goes_into_string_values(): void
    {
        $this->assertSame(['f' => 'x_7'], self::fields(['f' => 'required|string'], 7));
    }

    public function test_optional_fields_are_left_out_of_the_first_body(): void
    {
        $this->assertSame([], self::fields(['a' => 'nullable|string', 'b' => 'sometimes|integer', 'c' => 'string|max:5']));
    }

    public function test_a_prohibited_field_is_never_sent(): void
    {
        $this->assertSame([], self::fields(['a' => 'required|prohibited']));
    }

    public function test_exists_takes_the_first_row_of_the_table_and_column(): void
    {
        $b = (new BodyBuilder(self::lookup()))->build(['category_id' => 'required|exists:categories,id', 'email' => 'required|exists:users,email']);

        $this->assertSame(['category_id' => 11, 'email' => 'users_email_1'], $b->fields);
        $this->assertSame([], $b->unfilled);
    }

    public function test_exists_defaults_the_column_to_the_field_name_and_accepts_a_model_class(): void
    {
        $b = (new BodyBuilder(self::lookup()))->build(['id' => 'required|exists:App\\Models\\Category']);

        $this->assertSame(['id' => 11], $b->fields);
    }

    public function test_exists_with_no_row_leaves_the_field_out_and_says_why(): void
    {
        $b = (new BodyBuilder(self::lookup()))->build(['owner_id' => 'required|exists:owners,id']);

        $this->assertSame([], $b->fields);
        $this->assertSame([['field' => 'owner_id', 'reason' => 'exists_no_row:owners']], $b->unfilled);
    }

    public function test_exists_with_a_connection_prefix_uses_the_table(): void
    {
        $b = (new BodyBuilder(self::lookup()))->build(['category_id' => 'required|exists:mysql.categories,id']);

        $this->assertSame(['category_id' => 11], $b->fields);
    }

    public function test_unique_gives_a_value_no_generated_row_has(): void
    {
        $b = self::fields(['name' => 'required|string|unique:users,name', 'email' => 'required|email|unique:users,email', 'n' => 'required|integer|unique:t,n']);

        $this->assertSame('peak_new_x_1', $b['name']);
        $this->assertSame('peak_new_1@example.test', $b['email']);
        $this->assertSame(900001, $b['n']);
    }

    public function test_confirmed_adds_the_confirmation_field_with_the_same_value(): void
    {
        $this->assertSame(
            ['password' => 'x_1', 'password_confirmation' => 'x_1'],
            self::fields(['password' => 'required|string|confirmed']),
        );
    }

    public function test_same_copies_the_other_field(): void
    {
        $this->assertSame(
            ['a' => 'x_1', 'b' => 'x_1'],
            self::fields(['a' => 'required|string', 'b' => 'required|same:a']),
        );
    }

    public function test_a_file_rule_gives_a_file_field(): void
    {
        $b = (new BodyBuilder(self::lookup()))->build(['doc' => 'required|file|max:100', 'photo' => 'required|image', 'zip' => 'required|mimes:zip']);

        $this->assertInstanceOf(FileField::class, $b->fields['doc']);
        $this->assertSame('file', $b->fields['doc']->kind);
        $this->assertSame('image', $b->fields['photo']->kind);
        $this->assertSame('file', $b->fields['zip']->kind);
        $this->assertSame(['doc', 'photo', 'zip'], $b->files());
    }

    public function test_a_wildcard_rule_makes_an_array_with_one_element(): void
    {
        $this->assertSame(
            ['tags' => ['x_1']],
            self::fields(['tags' => 'required|array', 'tags.*' => 'string|max:20']),
        );
    }

    public function test_a_wildcard_with_a_field_makes_one_object(): void
    {
        $this->assertSame(
            ['lines' => [['sku' => 'x_1', 'qty' => 1]]],
            self::fields(['lines' => 'required|array', 'lines.*.sku' => 'required|string', 'lines.*.qty' => 'required|integer', 'lines.*.note' => 'nullable|string']),
        );
    }

    public function test_a_rule_the_builder_cannot_satisfy_is_noted_and_the_field_still_gets_a_string(): void
    {
        $b = (new BodyBuilder(self::lookup()))->build(['code' => 'required|string|regex:/^[A-Z]{3}$/']);

        $this->assertSame(['code' => 'x_1'], $b->fields);
        $this->assertSame(['regex'], $b->unsupported);
    }

    public function test_a_rule_object_without_a_string_form_is_noted(): void
    {
        $b = (new BodyBuilder(self::lookup()))->build(['code' => ['required', new \stdClass()]]);

        $this->assertSame(['code' => 'x_1'], $b->fields);
        $this->assertSame(['object_rule'], $b->unsupported);
    }

    public function test_a_rule_object_with_a_string_form_is_read(): void
    {
        $rule = new class() implements \Stringable {
            public function __toString(): string
            {
                return 'in:"a","b"';
            }
        };

        $this->assertSame(['f' => 'a'], self::fields(['f' => ['required', $rule]]));
    }

    public function test_a_retry_body_from_the_error_keys_tries_strings_then_integers_then_arrays(): void
    {
        $b = new BodyBuilder(self::lookup());

        $this->assertSame(['name' => 'x_1', 'age' => 'x_1'], $b->fromErrorKeys(['name', 'age'], 1));
        $this->assertSame(['name' => 1, 'age' => 1], $b->fromErrorKeys(['name', 'age'], 2));
        $this->assertSame(['name' => [], 'age' => []], $b->fromErrorKeys(['name', 'age'], 3));
    }

    public function test_a_retry_body_nests_dotted_error_keys(): void
    {
        $b = new BodyBuilder(self::lookup());

        $this->assertSame(['items' => [['sku' => 'x_1']]], $b->fromErrorKeys(['items.0.sku'], 1));
    }

    public function test_the_retry_stops_after_three_rounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BodyBuilder(self::lookup()))->fromErrorKeys(['a'], 4);
    }

    public function test_the_same_rules_always_give_the_same_body(): void
    {
        $rules = ['a' => 'required|string', 'b' => 'required|integer', 'c' => 'required|in:x,y'];

        $this->assertSame(self::fields($rules), self::fields($rules));
    }
}
