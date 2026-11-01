<?php

declare(strict_types=1);

return [
    'description' => 'What the tool cannot follow through a resource property or a column value stays a gap (unknown), never a quiet O(1).',
    'routes' => [
        '/untyped-prop' => [
            'query' => ['growth' => 'unknown'],
            'memory' => ['growth' => 'unknown'],
            'confidence' => null,
            'gaps_include' => ['UNRESOLVED_CALL'],
        ],
        '/vendor-prop' => [
            'query' => ['growth' => 'unknown'],
            'memory' => ['growth' => 'unknown'],
            'confidence' => null,
            'gaps_any' => ['VENDOR_CALL_NOT_MODELLED', 'UNRESOLVED_CALL'],
        ],
        '/scalar-chain' => [
            'query' => ['growth' => 'unknown'],
            'memory' => ['growth' => 'unknown'],
            'confidence' => null,
            'gaps_include' => ['UNRESOLVED_CALL'],
        ],
        '/scalar-chain-plain' => [
            'query' => ['growth' => 'unknown'],
            'memory' => ['growth' => 'unknown'],
            'confidence' => null,
            'gaps_include' => ['UNRESOLVED_CALL'],
        ],
    ],
];
