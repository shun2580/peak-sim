<?php

declare(strict_types=1);

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Guard\DbGuard;

// Knowledge about this app (Koel, a Laravel music server) that its schema and its routes cannot give. A run uses it
// only when asked for (`--profile=koel`), and a result reached with it is reported as such.
//
// This is knowledge, not a rule of judgement: nothing here decides whether a count is flat or grows. It says what rows
// the app needs before it will answer at all, who logs in, which generated values make the app look at no data, and
// which route arguments the tool cannot work out. The app is never changed and its seeder is never run; this file
// writes the rows itself, only into a measurement database, after the generator has filled it.
//
// It is a proof-of-concept artefact. For a permanent version, how to generalise this kind of knowledge (so that a
// person does not have to write one such file per app) is to be designed separately.
//
// What the file holds
//   skip / rows / values   read by the data generator (`Data\Generator`): tables it must not fill, row counts, constants.
//   user / admin           the two logins: a general user and an administrator (rows 1 and 2 of the generated users).
//   login_as               which of the two the first request of a route uses ('user' or 'admin').
//   reference_rows         rows the app needs in order to run (default organization, roles, permissions, a setting).
//                          The migrations already make most of them; each is added only when it is not there.
//   role_permissions       which role holds which permission (what the app's own migration grants).
//   fixups                 single UPDATE statements that undo what generated data does to Koel's meaning of a row.
//   arguments              how to fill a route argument the tool's own rules do not reach.
//   unfillable             what could not be prepared, and why (the design's fixed vocabulary of reasons).
//   after_generation       the function that applies reference_rows, role_permissions, the two logins (with the API key
//                          each holds for the Subsonic routes) and the fixups. It takes the PDO connection of the
//                          measurement database and that database's name, and refuses any name that is not a
//                          measurement database.
//
// Routes to an operation the controller does not define
//   Koel registers whole resources (`apiResource`) for controllers that define only some operations. The tool
//   records such a route as `action_not_defined` and does not hit it. By that mechanical test (the class has no public
//   method of that name) 25 of the app's routes are recorded so: 3 without arguments and 22 with. The arguments those
//   22 routes carry are never filled, so a declaration only they needed is dropped from `arguments` (album, artist,
//   song, playlist, station: the routes that exist have typed controller methods the tool reads itself). The
//   playlist_folder declaration stays, because routes that exist use it.
//
// Subsonic
//   The Subsonic routes (/rest/...) log in with credentials found in the request's own input (`u` with `apiKey`, or
//   `u`, `t`, `s`), not with the session. after_generation stores an API key for each of the two logins, encrypted and
//   hashed the way the app reads it, under the one APP_KEY of the run (the environment variable PEAK_MEASURE_APP_KEY,
//   which every process of a run shares; the key itself is never written here, in a report or in a log). With no such
//   variable nothing is stored. Whether a request carries the credentials is up to whoever sends it.

$when = '2026-01-01 00:00:00';

$permissions = ['manage settings', 'manage users', 'manage songs', 'manage radio stations', 'manage podcasts'];

