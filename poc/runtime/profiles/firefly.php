<?php

declare(strict_types=1);

use PeakSim\Runtime\Guard\DbGuard;

// Knowledge about this app (Firefly III, a Laravel personal-finance manager) that its schema and its routes cannot
// give. A run uses it only when asked for (`--profile=firefly`), and a result reached with it is reported as such,
// apart from one reached without.
//
// This is knowledge, not a rule of judgement: nothing here decides whether a count is flat or grows. The app is never
// changed and its seeder is never run. Each entry says why it is there, and where in the app that is.
$profile = [
    'values' => [
        'users' => [
            // The two-factor middleware asks for a one-time password whenever the user has a secret
            // (vendor/jc5/google2fa-laravel/src/Google2FA.php:125, isActivated). The generator writes a word into the
            // column, so every page stops at the password prompt instead of reaching its handler.
            'mfa_secret' => null,
        ],
        // The time-zone columns. The app reads a date column together with its `_tz` column and builds a time zone
        // from it (app/Casts/SeparateTimezoneCaster.php:50); a generated word is not a time zone, and the page fails
        // before or after its handler. All of them are given the one value, so that this entry does not have to be
        // reopened for each route that reads another.
        'account_balances' => ['date_tz' => 'UTC'],
        'available_budgets' => ['end_date_tz' => 'UTC', 'start_date_tz' => 'UTC'],
        'bills' => ['date_tz' => 'UTC', 'end_date_tz' => 'UTC', 'extension_date_tz' => 'UTC'],
        'budget_limits' => ['end_date_tz' => 'UTC', 'start_date_tz' => 'UTC'],
        'currency_exchange_rates' => ['date_tz' => 'UTC'],
        'invited_users' => ['expires_tz' => 'UTC'],
        'period_statistics' => ['end_tz' => 'UTC', 'start_tz' => 'UTC'],
        'piggy_bank_events' => ['date_tz' => 'UTC'],
        'piggy_bank_repetitions' => ['start_date_tz' => 'UTC', 'target_date_tz' => 'UTC'],
        'piggy_banks' => ['start_date_tz' => 'UTC', 'target_date_tz' => 'UTC'],
        'recurrences' => ['first_date_tz' => 'UTC', 'latest_date_tz' => 'UTC', 'repeat_until_tz' => 'UTC'],
        'tags' => ['date_tz' => 'UTC'],
        'transaction_journals' => ['date_tz' => 'UTC'],
    ],

    /**
     * Run once the app has started, before anyone is logged in, in the process that measures a route.
     */
    'on_boot' => static function (object $app): void {
        // 1. The token guard of the API (Passport) reads a public key when it is first asked for, and the app has
        //    none (config/passport.php:`public_key` is read from PASSPORT_PUBLIC_KEY; storage/oauth-public.key is not
        //    in a fresh install), so every API route stops with "Invalid key supplied" before it is logged in. A
        //    throw-away key, made here and kept only in this process, is all the guard needs: no token is ever checked.
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($pair !== false) {
            $details = openssl_pkey_get_details($pair);
            if (is_array($details) && is_string($details['key'] ?? null)) {
                $app['config']->set('passport.public_key', $details['key']);
            }
        }

        // 2. The pages ask Vite for the built front-end files (resources/views/layout/v3/blank.blade.php:22 @vite),
        //    which are not in the app as it is checked out, and fail with "Vite manifest not found" once the handler
        //    has run. The asset tags carry no data, so the helper is replaced by the empty one Laravel's own tests use.
        $vite = \Illuminate\Foundation\Vite::class;
        \Illuminate\Support\Facades\Facade::clearResolvedInstance($vite);
        $app->instance($vite, new class extends \Illuminate\Foundation\Vite {
            public function __invoke($entrypoints, $buildDirectory = null)
            {
                return new \Illuminate\Support\HtmlString('');
            }

            public function __call($method, $parameters)
            {
                return '';
            }

            public function __toString(): string
            {
                return '';
            }

            public function useIntegrityKey($key)
            {
                return $this;
            }

            public function useBuildDirectory($path)
            {
                return $this;
            }

            public function useHotFile($path)
            {
                return $this;
            }

            public function withEntryPoints($entryPoints)
            {
                return $this;
            }

            public function preloadedAssets()
            {
                return [];
            }

            public function content($asset, $buildDirectory = null)
            {
                return '';
            }

            public function asset($asset, $buildDirectory = null)
            {
                return '';
            }
        });
    },

    // Route argument => where its value comes from. The tool's own rules cannot read these (the routes name the
    // argument and the controller method does not type it as a model), so the profile says them outright.
    'arguments' => [
        'tagOrId' => ['table' => 'tags', 'column' => 'id', 'why' => 'the argument takes a tag by its number or its name (app/Models/Tag.php routeBinder); the number is used'],
        'transactionType' => ['value' => 'withdrawal', 'why' => 'the convert pages take the type to convert to as a word (routes/web.php transactions/convert/{transactionType}/{transactionGroup})'],
        'transactionGroup' => ['table' => 'transaction_groups', 'column' => 'id', 'why' => 'a group is bound by its number; the controller methods of the convert and destroy pages do not type it as a model'],
    ],

    // A GET route whose handler refuses a request without a field it requires (422), so its handler is not reached
    // without the field.
    'request_query' => [
        'validate_expression' => ['routes' => ['uri_prefix' => ['api/v1/rules/validate-expression']], 'query' => ['expression' => 'description_contains("a")']],
    ],

    // Single UPDATE statements that give a generated row the meaning the app looks for. The app's seeder is not run.
    'fixups' => [
        [
            'name' => 'account_types_are_asset',
            'why' => 'a show page sends an account to another page unless its type is one the app knows (app/Http/Controllers/Account/ShowController.php:95, config firefly.shortNamesByFullName); the generated types are made-up words',
            'sql' => "UPDATE account_types SET type = 'Asset account' WHERE id = 1",
        ],
        [
            'name' => 'transaction_types_are_withdrawal',
            'why' => 'the edit page sends a transaction of an unknown type to the account page (app/Http/Controllers/Transaction/EditController.php:82)',
            'sql' => "UPDATE transaction_types SET type = 'Withdrawal' WHERE id = 1",
        ],
        [
            'name' => 'system_currency',
            'why' => 'the app looks the system currency up by the code EUR (app/Support/Amount.php:332) and every page that shows an amount needs it; the generated rows carry made-up codes',
            'sql' => "UPDATE transaction_currencies SET code = 'EUR', name = 'Euro', symbol = 'E', decimal_places = 2, enabled = 1 WHERE id = 1",
        ],
    ],
];

$profile['after_generation'] =
    /** @return array{fixups: array<string, int>} */
    static function (PDO $pdo, string $database) use ($profile): array {
        DbGuard::assertDbName($database);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $current = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($current !== $database) {
                throw new RuntimeException(sprintf('refused: the connection is on %s, not on the measurement database %s', is_string($current) ? $current : 'no database', $database));
            }
        }
        $summary = ['fixups' => []];
        foreach ($profile['fixups'] as $fix) {
            $summary['fixups'][$fix['name']] = (int) $pdo->exec($fix['sql']);
        }

        return $summary;
    };

return $profile;
