<?php

declare(strict_types=1);

/*
 * The allow-list of vendor calls the analysis may treat as "reads nothing from the database and calls nothing back
 * into the app".
 *
 * Listing a call here is a CLAIM, not a convenience. A listed call comes out as a plain value (or the type named
 * in `returns`) and adds no cost and no gap. Anything vendor-side that is NOT listed is a gap
 * (VENDOR_CALL_NOT_MODELLED): "not known" is never turned into "safe". So a call is listed only when
 *
 *   - basis `verified`: its source was read in laravel/framework v13.39.0 (the version in the fixtures lock file;
 *     paths below are relative to src/ of that package), or
 *   - basis `contract`: the public contract plainly says so (a PHP core function, or a documented helper).
 *
 * and each entry says why in `why`. An entry without `basis` and `why` makes the file fail to load. Nothing is
 * listed just to make a route pass; the 24 routes of the fixtures need the small set below and no more
 * (tests/Unit/Known/KnownTableTest.php pins the exact list).
 *
 * Sections
 *   functions  name => entry
 *   methods    class => [method => entry]      calls on an object of a vendor class ('*' = every method)
 *   static     class => [method => entry]      static calls and facades
 *   refused    mouths that are kept out ON PURPOSE, each with its reason
 *
 * An entry is either
 *   answered   returns: 'plain' | 'object:<class>' | 'arg:<n>' (the n-th argument's own type)
 *                       | 'arg:<n>:<name>' (the argument called <name>, else the n-th unnamed one, else a plain value)
 *              args:    [min, max] number of arguments the claim covers (max null = any); default [0, null]
 *              stores_closures: true when a closure argument is only kept, never called
 *              basis, why
 *   delegated  delegate: the handler that owns the call; this handler leaves it alone. why.
 *
 * Arguments are evaluated by the engine either way. For an answered entry no argument may be something the call
 * could call back into (an object, a builder, a closure unless stores_closures): count($countableObject) runs the
 * object's own count(), abort($responsable) runs its toResponse(). Such a call is a gap.
 *
 * The refused list below is not "not yet listed": it is the set of mouths that run policies, listeners, rules,
 * queries or network calls the tool cannot see. A route that uses one is `unknown` (design 1.10, decision D21).
 *
 * Names are matched without regard to case and a leading backslash.
 */

