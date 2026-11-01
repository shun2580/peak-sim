<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/** AppFacts read from the booted app: the user model of a guard, and the rules of a FormRequest. */
final class LaravelAppFacts implements AppFacts
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    private const FORM_REQUEST = 'Illuminate\\Foundation\\Http\\FormRequest';

    /** @param object $app Illuminate\Foundation\Application */
    public function __construct(private readonly object $app)
    {
    }

    public function loginUser(?string $guard, string|int|null $id = null): ?string
    {
        $config = $this->app['config'];
        $name = $guard ?? (string) $config->get('auth.defaults.guard');
        // A guard a package adds at run time (sanctum) is not in config('auth.guards'): use the default provider.
        $provider = $config->get('auth.guards.' . $name . '.provider') ?? $config->get('auth.guards.' . $config->get('auth.defaults.guard') . '.provider');
        $model = $config->get('auth.providers.' . $provider . '.model');
        if (!is_string($model) || !is_subclass_of($model, self::MODEL)) {
            return null;
        }
        $instance = new $model();
        $key = $instance->getKeyName();
        $query = $model::query();
        if ($id !== null) {
            $query->where($key, '=', $id);
        }
        $found = $query->orderBy($key)->value($key);
        if ($found === null) {
            return null;
        }

        return $model . ':' . $found . ($guard === null ? '' : ':' . $guard);
    }

    public function bodyRules(?string $action): ?array
    {
        if ($action === null || $action === 'Closure') {
            return null;
        }
        [$class, $method] = array_pad(explode('@', $action, 2), 2, '__invoke');
        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }
        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !is_subclass_of($type->getName(), self::FORM_REQUEST)) {
                continue;
            }
            try {
                // Not resolved through the container: resolving a FormRequest validates it, and there is no input yet.
                $rules = (new ($type->getName())())->rules();
            } catch (\Throwable) {
                return null;
            }

            return is_array($rules) ? self::plain($rules) : null;
        }

        return null;
    }

    /**
     * Keeps the rules that are strings; a rule object (Rule::in(...), a closure) is dropped, never run.
     *
     * @param array<string|int, mixed> $rules
     *
     * @return array<string, mixed>
     */
    private static function plain(array $rules): array
    {
        $out = [];
        foreach ($rules as $field => $rule) {
            $parts = is_array($rule) ? array_values(array_filter($rule, 'is_string')) : (is_string($rule) ? [$rule] : []);
            $out[(string) $field] = $parts === [] ? '' : (count($parts) === 1 ? $parts[0] : $parts);
        }

        return $out;
    }
}
