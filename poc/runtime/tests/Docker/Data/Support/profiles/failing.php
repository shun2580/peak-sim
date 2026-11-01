<?php

declare(strict_types=1);

// Test profile whose hook fails: the build must stop with the hook's message, not carry on.
return [
    'after_generation' => static function (PDO $pdo, string $database): array {
        throw new RuntimeException('the generated users have no row 2');
    },
];
