<?php

declare(strict_types=1);

return [
    'description' => 'Two providers bind the same interface to different implementations; the tool must take the worse one and say so.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'O(N)', 'confidence' => 'medium'],
            'memory' => ['growth' => 'O(N)', 'confidence' => 'high'],
            'confidence' => 'medium',
            'n_table' => 'orders',
            'gaps' => [],
            'events_found' => '>=1',
            'assumptions' => ['WORST_CASE_OF_CANDIDATES'],
            'worst_case_adopted' => true,
        ],
    ],
];
