<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Boot;

/**
 * What a started app needs from the tool, whichever app it is, because of a package it has: nothing here is knowledge
 * about one app. Each step is tied to a class a package brings, and runs only when the app has it.
 *
 *  - Passport (`laravel/passport`): its token guard reads a public key the first time it is asked, and a fresh checkout
 *    has none (the keys are made by an install command and kept in files or in the environment), so every API route
 *    would stop with "Invalid key supplied" before it is logged in. A throw-away key pair, made here and kept in the
 *    configuration of this process only, is all the guard needs: no token is ever checked. A key the app already has
 *    is left alone. Nothing is written to a file.
 *  - Vite (`Illuminate\Foundation\Vite`): the pages ask for the built front-end files, which a checkout does not have,
 *    and fail with "Vite manifest not found" once their handler has run. The asset tags carry no data, so the helper
 *    is replaced by an empty one (`EmptyVite`).
 *
 * The steps touch the configuration and the container only: not the database, not the events, not the fakes.
 */
final class AppSupport
{
    private const PASSPORT = 'Laravel\\Passport\\Passport';

    private const VITE = 'Illuminate\\Foundation\\Vite';

    private const FACADE = 'Illuminate\\Support\\Facades\\Facade';

    /**
     * @param object                          $app         Illuminate\Foundation\Application
     * @param (callable(string): bool)|null   $classExists answers "does the app have this class" (default: class_exists)
     *
     * @return list<string> the steps that were taken (evidence for the record)
     */
    public static function apply(object $app, ?callable $classExists = null): array
    {
        $has = $classExists ?? static fn (string $class): bool => class_exists($class);
        $done = [];

        if ($has(self::PASSPORT) && self::passportKey($app)) {
            $done[] = 'passport_key';
        }
        if ($has(self::VITE)) {
            $app->instance(self::VITE, new EmptyVite());
            if (class_exists(self::FACADE)) {
                (self::FACADE)::clearResolvedInstance(self::VITE);
            }
            $done[] = 'vite_assets';
        }

        return $done;
    }

    private static function passportKey(object $app): bool
    {
        $config = $app['config'];
        if ((string) $config->get('passport.public_key', '') !== '') {
            return false;
        }
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($pair === false) {
            return false;
        }
        $details = openssl_pkey_get_details($pair);
        $private = '';
        if (!is_array($details) || !is_string($details['key'] ?? null) || !openssl_pkey_export($pair, $private)) {
            return false;
        }
        $config->set('passport.public_key', $details['key']);
        $config->set('passport.private_key', $private);

        return true;
    }
}
