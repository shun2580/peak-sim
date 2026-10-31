<?php

declare(strict_types=1);

return [
    'description' => 'A model that has an observer may run hidden queries when it is saved: gap.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps_include' => ['MODEL_OBSERVER_NOT_ANALYZED'],
            'events_found' => '>=1',
        ],
    ],
];
