<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Route;

use PeakSim\Runtime\Route\RequestCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The credentials an app profile has a request carry in its query. The secret part is never written down: a task holds
 * a token, and the process that sends the request makes the value from the run's APP_KEY.
 */
final class RequestCredentialsTest extends TestCase
{
    private const KEY_A = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    private const KEY_B = 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';

    public function test_a_derived_key_is_the_same_every_time_for_the_same_run_purpose_and_login(): void
    {
        $this->assertSame(
            RequestCredentials::derive(self::KEY_A, 'subsonic', 1),
            RequestCredentials::derive(self::KEY_A, 'subsonic', 1),
        );
        $this->assertSame(
            RequestCredentials::derive(self::KEY_A, 'subsonic', 1),
            RequestCredentials::derive(self::KEY_A, 'subsonic', '1'),
            'the id as a number or as text is the same login',
        );
    }

    public function test_a_derived_key_changes_with_the_run_the_purpose_and_the_login(): void
    {
        $base = RequestCredentials::derive(self::KEY_A, 'subsonic', 1);

        $this->assertNotSame($base, RequestCredentials::derive(self::KEY_B, 'subsonic', 1), 'another run');
        $this->assertNotSame($base, RequestCredentials::derive(self::KEY_A, 'other', 1), 'another purpose');
        $this->assertNotSame($base, RequestCredentials::derive(self::KEY_A, 'subsonic', 2), 'another login');
    }

    public function test_a_derived_key_has_a_fixed_readable_form_and_holds_no_part_of_the_app_key(): void
    {
        $key = RequestCredentials::derive(self::KEY_A, 'subsonic', 2);

        $this->assertMatchesRegularExpression('/^measure-subsonic-2-[0-9a-f]{32}$/D', $key);
        $this->assertStringNotContainsString(substr(self::KEY_A, 7, 12), $key);
    }

    public function test_an_empty_app_key_or_a_bad_purpose_derives_nothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RequestCredentials::derive('', 'subsonic', 1);
    }

    public function test_a_purpose_that_is_not_a_plain_word_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RequestCredentials::derive(self::KEY_A, 'sub sonic', 1);
    }

    public function test_a_template_takes_the_logins_email_and_leaves_the_secret_as_a_token_naming_the_login(): void
    {
        $query = RequestCredentials::template(
            ['u' => '{email}', 'apiKey' => '{key:subsonic}', 'f' => 'json', 'id' => '{id}'],
            ['id' => 2, 'email' => 'admin@measure.invalid'],
        );

        $this->assertSame(['u' => 'admin@measure.invalid', 'apiKey' => '{key:subsonic:2}', 'f' => 'json', 'id' => '2'], $query);
    }

    public function test_a_template_that_needs_what_the_login_does_not_have_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RequestCredentials::template(['u' => '{email}'], ['id' => 1]);
    }

    public function test_a_template_with_a_placeholder_nothing_reads_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RequestCredentials::template(['u' => '{password}'], ['id' => 1, 'email' => 'a@b.invalid', 'password' => 'secret']);
    }

    public function test_expanding_turns_a_token_into_the_derived_key_and_leaves_other_values_alone(): void
    {
        $expanded = RequestCredentials::expand(['u' => 'user@measure.invalid', 'apiKey' => '{key:subsonic:1}'], self::KEY_A);

        $this->assertSame(['u' => 'user@measure.invalid', 'apiKey' => RequestCredentials::derive(self::KEY_A, 'subsonic', 1)], $expanded);
    }

    public function test_expanding_without_the_run_app_key_is_refused_and_says_so_without_naming_a_value(): void
    {
        try {
            RequestCredentials::expand(['apiKey' => '{key:subsonic:1}'], '');
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('APP_KEY', $e->getMessage());
            $this->assertStringNotContainsString('measure-subsonic', $e->getMessage());
        }
    }

    public function test_a_query_without_tokens_needs_no_app_key(): void
    {
        $this->assertSame(['u' => 'x'], RequestCredentials::expand(['u' => 'x'], ''));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function placeholders(): iterable
    {
        yield 'plain text' => ['json', true];
        yield 'email' => ['{email}', true];
        yield 'id' => ['{id}', true];
        yield 'key' => ['{key:subsonic}', true];
        yield 'a token already naming a login' => ['{key:subsonic:2}', false];
        yield 'password' => ['{password}', false];
        yield 'key without a purpose' => ['{key:}', false];
        yield 'braces left open' => ['{email', false];
        yield 'mixed with text' => ['x-{email}', true];
    }

    #[DataProvider('placeholders')]
    public function test_which_values_a_profile_may_write(string $value, bool $allowed): void
    {
        $this->assertSame($allowed, RequestCredentials::isAllowedTemplate($value));
    }
}
