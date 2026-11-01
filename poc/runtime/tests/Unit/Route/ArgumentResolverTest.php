<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Route;

use PeakSim\Runtime\Route\ArgumentResolver;
use PeakSim\Runtime\Route\RouteSpec;
use PeakSim\Runtime\Route\RowLookup;
use PHPUnit\Framework\TestCase;

final class ArgumentResolverTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $params name => overrides
     */
    private static function spec(string $uri, array $params): RouteSpec
    {
        $full = [];
        foreach ($params as $name => $over) {
            $full[$name] = $over + ['optional' => false, 'where' => null, 'model' => null, 'field' => null, 'type' => null, 'scope' => null];
        }

        return new RouteSpec($uri, 'GET', ['GET'], [], $full, 'A@b', [], true);
    }

    private static function lookup(): RowLookup
    {
        return new class() implements RowLookup {
            /** @var array<string, string> */
            private array $models = ['App\\Models\\Order' => 'orders', 'App\\Models\\Post' => 'posts', 'App\\Models\\Comment' => 'comments'];

            public function tableForModel(string $modelClass): ?string
            {
                return $this->models[$modelClass] ?? null;
            }

            public function routeKeyColumn(string $modelClass): string
            {
                return $modelClass === 'App\\Models\\Post' ? 'slug' : 'id';
            }

            public function hasTable(string $table): bool
            {
                return in_array($table, ['orders', 'posts', 'comments', 'categories', 'users'], true);
            }

            public function primaryKey(string $table): ?string
            {
                return 'id';
            }

            public function firstValue(string $table, string $column, array $where = []): string|int|null
            {
                return match (true) {
                    $table === 'posts' && $column === 'slug' => 'posts_slug_1',
                    $table === 'comments' && $where === ['post_id' => 'posts_slug_1'] => 7,
                    $table === 'comments' => 3,
                    $table === 'orders', $table === 'categories', $table === 'users' => 1,
                    default => null,
                };
            }
        };
    }

    public function test_an_implicitly_bound_model_takes_the_route_key_of_its_first_row(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/posts/{post}', ['post' => ['model' => 'App\\Models\\Post']]));

        $this->assertTrue($r->ok());
        $this->assertSame(['post' => 'posts_slug_1'], $r->values);
        $this->assertSame('/posts/posts_slug_1', $r->fill('/posts/{post}'));
    }

    public function test_a_custom_binding_field_wins_over_the_route_key(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/orders/{order}', ['order' => ['model' => 'App\\Models\\Order', 'field' => 'id']]));

        $this->assertSame(['order' => 1], $r->values);
    }

    public function test_a_scoped_child_is_picked_from_the_parent_row(): void
    {
        $spec = self::spec('/posts/{post}/comments/{comment}', [
            'post' => ['model' => 'App\\Models\\Post'],
            'comment' => ['model' => 'App\\Models\\Comment', 'scope' => ['parent' => 'post', 'fk' => 'post_id']],
        ]);

        $r = (new ArgumentResolver(self::lookup()))->resolve($spec);

        $this->assertSame(['post' => 'posts_slug_1', 'comment' => 7], $r->values);
    }

    public function test_a_where_number_constraint_gets_a_number(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/things/{thing}', ['thing' => ['where' => '[0-9]+']]));

        $this->assertSame(['thing' => '1'], $r->values);
    }

    public function test_a_where_in_constraint_gets_its_first_alternative(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/reports/{type}', ['type' => ['where' => 'daily|weekly|monthly']]));

        $this->assertSame(['type' => 'daily'], $r->values);
    }

    public function test_a_uuid_pattern_gets_a_uuid(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/x/{token}', ['token' => ['where' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}']]));

        $this->assertTrue($r->ok());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $r->values['token']);
    }

    public function test_a_name_ending_in_id_finds_its_table_and_takes_the_first_primary_key(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/orders/{orderId}/lines', ['orderId' => []]));

        $this->assertSame(['orderId' => 1], $r->values);
    }

    public function test_a_snake_case_name_and_a_y_plural_find_their_table(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/c/{category_id}', ['category_id' => []]));

        $this->assertSame(['category_id' => 1], $r->values);
    }

    public function test_a_name_ending_in_id_with_no_such_table_but_an_int_type_gets_one(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/x/{widgetId}', ['widgetId' => ['type' => 'int']]));

        $this->assertSame(['widgetId' => 1], $r->values);
    }

    public function test_a_plain_id_with_an_int_type_gets_one(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/x/{id}', ['id' => ['type' => 'int']]));

        $this->assertSame(['id' => 1], $r->values);
    }

    public function test_a_parameter_nothing_explains_is_recorded_with_its_name_and_a_reason(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/x/{mystery}', ['mystery' => []]));

        $this->assertFalse($r->ok());
        $this->assertSame([['param' => 'mystery', 'reason' => 'no_rule_to_fill']], $r->unfilled);
        $this->assertNull($r->fill('/x/{mystery}'));
    }

    public function test_a_bound_model_with_no_row_is_unfilled_with_the_table_named(): void
    {
        $lookup = new class() implements RowLookup {
            public function tableForModel(string $modelClass): ?string
            {
                return 'orders';
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
                return null;
            }
        };

        $r = (new ArgumentResolver($lookup))->resolve(self::spec('/orders/{order}', ['order' => ['model' => 'App\\Models\\Order']]));

        $this->assertSame([['param' => 'order', 'reason' => 'no_row:orders']], $r->unfilled);
    }

    public function test_a_model_with_no_known_table_is_unfilled(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/g/{ghost}', ['ghost' => ['model' => 'App\\Models\\Ghost']]));

        $this->assertSame([['param' => 'ghost', 'reason' => 'no_table_for_model']], $r->unfilled);
    }

    public function test_a_value_that_breaks_the_where_constraint_is_not_used(): void
    {
        // posts_slug_1 does not match [0-9]+
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/posts/{post}', ['post' => ['model' => 'App\\Models\\Post', 'where' => '[0-9]+']]));

        $this->assertSame([['param' => 'post', 'reason' => 'violates_where']], $r->unfilled);
    }

    public function test_an_optional_parameter_that_cannot_be_filled_is_left_out_and_is_not_a_failure(): void
    {
        $spec = self::spec('/files/{path?}', ['path' => ['optional' => true]]);

        $r = (new ArgumentResolver(self::lookup()))->resolve($spec);

        $this->assertTrue($r->ok());
        $this->assertSame([], $r->values);
        $this->assertSame('/files', $r->fill('/files/{path?}'));
    }

    public function test_the_selection_does_not_depend_on_the_scale(): void
    {
        $resolver = new ArgumentResolver(self::lookup());
        $spec = self::spec('/posts/{post}', ['post' => ['model' => 'App\\Models\\Post']]);

        $this->assertEquals($resolver->resolve($spec), $resolver->resolve($spec));
    }

    public function test_fill_keeps_the_uri_as_given_when_there_is_nothing_to_fill(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/ok/eager', []));

        $this->assertTrue($r->ok());
        $this->assertSame('/ok/eager', $r->fill('/ok/eager'));
    }

    public function test_values_are_url_encoded_when_filled_in(): void
    {
        $r = (new ArgumentResolver(self::lookup()))->resolve(self::spec('/x/{type}', ['type' => ['where' => 'a b|c']]));

        $this->assertSame('/x/a%20b', $r->fill('/x/{type}'));
    }

    // ---- what the profile says outright -----------------------------------------------------------------------

    /** The lookup of the tests above, with a table of text keys that the tool's own rules do not reach. */
    private static function profileLookup(): RowLookup
    {
        return new class() implements RowLookup {
            public function tableForModel(string $modelClass): ?string
            {
                return $modelClass === 'App\\Models\\Order' ? 'orders' : null;
            }

            public function routeKeyColumn(string $modelClass): string
            {
                return 'id';
            }

            public function hasTable(string $table): bool
            {
                return in_array($table, ['orders', 'songs', 'genres', 'empty_things'], true);
            }

            public function primaryKey(string $table): ?string
            {
                return 'id';
            }

            public function firstValue(string $table, string $column, array $where = []): string|int|null
            {
                return match ([$table, $column]) {
                    ['orders', 'id'] => 1,
                    ['songs', 'id'] => 'song-key-1',
                    ['genres', 'public_id'] => 'genre-public-1',
                    default => null,
                };
            }
        };
    }

    public function test_a_profile_argument_takes_the_first_value_of_the_column_it_names(): void
    {
        $profile = ['arguments' => [
            'song' => ['table' => 'songs', 'column' => 'id', 'why' => 'a text key'],
            'genre' => ['table' => 'genres', 'column' => 'public_id', 'why' => 'bound by its public id'],
        ]];

        $r = (new ArgumentResolver(self::profileLookup(), $profile))->resolve(self::spec('/songs/{song}/genre/{genre}', ['song' => [], 'genre' => []]));

        $this->assertTrue($r->ok());
        $this->assertSame(['song' => 'song-key-1', 'genre' => 'genre-public-1'], $r->values);
    }

    public function test_a_profile_argument_can_be_a_literal(): void
    {
        $profile = ['arguments' => ['format' => ['value' => 'json', 'why' => 'the only format the route takes']]];

        $r = (new ArgumentResolver(self::profileLookup(), $profile))->resolve(self::spec('/export/{format}', ['format' => []]));

        $this->assertSame(['format' => 'json'], $r->values);
    }

    public function test_a_profile_argument_beats_a_guess_by_name_but_not_the_apps_own_model_binding(): void
    {
        $profile = ['arguments' => [
            'songId' => ['table' => 'songs', 'column' => 'id', 'why' => 'a text key, not a number'],
            'order' => ['table' => 'songs', 'column' => 'id', 'why' => 'would be wrong here'],
        ]];

        $r = (new ArgumentResolver(self::profileLookup(), $profile))->resolve(self::spec('/x/{songId}/{order}', [
            'songId' => ['type' => 'int'],
            'order' => ['model' => 'App\\Models\\Order'],
        ]));

        $this->assertSame(['songId' => 'song-key-1', 'order' => 1], $r->values);
    }

    public function test_a_profile_argument_with_no_row_behind_it_is_a_reason_not_a_guess(): void
    {
        $profile = ['arguments' => ['thing' => ['table' => 'empty_things', 'column' => 'id', 'why' => 'x']]];

        $r = (new ArgumentResolver(self::profileLookup(), $profile))->resolve(self::spec('/things/{thing}', ['thing' => []]));

        $this->assertFalse($r->ok());
        $this->assertSame([['param' => 'thing', 'reason' => 'no_row:empty_things']], $r->unfilled);
    }

    public function test_a_profile_argument_that_breaks_the_where_constraint_is_not_used(): void
    {
        $profile = ['arguments' => ['song' => ['table' => 'songs', 'column' => 'id', 'why' => 'x']]];

        $r = (new ArgumentResolver(self::profileLookup(), $profile))->resolve(self::spec('/songs/{song}', ['song' => ['where' => '[0-9]+']]));

        $this->assertSame([['param' => 'song', 'reason' => 'violates_where']], $r->unfilled);
    }

    public function test_an_unfillable_parameter_the_profile_explains_carries_the_profiles_reason_and_class(): void
    {
        $profile = ['unfillable' => [
            'embed_options' => ['params' => ['options'], 'reason' => 'unfilled_param', 'why' => 'an encrypted string'],
            'dropbox_key' => ['params' => ['key'], 'reason' => 'external_call_blocked', 'why' => 'calls the Dropbox service'],
            'email_change_confirm' => ['params' => [], 'reason' => 'signature_required', 'why' => 'a signed URL'],
        ]];

        $r = (new ArgumentResolver(self::profileLookup(), $profile))->resolve(self::spec('/dropbox/{key}', ['key' => []]));

        $this->assertFalse($r->ok());
        $this->assertSame([['param' => 'key', 'reason' => 'profile:dropbox_key', 'class' => 'external_call_blocked']], $r->unfilled);
    }

    public function test_the_profile_does_not_override_a_parameter_the_tool_could_fill(): void
    {
        $profile = ['unfillable' => ['preset' => ['params' => ['id'], 'reason' => 'unfilled_param', 'why' => 'x']]];

        $r = (new ArgumentResolver(self::profileLookup(), $profile))->resolve(self::spec('/orders/{order}', ['order' => ['model' => 'App\\Models\\Order']]));

        $this->assertTrue($r->ok());
    }

    public function test_an_unfillable_parameter_nothing_explains_stays_as_it_was(): void
    {
        $profile = ['unfillable' => ['dropbox_key' => ['params' => ['key'], 'reason' => 'external_call_blocked', 'why' => 'x']]];

        $r = (new ArgumentResolver(self::profileLookup(), $profile))->resolve(self::spec('/files/{file}', ['file' => []]));

        $this->assertSame([['param' => 'file', 'reason' => 'no_rule_to_fill']], $r->unfilled);
    }

    public function test_with_no_profile_the_resolver_behaves_as_before(): void
    {
        $spec = self::spec('/posts/{post}/comments/{comment}', [
            'post' => ['model' => 'App\\Models\\Post'],
            'comment' => ['model' => 'App\\Models\\Comment', 'scope' => ['parent' => 'post', 'fk' => 'post_id']],
        ]);

        $this->assertEquals((new ArgumentResolver(self::lookup()))->resolve($spec), (new ArgumentResolver(self::lookup(), []))->resolve($spec));
    }
}
