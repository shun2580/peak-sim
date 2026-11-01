<?php

declare(strict_types=1);

// Knowledge about the verification app that its schema cannot give. Used only when asked for (`--profile=fixtures`);
// a run without it is the generator on its own, and the two are reported apart.
//
// orders.status: a controller filters the orders it loaded for the status 'paid'. The generator writes a placeholder
// into a plain string column, which no filter matches, so that route would see no data at all.
return [
    'values' => [
        'orders' => ['status' => 'paid'],
    ],
];
