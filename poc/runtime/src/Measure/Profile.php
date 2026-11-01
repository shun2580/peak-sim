<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/**
 * An app profile: what a person knows about one app that the schema cannot say (a column value a filter reads, a table
 * to leave empty, a row count). It is a file `profiles/<name>.php` that returns an array for the generator. It is
 * chosen by name, never by path, and it is recorded beside the result: a verdict reached with a profile is a verdict
 * reached with that knowledge added, and is reported apart from one reached without.
 */
final class Profile
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,30}$/D';

    /** The keys the generator reads (`Data\DataGenerator`) and the login plan reads (`Route\UserProvider`). */
    private const KEYS = ['skip', 'rows', 'values', 'user', 'login_as'];

    /**
     * @return array<string, mixed> empty when no profile is named
     *
     * @throws BuildFailure
     */
    public static function load(?string $name, ?string $dir = null): array
    {
        if ($name === null) {
            return [];
        }
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new BuildFailure(sprintf('profile name "%s" is not allowed (lowercase letters, digits, - and _)', $name));
        }
        $file = ($dir ?? dirname(__DIR__, 2) . '/profiles') . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new BuildFailure(sprintf('profile "%s" does not exist', $name));
        }
        $profile = (static fn (): mixed => require $file)();
        if (!is_array($profile)) {
            throw new BuildFailure(sprintf('profile "%s" must return an array', $name));
        }
        foreach (array_keys($profile) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new BuildFailure(sprintf('profile "%s" has a key nothing reads: %s', $name, (string) $key));
            }
        }

        return $profile;
    }
}
