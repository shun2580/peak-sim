<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/**
 * The credentials an app profile has a request carry in its query (an app that reads them from the request's own input
 * and not from a session: Koel's Subsonic routes read `u` with `apiKey`).
 *
 * A profile writes the query as a template, `{email}` and `{id}` (the login the entry asks for) and `{key:<purpose>}`
 * (a secret made for that login). A task keeps the secret only as a token, `{key:<purpose>:<login id>}`; the process
 * that sends the request makes the value from the run's APP_KEY. The same function makes the value the profile stores
 * in the measurement database, so the two always agree and nothing has to be handed from one to the other. The value
 * is never written to a task, a record, a log or an error message.
 */
final class RequestCredentials
{
    private const PURPOSE = '[a-z][a-z0-9_]{0,30}';

    /** Longest value of the secret's hex part: 128 bits. */
    private const HEX_LENGTH = 32;

    /**
     * The secret of one login for one purpose, derived from the run's APP_KEY (HMAC-SHA256): the same for every process
     * of the run, different from run to run, and not guessable without the APP_KEY.
     *
     * @throws \InvalidArgumentException when the app key is empty or the purpose is not a plain word
     */
    public static function derive(string $appKey, string $purpose, string|int $loginId): string
    {
        if ($appKey === '') {
            throw new \InvalidArgumentException('the run has no APP_KEY to derive a credential from');
        }
        if (preg_match('/^' . self::PURPOSE . '$/D', $purpose) !== 1) {
            throw new \InvalidArgumentException('the purpose of a derived credential is a plain lowercase word');
        }
        $id = (string) $loginId;
        if (preg_match('/^[0-9A-Za-z_-]{1,40}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('the login id of a derived credential is a plain word or number');
        }

        return sprintf('measure-%s-%s-%s', $purpose, $id, substr(hash_hmac('sha256', 'peak-sim-measure:' . $purpose . ':' . $id, $appKey), 0, self::HEX_LENGTH));
    }

    /**
     * Whether a value in a profile's query template is written with placeholders this class reads (or none at all).
     */
    public static function isAllowedTemplate(string $value): bool
    {
        $rest = preg_replace('/\{(?:email|id|key:' . self::PURPOSE . ')\}/', '', $value);

        return is_string($rest) && ! str_contains($rest, '{') && ! str_contains($rest, '}');
    }

    /**
     * Fills a query template for one login: `{email}` and `{id}` become the login's, `{key:p}` becomes the token
     * `{key:p:<id>}` (the secret itself is made later, in the process that sends the request).
     *
     * @param array<string, string>     $template
     * @param array<string, mixed>      $login    the profile's `user` or `admin`: needs `id`, and `email` when asked for
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException when a placeholder is not one this class reads or the login lacks what it needs
     */
    public static function template(array $template, array $login): array
    {
        $id = $login['id'] ?? null;
        $email = $login['email'] ?? null;
        $out = [];
        foreach ($template as $name => $value) {
            if (! self::isAllowedTemplate($value)) {
                throw new \InvalidArgumentException(sprintf('the query value of "%s" has a placeholder nothing reads', $name));
            }
            $filled = preg_replace_callback(
                '/\{(email|id|key:' . self::PURPOSE . ')\}/',
                static function (array $m) use ($name, $id, $email): string {
                    if ($m[1] === 'email') {
                        if (! is_string($email) || $email === '') {
                            throw new \InvalidArgumentException(sprintf('the query value of "%s" asks for the login\'s email and the login has none', $name));
                        }

                        return $email;
                    }
                    if (! is_int($id) && ! is_string($id)) {
                        throw new \InvalidArgumentException(sprintf('the query value of "%s" asks for the login\'s id and the login has none', $name));
                    }
                    if ($m[1] === 'id') {
                        return (string) $id;
                    }

                    return '{' . $m[1] . ':' . $id . '}';
                },
                $value,
            );
            $out[(string) $name] = (string) $filled;
        }

        return $out;
    }

    /**
     * Makes the secrets: a value that is exactly a token `{key:<purpose>:<login id>}` becomes the derived key.
     *
     * @param array<string, string> $query
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException when a token needs the run's APP_KEY and there is none (the message names the
     *                                   query name, never a value)
     */
    public static function expand(array $query, string $appKey): array
    {
        $out = [];
        foreach ($query as $name => $value) {
            if (preg_match('/^\{key:(' . self::PURPOSE . '):([0-9A-Za-z_-]{1,40})\}$/D', $value, $m) === 1) {
                if ($appKey === '') {
                    throw new \InvalidArgumentException(sprintf('the query value of "%s" needs the run\'s APP_KEY and the run has none', $name));
                }
                $value = self::derive($appKey, $m[1], $m[2]);
            }
            $out[(string) $name] = $value;
        }

        return $out;
    }
}
