<?php

declare(strict_types=1);

// Test profile for the wiring of `after_generation` (a hook that runs right after the generator): it records what it
// saw, adds one customer of its own and logs in as that customer. The verification app has no logins; this only shows
// that the hook is called, with the right connection and name, after the generator and before anything is measured.
return [
    'user' => ['id' => 1, 'role' => 'user'],
    'after_generation' => static function (PDO $pdo, string $database): array {
        $seen = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $current = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $pdo->exec("INSERT INTO customers (name, email) VALUES ('Hook Customer', 'hook@measure.invalid')");

        return ['customers_seen' => $seen, 'connected_to_the_named_database' => $current === $database];
    },
];
