<?php

declare(strict_types=1);

return [
    'description' => 'A relation read through a resource\'s own property that holds the wrapped model is already loaded when the model was loaded with it; any other case still lazy-loads.',
    'routes' => [
        // The property holds the very model the resource wraps, and the model came with its relation: nothing is loaded again.
        '/promoted-eager' => [
            'query' => 'O(1)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'assumptions' => [],
            'events_found' => '>=1',
        ],
        '/prop-eager' => [
            'query' => 'O(1)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'assumptions' => [],
            'events_found' => '>=1',
        ],
        '/promoted-eager-map-new' => [
            'query' => 'O(1)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'assumptions' => [],
            'events_found' => '>=1',
        ],
        '/promoted-eager-make' => [
            'query' => 'O(1)',
            'memory' => 'O(1)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'assumptions' => [],
            'events_found' => '>=1',
        ],
        '/nested-eager' => [
            'query' => 'O(1)',
            'memory' => 'O(N*M)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'm_table' => 'items',
            'm_per' => 'orders',
            'gaps' => [],
            'assumptions' => [],
            'events_found' => '>=1',
        ],
        // The relation was not loaded with the model (nothing, or another relation): the lazy load is still found. (cmd_248 INV2)
        '/promoted-lazy' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'assumptions' => [],
            'events_found' => 2,
        ],
        '/prop-lazy' => [
            'query' => 'O(N)',
            'memory' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'assumptions' => [],
            'events_found' => 2,
        ],
        '/promoted-other-relation' => [
            'query' => 'O(N)',
            'memory' => 'O(N*M)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'm_table' => 'items',
            'm_per' => 'orders',
            'gaps' => [],
            'assumptions' => [],
            'events_found' => 3,
            'evidence_include' => ['app/Http/Resources/PromotedResource.php:22'],
        ],
        // items is loaded with the order, product is not: one lazy load per item.
        '/nested-shallow' => [
            'query' => 'O(N*M)',
            'memory' => 'O(N*M)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'assumptions' => [],
            'events_found' => 3,
        ],
        // The property is of the wrapped class but is not the model the resource wraps: it says nothing about what was loaded.
        '/other-order' => [
            'query' => 'O(N)',
            'confidence' => 'medium',
            'n_table' => 'orders',
            'gaps' => [],
            'assumptions' => ['FIND_ARGUMENT_ASSUMED_SCALAR'],
            'evidence_include' => ['app/Http/Resources/OtherOrderResource.php:23'],
        ],
        // The resource wraps its second constructor argument: the first argument (loaded with its relation) is not what the property holds.
        '/wrapped-second' => [
            'query' => 'O(N)',
            'confidence' => 'medium',
            'n_table' => 'orders',
            'gaps' => [],
            'assumptions' => ['FIND_ARGUMENT_ASSUMED_SCALAR'],
            'evidence_include' => ['app/Http/Resources/WrappedSecondResource.php:22'],
        ],
        // The property is of another class than the wrapped model: its relations are read as before.
        '/buyer' => [
            'query' => 'O(N)',
            'confidence' => 'high',
            'n_table' => 'orders',
            'gaps' => [],
            'assumptions' => [],
            'evidence_include' => ['app/Http/Resources/BuyerResource.php:23'],
        ],
    ],
];