$profile = [
    // Spatie permission tables and settings are made by the migrations (and, for settings, by the app when it runs);
    // generated rows in them would be noise (a role named "roles_name_7") or wrong. The licence table stays empty:
    // with no licence row the app is the community edition, and checking one calls an outside service.
    'skip' => ['roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'settings', 'licenses'],

    // One generated organization: the users table needs a parent to point at while it is written. The two logins are
    // then moved into the default organization the migrations make (see after_generation).
    'rows' => ['organizations' => 1],

    'values' => [
        'users' => [
            // These columns are encrypted or one-off values; a generated string cannot be decrypted and breaks every
            // route that loads the user.
            'subsonic_api_key' => null,
            'subsonic_api_key_hash' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'invitation_token' => null,
            'invited_at' => null,
            'invitation_accepted_at' => null,
            'deletion_requested_at' => null,
            'sso_provider' => null,
            'sso_id' => null,
            // The app casts this column to a value object; an empty object is what it expects of a new user.
            'preferences' => '{}',
        ],
        // The app reads the queue as a JSON list of song ids; a generated word makes the first page load fail.
        'queue_states' => ['song_ids' => '[]'],
        // A playlist belongs to a user as its owner or as a collaborator; the generator writes a plain word, and the
        // app lists a user's own playlists by the value 'owner'.
        'playlist_user' => ['role' => 'owner'],
    ],

    'user' => ['id' => 1, 'name' => 'Measure User', 'email' => 'user@measure.invalid', 'password' => 'measure-pass', 'role' => 'user'],
    'admin' => ['id' => 2, 'name' => 'Measure Admin', 'email' => 'admin@measure.invalid', 'password' => 'measure-pass', 'role' => 'admin'],
    'login_as' => 'user',

    'reference_rows' => [
        // `Organization::default()` finds this by slug and creates it when missing; it is also what every user belongs to.
        ['table' => 'organizations', 'match' => ['slug' => 'koel'], 'row' => ['id' => '01j00000000000000000000001', 'name' => 'Koel', 'slug' => 'koel', 'created_at' => $when, 'updated_at' => $when]],
        ['table' => 'roles', 'match' => ['name' => 'admin', 'guard_name' => 'api'], 'row' => ['name' => 'admin', 'guard_name' => 'api', 'created_at' => $when, 'updated_at' => $when]],
        ['table' => 'roles', 'match' => ['name' => 'manager', 'guard_name' => 'api'], 'row' => ['name' => 'manager', 'guard_name' => 'api', 'created_at' => $when, 'updated_at' => $when]],
        ['table' => 'roles', 'match' => ['name' => 'user', 'guard_name' => 'api'], 'row' => ['name' => 'user', 'guard_name' => 'api', 'created_at' => $when, 'updated_at' => $when]],
        ['table' => 'roles', 'match' => ['name' => 'guest', 'guard_name' => 'api'], 'row' => ['name' => 'guest', 'guard_name' => 'api', 'created_at' => $when, 'updated_at' => $when]],
        ...array_map(
            static fn (string $name): array => ['table' => 'permissions', 'match' => ['name' => $name, 'guard_name' => 'api'], 'row' => ['name' => $name, 'guard_name' => 'api', 'created_at' => $when, 'updated_at' => $when]],
            $permissions,
        ),
        // The one setting the app's own seeder writes: an install-wide (no organization) media path, empty. The value
        // column is JSON, so an empty string is stored as two quotes.
        ['table' => 'settings', 'match' => ['key' => 'media_path', 'organization_id' => null], 'row' => ['key' => 'media_path', 'organization_id' => null, 'value' => '""']],
    ],

    'role_permissions' => [
        'admin' => $permissions,
        'manager' => ['manage users', 'manage podcasts', 'manage songs', 'manage radio stations'],
        'user' => [],
        'guest' => [],
    ],

    'fixups' => [
        [
            'name' => 'songs_are_not_episodes',
            'why' => 'every generated song has a podcast, so the app treats all of them as podcast episodes and the song list is empty',
            'sql' => 'UPDATE songs SET podcast_id = NULL, episode_guid = NULL',
        ],
    ],

    // Route argument => where its value comes from: the first row of a table (a column of it), or a literal. Koel binds
    // most of them to a model by its primary key; the tool's own rules reach these only when the controller method
    // can be read (see the note in the report on invokable controllers), so the profile says them outright. Only
    // routes that exist are counted: the ones recorded as action_not_defined are never hit (see the head of this file).
    'arguments' => [
        'songId' => ['table' => 'songs', 'column' => 'id', 'why' => 'a song id is a text key; the argument is not bound to the model'],
        'playlist_folder' => ['table' => 'playlist_folders', 'column' => 'id', 'why' => 'bound by its key'],
        'playlistFolder' => ['table' => 'playlist_folders', 'column' => 'id', 'why' => 'bound by its key'],
        'podcast' => ['table' => 'podcasts', 'column' => 'id', 'why' => 'bound by its key'],
        'radioStation' => ['table' => 'radio_stations', 'column' => 'id', 'why' => 'a radio station id is a text key'],
        'duplicateUpload' => ['table' => 'duplicate_uploads', 'column' => 'id', 'why' => 'bound by its key'],
        'embed' => ['table' => 'embeds', 'column' => 'id', 'why' => 'bound by its key'],
        'passkey' => ['table' => 'passkeys', 'column' => 'id', 'why' => 'bound by its key'],
        'theme' => ['table' => 'themes', 'column' => 'id', 'why' => 'bound by its key'],
        'genre' => ['table' => 'genres', 'column' => 'public_id', 'why' => 'the genre is bound by its public id, not its number'],
        'user' => ['table' => 'users', 'column' => 'public_id', 'why' => 'the user is bound by its public id'],
    ],

    'unfillable' => [
        'subsonic' => ['reason' => 'unauthenticated', 'why' => 'the Subsonic routes read their credentials (u with apiKey, or u, t and s) from the request\'s own input; both logins hold an API key now (encrypted under the run\'s shared APP_KEY), but a request that carries no such input is not authenticated'],
        'embed_options' => ['params' => ['options'], 'reason' => 'unfilled_param', 'why' => 'the options argument of an embed stream is an encrypted string the app builds itself with that same per-process key'],
        'equalizer_preset_id' => ['params' => ['id'], 'reason' => 'unfilled_param', 'why' => 'a preset lives inside the user\'s preferences, not in a table, so there is no row to point at'],
        'dropbox_key' => ['params' => ['key'], 'reason' => 'external_call_blocked', 'why' => 'authorizing a Dropbox app calls the Dropbox service'],
        'email_change_confirm' => ['params' => [], 'reason' => 'signature_required', 'why' => 'the confirmation link is a signed URL'],
        'log_viewer_files' => ['params' => ['fileIdentifier', 'folderIdentifier'], 'reason' => 'file_dependency', 'why' => 'the log viewer names log files that exist on the app\'s disk, and the app is mounted read-only with none'],
        'license' => ['reason' => 'external_call_blocked', 'why' => 'checking a licence calls an outside service; no licence row is made, so the community edition is measured'],
    ],
];

$profile['after_generation'] =
    /**
     * @return array{reference_rows: array{inserted: int, present: int}, role_permissions: int, logins: array<string, int>, subsonic: array{api_keys: int}, fixups: array<string, int>}
     */
    static function (PDO $pdo, string $database) use ($profile): array {
        DbGuard::assertDbName($database);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $current = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($current !== $database) {
                throw new RuntimeException(sprintf('refused: the connection is on %s, not on the measurement database %s', is_string($current) ? $current : 'no database', $database));
            }
        }

        // `$column IS NULL` for a null value, `$column = ?` otherwise.
        $where = static function (array $match): array {
            $parts = [];
            $args = [];
            foreach ($match as $column => $value) {
                if ($value === null) {
                    $parts[] = sprintf('`%s` IS NULL', $column);
                } else {
                    $parts[] = sprintf('`%s` = ?', $column);
                    $args[] = $value;
                }
            }

            return [implode(' AND ', $parts), $args];
        };
        $one = static function (string $sql, array $args) use ($pdo): mixed {
            $statement = $pdo->prepare($sql);
            $statement->execute($args);
            $value = $statement->fetchColumn();

            return $value === false ? null : $value;
        };
        $insert = static function (string $table, array $row) use ($pdo): void {
            $columns = array_map(static fn (string $c): string => '`' . $c . '`', array_keys($row));
            $pdo->prepare(sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, count($row), '?'))))
                ->execute(array_values($row));
        };

        $summary = ['reference_rows' => ['inserted' => 0, 'present' => 0], 'role_permissions' => 0, 'logins' => [], 'subsonic' => ['api_keys' => 0], 'fixups' => []];

        foreach ($profile['reference_rows'] as $entry) {
            [$clause, $args] = $where($entry['match']);
            if ($one(sprintf('SELECT 1 FROM `%s` WHERE %s LIMIT 1', $entry['table'], $clause), $args) !== null) {
                ++$summary['reference_rows']['present'];
                continue;
            }
            $insert($entry['table'], $entry['row']);
            ++$summary['reference_rows']['inserted'];
        }

        $roleId = static fn (string $name): mixed => $one('SELECT id FROM roles WHERE name = ? AND guard_name = ?', [$name, 'api']);

        foreach ($profile['role_permissions'] as $role => $names) {
            foreach ($names as $name) {
                $row = ['permission_id' => $one('SELECT id FROM permissions WHERE name = ? AND guard_name = ?', [$name, 'api']), 'role_id' => $roleId($role)];
                [$clause, $args] = $where($row);
                if ($one(sprintf('SELECT 1 FROM role_has_permissions WHERE %s LIMIT 1', $clause), $args) === null) {
                    $insert('role_has_permissions', $row);
                    ++$summary['role_permissions'];
                }
            }
        }

        $organization = $one('SELECT id FROM organizations WHERE slug = ?', ['koel']);
        foreach (['user', 'admin'] as $who) {
            $login = $profile[$who];
            $update = $pdo->prepare('UPDATE users SET organization_id = ?, name = ?, email = ?, password = ?, preferences = ? WHERE id = ?');
            $update->execute([$organization, $login['name'], $login['email'], password_hash($login['password'], PASSWORD_BCRYPT, ['cost' => 4]), '{}', $login['id']]);
            if ($one('SELECT 1 FROM users WHERE id = ? AND email = ?', [$login['id'], $login['email']]) === null) {
                throw new RuntimeException(sprintf('the generated users have no row %d to log in as %s', $login['id'], $who));
            }
            $link = ['role_id' => $roleId($login['role']), 'model_type' => 'App\\Models\\User', 'model_id' => $login['id']];
            [$clause, $args] = $where($link);
            if ($one(sprintf('SELECT 1 FROM model_has_roles WHERE %s LIMIT 1', $clause), $args) === null) {
                $insert('model_has_roles', $link);
            }
            $summary['logins'][$who] = $login['id'];
        }

        // The run's one APP_KEY, from the environment (never from this file). A malformed one is refused (without being
        // quoted); an absent one stores nothing, since a value the app cannot decrypt would break every route that loads
        // the user.
        $given = getenv(AppBooter::APP_KEY_ENV);
        if (is_string($given) && $given !== '') {
            $appKey = AppBooter::appKeyFrom([AppBooter::APP_KEY_ENV => $given]);
            $raw = (string) base64_decode(substr($appKey, 7), true);
            foreach (['user', 'admin'] as $who) {
                $id = $profile[$who]['id'];
                // A synthetic key, one per login. The app keeps it encrypted (the `encrypted` cast: Crypt::encryptString
                // under AES-256-CBC) and looks a user up by its HMAC-SHA256 under APP_KEY.
                $apiKey = sprintf('measure-subsonic-%d-%s', $id, bin2hex(random_bytes(8)));
                $iv = random_bytes(16);
                $value = openssl_encrypt($apiKey, 'aes-256-cbc', $raw, 0, $iv);
                if ($value === false) {
                    throw new RuntimeException('the Subsonic API key could not be encrypted');
                }
                $ivText = base64_encode($iv);
                $stored = base64_encode((string) json_encode(['iv' => $ivText, 'value' => $value, 'mac' => hash_hmac('sha256', $ivText . $value, $raw), 'tag' => ''], JSON_UNESCAPED_SLASHES));
                $pdo->prepare('UPDATE users SET subsonic_api_key = ?, subsonic_api_key_hash = ? WHERE id = ?')
                    ->execute([$stored, hash_hmac('sha256', $apiKey, $appKey), $id]);
                ++$summary['subsonic']['api_keys'];
            }
        }

        foreach ($profile['fixups'] as $fix) {
            $summary['fixups'][$fix['name']] = (int) $pdo->exec($fix['sql']);
        }

        return $summary;
    };

return $profile;
