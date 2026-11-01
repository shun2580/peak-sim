<?php

declare(strict_types=1);

use PeakSim\Runtime\Guard\DbGuard;

// Knowledge about this app (Koel, a Laravel music server) that its schema and its routes cannot give: the first profile
// (`koel`) with one more kind of knowledge added, who the generated rows belong to. A run uses it only when asked for
// (`--profile=koel_userbound`), and a result reached with it is reported as such, apart from one reached with `koel`.
//
// Why a second profile. Many of Koel's list routes do not return "the rows of a table"; they return the rows of a table
// that belong to the logged-in user (their playlists, their radio stations, the users of their organization). The
// generator hands the foreign keys of a child table round among its parents, so of a thousand playlists the login owns
// one, however many there are. A route that reads only what the login owns then shows the same answer at every size,
// and a flat count there says little about the route: the added rows never reached it. This profile points the
// generated rows at the login (the user the first request logs in as, `login_as` of the first profile) so that the same
// routes can be measured with their rows in reach.
//
// This is knowledge, not a rule of judgement: nothing here decides whether a count is flat or grows. Each pointing below
// says which column the app's code filters by, and where (file and line in Koel at the commit the trials use). The app is
// never changed and its seeder is never run. Every statement runs only in a measurement database, after the generator
// has filled it and after everything the first profile does; all of them are safe to run twice.
//
// Not done here, and why it matters when reading a result:
//   - a table that does not grow in the data plan of a route stays small whatever it points at. The plan is fixed by the
//     static analysis of the route (its n_table), not by this file. Where the plan leaves a pivot or a child table at the
//     base size (a few rows), the pointing below can add rows derived from rows that did grow (playlists, albums); it
//     does not do so for duplicate uploads, whose rows are not derived from any grown table.
//   - polymorphic or JSON-held relations (favourites, equalizer presets, plays) are not pointed at the login: that needs
//     more knowledge than a column to point.
//
// It is a proof-of-concept artefact. For a permanent version, how to generalise this kind of knowledge (so that a person
// does not have to write one such file per app) is to be designed separately.

$base = (static function (): array {
    $profile = require __DIR__ . '/koel.php';
    if (!is_array($profile)) {
        throw new RuntimeException('the first Koel profile must return an array');
    }

    return $profile;
})();

$login = (int) $base['user']['id'];
if ($login < 1) {
    throw new RuntimeException('the login to point the rows at needs a user id');
}

// One statement each, run in this order. `evidence` is where the app's code reads the column (Koel da0e70f, app/...).
$pointings = [
    [
        'name' => 'users_in_the_default_organization',
        'why' => 'the user list is the users of the login\'s organization; the first profile moves only the two logins into the default one, and the generated users sit in a generated organization the login cannot see',
        'evidence' => 'UserController.php:24-29 (getAllInOrganization), UserRepository.php:61-64',
        'sql' => "UPDATE users SET organization_id = (SELECT id FROM organizations WHERE slug = 'koel')",
    ],
    [
        'name' => 'playlists_owned_by_the_login',
        'why' => 'a playlist is listed for a user when the user holds it in the pivot with the role owner (community edition); the generator spreads the pivot over all users. The pivot stays at the base size when only playlists grow, so a row is added for every playlist the login does not hold yet',
        'evidence' => 'PlaylistController.php:28-31, PlaylistRepository.php:16-22 and 48-51 (ownedPlaylists), HasUserRelationships.php:35-38',
        'sql' => sprintf(
            "INSERT INTO playlist_user (playlist_id, user_id, role) SELECT p.id, %d, 'owner' FROM playlists p WHERE NOT EXISTS (SELECT 1 FROM playlist_user pu WHERE pu.playlist_id = p.id AND pu.user_id = %d)",
            $login,
            $login,
        ),
    ],
    [
        'name' => 'playlist_songs_in_the_first_playlist',
        'why' => 'the songs of a playlist are the rows of the pivot that name it; the route is asked for the first playlist by key, and the generator spreads the pivot over all playlists, so that one holds a single song',
        'evidence' => 'PlaylistSongController.php:29-39, SongRepository.php:348-358 (getByStandardPlaylist)',
        'sql' => 'UPDATE playlist_song SET playlist_id = (SELECT id FROM playlists ORDER BY id LIMIT 1)',
    ],
    [
        'name' => 'playlist_folders_of_the_login',
        'why' => 'a user\'s folders are the folders whose user_id is theirs',
        'evidence' => 'PlaylistFolderController.php:22-25, HasUserRelationships.php:45-48 (playlistFolders)',
        'sql' => sprintf('UPDATE playlist_folders SET user_id = %d', $login),
    ],
    [
        'name' => 'radio_stations_of_the_login',
        'why' => 'a user sees the stations they created, plus public ones created in their organization; the generator spreads user_id over all users',
        'evidence' => 'RadioStationController.php:26-29, RadioStationRepository.php:35-38, RadioStationBuilder.php:16-40 (accessible)',
        'sql' => sprintf('UPDATE radio_stations SET user_id = %d', $login),
    ],
    [
        'name' => 'podcast_subscriptions_of_the_login',
        'why' => 'a user\'s podcasts are the ones they are subscribed to: rows of the pivot with their user_id',
        'evidence' => 'PodcastController.php:29-35, PodcastRepository.php:35-45, PodcastBuilder.php:18-25 (subscribed)',
        'sql' => sprintf('UPDATE podcast_user SET user_id = %d', $login),
    ],
    [
        'name' => 'passkeys_of_the_login',
        'why' => 'a user\'s passkeys are the rows whose user_id is theirs (the relation comes from the passkeys package; the column is the one the app\'s migration makes)',
        'evidence' => 'PasskeyController.php:20-24 ($user->passkeys)',
        'sql' => sprintf('UPDATE passkeys SET user_id = %d', $login),
    ],
    [
        'name' => 'duplicate_uploads_of_the_login',
        'why' => 'a user sees the duplicate uploads that belong to them',
        'evidence' => 'FetchDuplicateUploadsController.php:14-17, DuplicateUploadRepository.php:12-15',
        'sql' => sprintf('UPDATE duplicate_uploads SET user_id = %d', $login),
    ],
];

