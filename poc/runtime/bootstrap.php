<?php

declare(strict_types=1);

/*
 * Minimal autoloader for the runtime proof of concept. It is deliberately not part of the root composer
 * autoload (and not part of the phar): the PoC must never leak into the official tool.
 *
 *   PeakSim\Runtime\        -> poc/runtime/src/
 *   PeakSim\Runtime\Tests\  -> poc/runtime/tests/
 */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'PeakSim\\Runtime\\Tests\\' => __DIR__ . '/tests/',
        'PeakSim\\Runtime\\' => __DIR__ . '/src/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
