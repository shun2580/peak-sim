<?php

declare(strict_types=1);

// Knowledge about this app (Koel, a Laravel music server) that its schema and its routes cannot give: the first profile
// (`koel`) with one change, who the first request of each route logs in as. A run uses it only when asked for
// (`--profile=koel_admin`), and a result reached with it is reported as such, apart from one reached with `koel`.
//
// Why a variant and not a new default. Some of Koel's routes are for administrators only (user management, editing an
// album or an artist): a general user gets 403 from the policy, so the route is recorded as not reached. Logging the
// first request in as the administrator reaches them. It is not the default of the first profile because the rows a
// login can see change with who it is (an administrator sees every user of the organization, a general user sees their
// own playlists), so a profile whose every request is made as the administrator measures a different set of visible
// rows from the one a general user meets. The two runs are meant to be laid over each other: run as a general user,
// then run as the administrator only the routes that answered 403, and report the second apart as such.
//
// This is knowledge, not a rule of judgement: nothing here decides whether a count is flat or grows. The administrator is
// the one the first profile already defines (`admin`); this file changes nothing else, and the first profile's
// `after_generation` (with its refusal of any database that is not a measurement database) is the one that runs. The app
// is never changed and its seeder is never run.
//
// It is a proof-of-concept artefact. For a permanent version, how to generalise this kind of knowledge (so that a person
// does not have to write one such file per app) is to be designed separately.

$profile = require __DIR__ . '/koel.php';
if (!is_array($profile)) {
    throw new RuntimeException('the first Koel profile must return an array');
}
$profile['login_as'] = 'admin';

return $profile;
