<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Capture;

use PeakSim\Runtime\Capture\SecretBindings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Which bound values are kept out of a record. The fixed word is `[redacted]`; every case is written by hand. */
final class SecretBindingsTest extends TestCase
{
    private const R = SecretBindings::REDACTED;

    /** @return array<string, array{string, list<mixed>, list<mixed>}> */
    public static function bindings(): array
    {
        return [
            'no secret column: nothing changes' => ['select * from `users` where `id` = ? and `name` = ?', [7, 'a'], [7, 'a']],
            'a comparison' => ['select * from `users` where `email` = ? and `api_token` = ?', ['a@x.test', 'tok-123'], ['a@x.test', self::R]],
            'a qualified column' => ['select * from `users` where `users`.`remember_token` = ? limit 1', ['tok'], [self::R]],
            'an update set' => ['update `users` set `password` = ?, `name` = ?, `updated_at` = ? where `id` = ?', ['hash', 'n', '2026-01-01', 7], [self::R, 'n', '2026-01-01', 7]],
            'an insert column list' => ['insert into `users` (`name`, `password`, `email`) values (?, ?, ?)', ['n', 'hash', 'e'], ['n', self::R, 'e']],
            'a multi-row insert' => ['insert into `t` (`a`, `secret`) values (?, ?), (?, ?)', [1, 's1', 2, 's2'], [1, self::R, 2, self::R]],
            'an insert with a function in the tuple' => ['insert into `t` (`a`, `token`, `b`) values (now(), ?, ?)', ['tk', 'b'], [self::R, 'b']],
            'an in list' => ['select * from `t` where `token` in (?, ?, ?) and `id` = ?', ['x', 'y', 'z', 5], [self::R, self::R, self::R, 5]],
            'a not in list' => ['select * from `t` where `token` not in (?, ?)', ['x', 'y'], [self::R, self::R]],
            'between' => ['select * from `t` where `otp_code` between ? and ? and `id` = ?', [1, 9, 4], [self::R, self::R, 4]],
            'like' => ['select * from `t` where `session_cookie` like ?', ['%a%'], [self::R]],
            'not like' => ['select * from `t` where `session_cookie` not like ?', ['%a%'], [self::R]],
            'a function around the column' => ['select * from `t` where lower(`secret_key`) = ?', ['k'], [self::R]],
            'a column that does not look secret next to one that does' => ['select * from `t` where `token_count` > ? and `title` = ?', [5, 'x'], [self::R, 'x']],
            'an is null that takes no value does not disturb the others' => ['select * from `t` where `a` = ? or (`password` is null and id = ?)', ['x', 3], ['x', 3]],
            'limit placeholder with a secret column is not guessed' => ['select `token` from `t` where `id` = ? limit ?', [3, 10], [3, self::R]],
        ];
    }

    /**
     * @param list<mixed> $in
     * @param list<mixed> $expected
     */
    #[DataProvider('bindings')]
    public function testBindings(string $sql, array $in, array $expected): void
    {
        self::assertSame($expected, SecretBindings::redactBindings($sql, $in));
    }

    public function testExtraBindingsBeyondThePlaceholdersAreRedactedNotKept(): void
    {
        self::assertSame([self::R, self::R], SecretBindings::redactBindings('select * from `t` where `token` = ?', ['a', 'b']));
    }

    public function testLiteralsNextToASecretColumnAreRedactedInTheSql(): void
    {
        self::assertSame(
            "select * from `t` where `token` = '[redacted]' and `kind` = 'audit'",
            SecretBindings::redactSql("select * from `t` where `token` = 'abc123' and `kind` = 'audit'"),
        );
        self::assertSame(
            "insert into `t` (`name`, `password`) values ('bob', '[redacted]')",
            SecretBindings::redactSql("insert into `t` (`name`, `password`) values ('bob', 'hunter2')"),
        );
        self::assertSame('select * from `t` where `id` = 5', SecretBindings::redactSql('select * from `t` where `id` = 5'));
    }

    public function testTheSecretColumnPattern(): void
    {
        foreach (['password', 'user_password', 'remember_token', 'api_token', 'apikey', 'api_key', 'client_secret', 'recovery_codes', 'two_factor_secret', 'otp', 'Authorization', 'bearer_value', 'cookie', 'signature', 'salt', 'cvv', 'private_key', 'passphrase', 'credentials'] as $name) {
            self::assertTrue(SecretBindings::isSecretColumn($name), $name);
        }
        foreach (['id', 'name', 'email', 'title', 'status', 'created_at', 'order_id', 'slug', 'description'] as $name) {
            self::assertFalse(SecretBindings::isSecretColumn($name), $name);
        }
    }
}
