<?php

declare(strict_types=1);

use PeakSim\Runtime\Guard\DbGuard;

// Knowledge about this app (Firefly III, a Laravel personal-finance manager) that its schema and its routes cannot
// give. A run uses it only when asked for (`--profile=firefly`), and a result reached with it is reported as such,
// apart from one reached without.
//
// This is knowledge, not a rule of judgement: nothing here decides whether a count is flat or grows. The app is never
// changed and its seeder is never run. Each entry says why it is there, and where in the app that is.
//
// What the tool now does for every app, and so is not here any more: a throw-away Passport key and an empty Vite
// helper (Boot\AppSupport), a NULL for an optional secret column such as `users.mfa_secret` and UTC for the `*_tz`
// columns (Data\ValueFactory), a route argument that is named for its table, such as `transactionGroup`
// (Route\ArgumentResolver). What is left is vocabulary, an app's own binding and reference rows.
$profile = [
    // Route argument => where its value comes from. The tool's own rules cannot read these (one is the app's own
    // binding, the other a word that the name of a table would give a number for), so the profile says them outright.
    'arguments' => [
        'tagOrId' => ['table' => 'tags', 'column' => 'id', 'why' => 'the argument takes a tag by its number or its name (app/Models/Tag.php routeBinder); the number is used'],
        'transactionType' => ['value' => 'withdrawal', 'why' => 'the convert pages take the type to convert to as a word (routes/web.php transactions/convert/{transactionType}/{transactionGroup})'],
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
