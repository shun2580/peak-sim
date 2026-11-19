<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Boot\OnBootWatch;
use PeakSim\Runtime\Route\ProfileRoutes;
use PeakSim\Runtime\Route\RequestCredentials;
use PeakSim\Runtime\Run\Classification;

/**
 * An app profile: what a person knows about one app that the schema cannot say (a column value a filter reads, a table
 * to leave empty, a row count). It is a file `profiles/<name>.php` that returns an array for the generator. It is
 * chosen by name, never by path, and it is recorded beside the result: a verdict reached with a profile is a verdict
 * reached with that knowledge added, and is reported apart from one reached without.
 */
final class Profile
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,30}$/D';

    /** The variable that hands the profile's name to the processes that start the app (`bin/measure-one`). */
    public const NAME_ENV = 'PEAK_MEASURE_PROFILE';

    /**
     * The keys something reads:
     *   skip, rows, values             the data generator (`Data\Generator`)
     *   user, admin, login_as          the login plan (`Route\UserProvider`) and the task builder
     *   reference_rows, role_permissions, fixups, after_generation
     *                                  the hook that runs after the generator (`DatabaseBuild::afterGeneration`); the
     *                                  first three are what that hook reads in the profile that has one
     *   state_changing                 routes that rewrite a table other routes read: a non-empty list, in the order they are measured, each
     *                                  with the `route` ("METHOD uri"), the `tables` it rewrites and a `why`. They are measured after
     *                                  every other route of their database (`RouteOrder`); the repeat also ANALYZEs their tables
     *                                  between two laps (`bin/variance-run`)
     *   arguments, unfillable          the argument resolver (`Route\ArgumentResolver`)
     *   request_query                  the task builder: routes whose GET carries credentials in its query
     *                                  (`Route\ProfileRoutes::querying`, `Route\RequestCredentials`)
     *   on_boot                        a function run in the process that measures a route, once the app has started and
     *                                  before anyone is logged in. It receives the application and may set configuration
     *                                  or bind a service (a key the app reads at login, the asset helper of an app whose
     *                                  front end is not built). It must not touch the database
     *                                  (`Profile::onBoot`, called by `Run\MeasureOne`)
     */
    private const KEYS = ['skip', 'rows', 'values', 'user', 'admin', 'login_as', 'reference_rows', 'role_permissions', 'fixups', 'arguments', 'unfillable', 'request_query', 'after_generation', 'state_changing', 'on_boot'];

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
        if (array_key_exists('after_generation', $profile) && !is_callable($profile['after_generation'])) {
            throw new BuildFailure(sprintf('profile "%s": after_generation must be a function', $name));
        }
        if (array_key_exists('state_changing', $profile)) {
            self::checkStateChanging($name, $profile['state_changing']);
        }
        if (array_key_exists('on_boot', $profile) && !is_callable($profile['on_boot'])) {
            throw new BuildFailure(sprintf('profile "%s": on_boot must be a function', $name));
        }
        foreach ((array) ($profile['unfillable'] ?? []) as $label => $entry) {
            self::checkUnfillable($name, (string) $label, $entry);
        }
        foreach ((array) ($profile['request_query'] ?? []) as $label => $entry) {
            self::checkRequestQuery($name, (string) $label, $entry, $profile);
        }
        $loginAs = $profile['login_as'] ?? 'user';
        if ($loginAs !== 'user' && $loginAs !== 'admin') {
            throw new BuildFailure(sprintf('profile "%s": login_as must be "user" or "admin"', $name));
        }
        if ($loginAs === 'admin' && !isset($profile['admin'])) {
            throw new BuildFailure(sprintf('profile "%s": login_as is "admin" and the profile has no admin to log in as', $name));
        }

        return $profile;
    }

    /**
     * Runs the profile's `on_boot` function, if it has one, on the started application, under the watch that holds it
     * to its conditions (it does not touch the database and does not take the fakes off: `Boot\OnBootWatch`). An
     * object that is not a container (a test's stand-in) is given the function without a watch.
     *
     * @param array<string, mixed> $profile
     *
     * @throws \PeakSim\Runtime\Boot\OnBootViolation
     */
    public static function onBoot(array $profile, object $app, ?OnBootWatch $watch = null): void
    {
        if (!isset($profile['on_boot'])) {
            return;
        }
        $watch ??= $app instanceof \ArrayAccess ? new OnBootWatch($app) : null;
        if ($watch === null) {
            $profile['on_boot']($app);

            return;
        }
        $watch->run($profile['on_boot']);
    }

    /**
     * The routes a profile says rewrite a table other routes read, by "METHOD uri" in the order they are measured, with the tables each
     * rewrites. Empty for a profile with none.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<string, list<string>>
     */
    public static function stateChanging(array $profile): array
    {
        $out = [];
        foreach ((array) ($profile['state_changing'] ?? []) as $entry) {
            $out[(string) $entry['route']] = array_values(array_map('strval', (array) $entry['tables']));
        }

        return $out;
    }

    /**
     * @throws BuildFailure
     */
    private static function checkStateChanging(string $profile, mixed $entries): void
    {
        $fail = static fn (string $why): BuildFailure => new BuildFailure(sprintf('profile "%s": state_changing %s', $profile, $why));
        if (!is_array($entries) || $entries === [] || !array_is_list($entries)) {
            throw $fail('must be a non-empty list');
        }
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['route'] ?? null) || preg_match('/^[A-Z]+ \/\S*$/D', $entry['route']) !== 1) {
                throw $fail('has an entry with no route written "METHOD uri"');
            }
            if (isset($seen[$entry['route']])) {
                throw $fail(sprintf('names %s twice', $entry['route']));
            }
            $seen[$entry['route']] = true;
            $tables = $entry['tables'] ?? null;
            if (!is_array($tables) || $tables === [] || array_filter($tables, static fn (mixed $t): bool => is_string($t) && preg_match('/^[a-z0-9_]{1,64}$/D', $t) === 1) !== $tables) {
                throw $fail(sprintf('entry %s needs the plain names of the tables it rewrites', $entry['route']));
            }
            if (!is_string($entry['why'] ?? null) || trim($entry['why']) === '') {
                throw $fail(sprintf('entry %s has no why', $entry['route']));
            }
        }
    }

    /**
     * An `unfillable` entry names a reason from the fixed vocabulary (the classification of an attempt, and
     * `file_dependency`, which a task is recorded with when its body needs an uploaded file) and, if it names routes, only the keys
     * `ProfileRoutes` reads, each a non-empty list of strings.
     *
     * @throws BuildFailure
     */
    private static function checkUnfillable(string $profile, string $label, mixed $entry): void
    {
        $fail = static fn (string $why): BuildFailure => new BuildFailure(sprintf('profile "%s": unfillable "%s" %s', $profile, $label, $why));
        if (!is_array($entry) || !is_string($entry['reason'] ?? null) || !in_array($entry['reason'], [...Classification::ALL, 'file_dependency'], true)) {
            throw $fail('needs a reason from the fixed vocabulary');
        }
        if (!isset($entry['routes'])) {
            return;
        }
        self::checkRoutes($entry['routes'], $fail);
    }

    /**
     * @param callable(string): BuildFailure $fail
     *
     * @throws BuildFailure
     */
    private static function checkRoutes(mixed $routes, callable $fail): void
    {
        if (!is_array($routes) || $routes === []) {
            throw $fail('has routes that are not a list of keys');
        }
        foreach ($routes as $key => $values) {
            if (!in_array($key, ProfileRoutes::ROUTE_KEYS, true)) {
                throw $fail(sprintf('names a route key nothing reads: %s', (string) $key));
            }
            if (!is_array($values) || $values === [] || array_filter($values, 'is_string') !== $values) {
                throw $fail(sprintf('needs a non-empty list of strings for %s', (string) $key));
            }
        }
    }

    /**
     * A `request_query` entry names the routes whose GET carries credentials in its query: `routes` (as in `unfillable`),
     * `query` (parameter name => text, with the placeholders `RequestCredentials` reads) and, optionally, `as`: the login
     * (`user` or `admin`) whose email and key it uses, which the profile must have.
     *
     * @param array<string, mixed> $profile
     *
     * @throws BuildFailure
     */
    private static function checkRequestQuery(string $name, string $label, mixed $entry, array $profile): void
    {
        $fail = static fn (string $why): BuildFailure => new BuildFailure(sprintf('profile "%s": request_query "%s" %s', $name, $label, $why));
        if (!is_array($entry) || !isset($entry['routes'])) {
            throw $fail('needs routes');
        }
        self::checkRoutes($entry['routes'], $fail);
        $query = $entry['query'] ?? null;
        if (!is_array($query) || $query === []) {
            throw $fail('needs a query (a non-empty list of name => text)');
        }
        $as = $entry['as'] ?? ($profile['login_as'] ?? 'user');
        if ($as !== 'user' && $as !== 'admin') {
            throw $fail('has an "as" that is neither user nor admin');
        }
        $login = $profile[$as] ?? null;
        $needsLogin = false;
        foreach ($query as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $name) !== 1) {
                throw $fail('has a query name that is not a plain word');
            }
            if (!is_string($value) || !RequestCredentials::isAllowedTemplate($value)) {
                throw $fail(sprintf('has a query value for "%s" that is not text or has a placeholder nothing reads', $name));
            }
            $needsLogin = $needsLogin || str_contains($value, '{');
        }
        if ($needsLogin) {
            if (!is_array($login) || !(is_int($login['id'] ?? null) || is_string($login['id'] ?? null))) {
                throw $fail(sprintf('uses the %s login and the profile has none with an id', $as));
            }
            foreach ($query as $value) {
                if (str_contains($value, '{email}') && !(is_string($login['email'] ?? null) && $login['email'] !== '')) {
                    throw $fail(sprintf('asks for the email of the %s login and it has none', $as));
                }
            }
        }
    }
}
