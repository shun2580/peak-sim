<?php

// Routes for the /ng group. Loaded by bootstrap/app.php with the "/ng" prefix and the "web" middleware.
// Owner: the ng group (see FIXTURES.md). The real routes live in two files, one per worker;
// they are required here, so they inherit the prefix and middleware above.

require __DIR__.'/ng_basic.php';
require __DIR__.'/ng_deep.php';
