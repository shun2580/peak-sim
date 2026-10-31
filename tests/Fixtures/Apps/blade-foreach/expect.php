<?php

declare(strict_types=1);

return [
    'description' => 'A relation read inside @foreach in a Blade view is an N+1, and the evidence points at the template line.',
    'routes' => [
        '/t' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'evidence_include' => ['resources/views/t.blade.php:2'],
        ],
    ],
];
