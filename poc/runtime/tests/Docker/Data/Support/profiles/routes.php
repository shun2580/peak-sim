<?php

declare(strict_types=1);

// Test profile for the wiring of `arguments`, `unfillable` and the logins into the inspection (the step that turns
// every route into a task). It names a source for one route argument, explains two others, and says who logs in.
return [
    'user' => ['id' => 1, 'role' => 'user'],
    'admin' => ['id' => 2, 'role' => 'admin'],
    'login_as' => 'admin',
    'arguments' => [
        'song' => ['table' => 'customers', 'column' => 'email', 'why' => 'the first customer stands in for a song'],
    ],
    'unfillable' => [
        'dropbox_key' => ['params' => ['key'], 'reason' => 'external_call_blocked', 'why' => 'calls an outside service'],
        'signed_links' => ['routes' => ['uri_prefix' => ['probe/signed/']], 'reason' => 'signature_required', 'why' => 'the link is a signed URL'],
    ],
];
