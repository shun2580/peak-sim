<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

/**
 * Whether the controller a route points at has the operation the route names.
 *
 * `apiResource` registers all seven operations (index, store, show, update, destroy ...) even when the controller
 * defines only some of them. A request to one that is missing ends in a 500 that looks like an app failure, so the
 * route is told apart by reading the class, and recorded as `action_not_defined` without being hit.
 *
 * Reflection only; nothing is run. A public method counts. `__call` does not: Laravel's base controller answers any
 * missing method with a BadMethodCallException, which is the 500 this check exists to keep out of the counts.
 */
final class ActionDefinition
{
    /**
     * @param ?string $action `Class@method`, or a bare class name for an invokable controller (the string the static
     *                        analysis prints, and the booted route's action name)
     *
     * @return ?string why the operation is not defined, or null when it is defined or cannot be judged. A class that
     *                 cannot be loaded is "cannot be judged": not seeing a class does not show that the operation is missing.
     */
    public static function undefined(?string $action): ?string
    {
        if ($action === null || $action === '' || $action === 'Closure') {
            return null;
        }

        [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
        if ($class === '' || $method === '' || !class_exists($class)) {
            return null;
        }

        $reflection = new \ReflectionClass($class);
        if ($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic()) {
            return null;
        }

        return $class . ' has no public method ' . $method;
    }
}
