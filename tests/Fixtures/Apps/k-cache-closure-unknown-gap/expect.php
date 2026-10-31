<?php

declare(strict_types=1);

return [
    'description' => 'If what is cached cannot be followed, neither side of the cache can be judged.',
    'routes' => [
        '/t' => [
            'query' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'memory' => ['growth' => 'unknown', 'confidence' => null, 'lower_bound' => null],
            'confidence' => null,
            'gaps_include' => ['CACHE_VALUE_SIZE_UNKNOWN'],
            'gaps_any' => ['UNRESOLVED_CALL', 'VENDOR_CALL_NOT_MODELLED'],
        ],
    ],
];
