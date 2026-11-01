<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

/**
 * Logs a user in for the requests of this process, without a login request: the user is set on the guard. A
 * Sanctum token guard is set too when the app has laravel/sanctum (it is never added).
 *
 * Spec text: `<Model class>:<key>[:<guard>]`, e.g. `App\Models\User:1:web`.
 */
final class ActingAs
{
    /** @return array{string, string, ?string} [model class, key, guard] */
    public static function parse(string $spec): array
    {
        if (preg_match('/^(?<class>[A-Za-z_\\\\][A-Za-z0-9_\\\\]*):(?<key>[^:]+)(?::(?<guard>[A-Za-z0-9_.-]+))?$/D', $spec, $m) !== 1) {
            throw new \InvalidArgumentException('--act-as must look like Model\\Class:key[:guard]');
        }

        return [$m['class'], $m['key'], ($m['guard'] ?? '') === '' ? null : $m['guard']];
    }

    public static function apply(object $app, string $spec): void
    {
        [$class, $key, $guard] = self::parse($spec);
        if (!class_exists($class)) {
            throw new \RuntimeException('the user model does not exist: ' . $class);
        }
        $user = $class::query()->find($key);
        if ($user === null) {
            throw new \RuntimeException(sprintf('no %s with key %s in the measurement database', $class, $key));
        }
        // Guards want a current request; the real one is bound when the kernel handles the request.
        $request = 'Illuminate\\Http\\Request';
        $app->instance('request', $request::create(Requester::BASE_URL));
        $auth = $app['auth'];
        $guard ??= (string) $app['config']->get('auth.defaults.guard');
        $auth->guard($guard)->setUser($user);
        if (class_exists('Laravel\\Sanctum\\Sanctum')) {
            \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
        }
        $auth->shouldUse($guard);
    }
}