return [
    'functions' => [
        'abort_unless' => [
            'returns' => 'plain',
            'args' => [2, 4],
            'basis' => 'verified',
            'why' => 'Foundation/helpers.php: throws through abort() when the condition is false; abort() calls back into the app only for a Responsable argument, which the argument rule keeps out.',
        ],
        'cache' => [
            'returns' => 'object:Illuminate\\Cache\\CacheManager',
            'args' => [0, 0],
            'basis' => 'verified',
            'why' => 'Foundation/helpers.php: with no key it returns app(\'cache\'), the CacheManager, and does nothing else. With a key it reads or writes a store (a query on the database driver), so only the no-argument form is listed.',
        ],
        'count' => [
            'returns' => 'plain',
            'args' => [1, 2],
            'basis' => 'contract',
            'why' => 'PHP core: counts an array or a Countable in memory. A Countable object runs its own count(), which the argument rule keeps out (objects are refused).',
        ],
        'in_array' => [
            'returns' => 'plain',
            'args' => [2, 3],
            'basis' => 'contract',
            'why' => 'PHP core: a search in memory. Residual: a loose comparison of a string with an object inside the list can run that object\'s __toString(); list elements are not inspected.',
        ],
        'now' => [
            'returns' => 'plain',
            'args' => [0, 1],
            'basis' => 'verified',
            'why' => 'Foundation/helpers.php: Date::now(), a Carbon instance; no database, no callback.',
        ],
        'response' => [
            'returns' => 'object:Illuminate\\Contracts\\Routing\\ResponseFactory',
            'args' => [0, 0],
            'basis' => 'verified',
            'why' => 'Foundation/helpers.php: with no argument it returns the ResponseFactory. With content it builds a Response that may serialise it (a model, its $appends accessors), so that form is the response sink\'s business, not listed.',
        ],
        'round' => [
            'returns' => 'plain',
            'args' => [1, 2],
            'basis' => 'contract',
            'why' => 'PHP core: arithmetic on a number.',
        ],
        'view' => [
            'delegate' => 'ViewHandler',
            'why' => 'Renders a Blade template whose body has to be followed (design 1.11); the view handler owns it. Until that handler is registered a view() call is a gap, which is the safe outcome.',
        ],
    ],

    'methods' => [
        'Illuminate\\Cache\\CacheManager' => [
            'flexible' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'remember' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'rememberForever' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'sear' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
        ],
        'Illuminate\\Cache\\Repository' => [
            'flexible' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'remember' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'rememberForever' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'sear' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
        ],
        'Illuminate\\Contracts\\Cache\\Repository' => [
            'flexible' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'remember' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'rememberForever' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'sear' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
        ],
        'Illuminate\\Contracts\\Routing\\ResponseFactory' => [
            'json' => ['delegate' => 'ResponseSink', 'why' => 'The body is serialised here, so the models inside it (their $appends, their loaded relations) are the response sink\'s to follow.'],
            'view' => ['delegate' => 'ViewHandler', 'why' => 'Renders a Blade template whose body has to be followed; the view handler owns it.'],
        ],
        'Illuminate\\Http\\Request' => [
            'query' => [
                'returns' => 'plain',
                'args' => [1, 2],
                'basis' => 'verified',
                'why' => 'Http/Concerns/InteractsWithInput.php: reads one item of the query string from the request in memory; the default is returned, never called. The no-key form is not needed by the routes and is not listed.',
            ],
        ],
        'Illuminate\\Routing\\ResponseFactory' => [
            'json' => ['delegate' => 'ResponseSink', 'why' => 'The body is serialised here, so the models inside it (their $appends, their loaded relations) are the response sink\'s to follow.'],
            'view' => ['delegate' => 'ViewHandler', 'why' => 'Renders a Blade template whose body has to be followed; the view handler owns it.'],
        ],
    ],

    'static' => [
        'Cache' => [
            'flexible' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores. (The global alias of the facade.)'],
            'remember' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores. (The global alias of the facade.)'],
            'rememberForever' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores. (The global alias of the facade.)'],
            'sear' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores. (The global alias of the facade.)'],
        ],
        'Illuminate\\Database\\Eloquent\\Casts\\Attribute' => [
            'get' => [
                'returns' => 'arg:0:get',
                'args' => [1, 1],
                'stores_closures' => true,
                'basis' => 'verified',
                'why' => 'Database/Eloquent/Casts/Attribute.php: new static($get) keeps the callable and calls nothing. It hands back the getter closure itself so whoever evaluates the accessor can run its body.',
            ],
            'make' => [
                'returns' => 'arg:0:get',
                'args' => [0, 2],
                'stores_closures' => true,
                'basis' => 'verified',
                'why' => 'Database/Eloquent/Casts/Attribute.php: new static($get, $set) keeps the callables and calls nothing. It hands back the getter (the argument named get, else the first); a call with no getter gives a plain value, and the setter is never run.',
            ],
        ],
        'Illuminate\\Support\\Facades\\Cache' => [
            'flexible' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'remember' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'rememberForever' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
            'sear' => ['delegate' => 'CacheOps', 'why' => 'The cache handler runs the closure for the miss and counts what a hit restores.'],
        ],
    ],

    'refused' => [
        [
            'class' => 'Illuminate\\Support\\Facades\\Gate',
            'why' => 'Gate and policy checks run policy code that may query the database or call anything; the tool does not follow policies.',
        ],
        [
            'class' => 'Illuminate\\Contracts\\Auth\\Access\\Gate',
            'why' => 'Gate and policy checks run policy code that may query the database or call anything; the tool does not follow policies.',
        ],
        [
            'class' => 'Illuminate\\Auth\\Access\\Gate',
            'why' => 'Gate and policy checks run policy code that may query the database or call anything; the tool does not follow policies.',
        ],
        [
            'class' => 'Illuminate\\Support\\Facades\\Validator',
            'why' => 'A validation rule such as exists or unique issues a SELECT per rule (and per row); the tool does not model rules.',
        ],
        [
            'class' => 'Illuminate\\Contracts\\Validation\\Factory',
            'why' => 'A validation rule such as exists or unique issues a SELECT per rule (and per row); the tool does not model rules.',
        ],
        [
            'class' => 'Illuminate\\Validation\\Factory',
            'why' => 'A validation rule such as exists or unique issues a SELECT per rule (and per row); the tool does not model rules.',
        ],
        [
            'class' => 'Illuminate\\Http\\Request',
            'methods' => ['user', 'validate', 'validateWithBag'],
            'why' => 'validate() runs rules that may query (exists, unique); user() resolves the signed-in user through the user provider, which queries.',
        ],
        [
            'function' => 'validator',
            'why' => 'Builds a validator whose rules (exists, unique) issue queries; the tool does not model rules.',
        ],
        [
            'function' => 'event',
            'why' => 'Runs every listener registered for the event; the tool does not see listeners, and a listener may query or call anything.',
        ],
        [
            'function' => 'dispatch',
            'why' => 'Runs a job (synchronously, or through a queue driver that may write to the database); the tool does not follow jobs.',
        ],
        [
            'function' => 'dispatch_sync',
            'why' => 'Runs a job synchronously inside the request; the tool does not follow jobs.',
        ],
        [
            'function' => 'broadcast',
            'why' => 'Fires a broadcast event: listeners and the broadcaster run, and the tool does not see either.',
        ],
        [
            'class' => 'Illuminate\\Support\\Facades\\Event',
            'why' => 'Runs every listener registered for the event; the tool does not see listeners.',
        ],
        [
            'class' => 'Illuminate\\Contracts\\Events\\Dispatcher',
            'why' => 'Runs every listener registered for the event; the tool does not see listeners.',
        ],
        [
            'class' => 'Illuminate\\Support\\Facades\\Bus',
            'why' => 'Runs or queues jobs; the tool does not follow jobs, and a queue driver may write to the database.',
        ],
        [
            'class' => 'Illuminate\\Contracts\\Bus\\Dispatcher',
            'why' => 'Runs or queues jobs; the tool does not follow jobs, and a queue driver may write to the database.',
        ],
        [
            'class' => 'Illuminate\\Support\\Facades\\Queue',
            'why' => 'Pushes a job: a queue driver may write to the database, and a sync queue runs the job in the request.',
        ],
        [
            'class' => 'Illuminate\\Support\\Facades\\Auth',
            'why' => 'Auth::user() and the guards resolve the signed-in user through the user provider, which queries the database.',
        ],
        [
            'class' => 'Illuminate\\Contracts\\Auth\\Guard',
            'why' => 'user() resolves the signed-in user through the user provider, which queries the database.',
        ],
        [
            'class' => 'Illuminate\\Contracts\\Auth\\Factory',
            'why' => 'Guards resolve the signed-in user through the user provider, which queries the database.',
        ],
        [
            'class' => 'Illuminate\\Auth\\AuthManager',
            'why' => 'Guards resolve the signed-in user through the user provider, which queries the database.',
        ],
        [
            'function' => 'auth',
            'why' => 'Returns the guard; the user behind it is resolved through the user provider, which queries the database.',
        ],
        [
            'class' => 'Illuminate\\Support\\Facades\\Mail',
            'why' => 'Rendering a mailable runs its view and its data code (queries), and a queued mail writes to the queue.',
        ],
        [
            'class' => 'Illuminate\\Contracts\\Mail\\Mailer',
            'why' => 'Rendering a mailable runs its view and its data code (queries), and a queued mail writes to the queue.',
        ],
        [
            'class' => 'Illuminate\\Support\\Facades\\Notification',
            'why' => 'Notifications run channels and their data code, which may query, call out or queue.',
        ],
        [
            'class' => 'Illuminate\\Contracts\\Notifications\\Dispatcher',
            'why' => 'Notifications run channels and their data code, which may query, call out or queue.',
        ],
        [
            'class' => 'Illuminate\\Support\\Facades\\Http',
            'why' => 'Talks to the network; the cost is not the app\'s database or memory, and the response is not knowable.',
        ],
        [
            'class' => 'Illuminate\\Http\\Client\\Factory',
            'why' => 'Talks to the network; the cost is not the app\'s database or memory, and the response is not knowable.',
        ],
        [
            'class' => 'Illuminate\\Support\\Facades\\DB',
            'methods' => ['raw'],
            'why' => 'A raw SQL fragment can hide a subquery or a function with a cost of its own; it is the query handler\'s to read (SQL_NOT_PARSED), never a plain value.',
        ],
        [
            'function' => 'app',
            'why' => 'Resolves whatever the container holds: the class can be bound to app code, and a dynamic abstract cannot be told at all. Container resolution is the engine\'s business (bindings), not an allow-list item.',
        ],
        [
            'function' => 'resolve',
            'why' => 'Resolves whatever the container holds: the class can be bound to app code. Container resolution is the engine\'s business (bindings), not an allow-list item.',
        ],
        [
            'class' => 'Illuminate\\Support\\Facades\\App',
            'methods' => ['make', 'makeWith', 'resolve'],
            'why' => 'Resolves whatever the container holds: the class can be bound to app code. Container resolution is the engine\'s business (bindings), not an allow-list item.',
        ],
        [
            'class' => 'Illuminate\\Contracts\\Container\\Container',
            'methods' => ['make', 'makeWith', 'resolve', 'get'],
            'why' => 'Resolves whatever the container holds: the class can be bound to app code. Container resolution is the engine\'s business (bindings), not an allow-list item.',
        ],
        [
            'class' => 'Illuminate\\Foundation\\Application',
            'methods' => ['make', 'makeWith', 'resolve', 'get'],
            'why' => 'Resolves whatever the container holds: the class can be bound to app code. Container resolution is the engine\'s business (bindings), not an allow-list item.',
        ],
    ],
];