// One album for every artist that has none; returns how many were added. A key is "01K" and 23 characters of Crockford's
// base32 holding the row number: a ULID (the generator's own keys begin "01J"), so it cannot meet a generated one.
$addAlbums = static function (PDO $pdo, int $owner): int {
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $key = static function (int $n) use ($alphabet): string {
        $out = '';
        for ($k = 0; $k < 23; $k++) {
            $out = $alphabet[$n % 32] . $out;
            $n = intdiv($n, 32);
        }

        return '01K' . $out;
    };

    $ids = $pdo->query('SELECT a.id FROM artists a WHERE NOT EXISTS (SELECT 1 FROM albums b WHERE b.artist_id = a.id) ORDER BY a.id')->fetchAll(PDO::FETCH_COLUMN);
    if ($ids === []) {
        return 0;
    }
    $next = (int) $pdo->query("SELECT COUNT(*) FROM albums WHERE id LIKE '01K%'")->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO albums (id, name, artist_id, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    $when = '2026-01-01 00:00:00';
    $pdo->beginTransaction();
    try {
        foreach ($ids as $artistId) {
            ++$next;
            $insert->execute([$key($next), 'Album ' . $next, $artistId, $owner, $when, $when]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return count($ids);
};

$profile = $base;
// The first profile's own fixups stay in this list (they run in its after_generation); the pointings run after them.
$profile['fixups'] = [...$base['fixups'], ...$pointings];

$profile['after_generation'] =
    /**
     * @return array<string, mixed> the first profile's summary, with the number of rows each pointing changed or added
     */
    static function (PDO $pdo, string $database) use ($base, $pointings, $login, $addAlbums): array {
        DbGuard::assertDbName($database);

        // Everything the first profile does first (it also refuses a connection that is not on the measurement database).
        $summary = ($base['after_generation'])($pdo, $database);

        foreach ($pointings as $pointing) {
            $summary['fixups'][$pointing['name']] = (int) $pdo->exec($pointing['sql']);
        }

        // Albums. `/api/albums` pages the albums table (60 a page) and the community edition filters nothing, so what
        // matters is only how many there are. When just the artists table grows, albums stay at the base size, so one
        // album is added for each artist that has none. It is derived from rows that were generated, takes its owner
        // from the login, and has a key of the shape the app accepts (a ULID, "01K" then the row number).
        $summary['fixups']['albums_for_artists_without_one'] = $addAlbums($pdo, $login);

        return $summary;
    };

return $profile;
