<?php

declare(strict_types=1);

return [
    'description' => 'The N of an insert comes from the table that was plucked (customers), not from the table written (orders).',
    'routes' => [
        '/t' => [
            'query' => 'O(1)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'customers',
            'gaps' => [],
            'events_found' => '>=1',
        ],
    ],
];
