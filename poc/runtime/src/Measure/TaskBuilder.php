<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Route\ArgumentResolver;
use PeakSim\Runtime\Route\BodyBuilder;
use PeakSim\Runtime\Route\RouteSpec;
use PeakSim\Runtime\Route\UserProvider;
use PeakSim\Runtime\Run\ActionDefinition;
use PeakSim\Runtime\Run\Classification;

/** Turns a route of the catalogue into a task: fills the parameters, picks the login, builds the body. */
final class TaskBuilder
{
    public function __construct(
        private readonly ArgumentResolver $arguments,
        private readonly BodyBuilder $bodies,
        private readonly AppFacts $facts,
    ) {
    }

    public function build(RouteSpec $spec): RouteTask
    {
        if (!$spec->inRuntime) {
            return self::failed($spec, 'not_in_runtime', 'the static analysis lists this route and the booted app does not have it');
        }

        // Before the parameters: a route to an operation the controller does not have is not hit, and its parameters
        // (which only the missing method would have typed) are not what stops it.
        $undefined = ActionDefinition::undefined($spec->action);
        if ($undefined !== null) {
            return self::failed($spec, Classification::ACTION_NOT_DEFINED, $undefined);
        }

        $resolution = $this->arguments->resolve($spec);
        if (!$resolution->ok()) {
            return self::failed($spec, 'unfilled_param', implode('; ', array_map(
                static fn (array $u): string => $u['param'] . ': ' . $u['reason'],
                $resolution->unfilled,
            )));
        }
        $uri = $resolution->fill($spec->uri);
        if ($uri === null) {
            return self::failed($spec, 'unfilled_param', 'the uri could not be filled');
        }

        $plan = UserProvider::plan(UserProvider::requirement($spec->middleware), null);
        $actAs = null;
        if ($plan['login']) {
            $actAs = $this->facts->loginUser($plan['guard']);
            if ($actAs === null) {
                return self::failed($spec, 'no_login_user', 'the route needs a login and the measurement database has no row of the user model (guard: ' . ($plan['guard'] ?? 'default') . ')');
            }
        }

        $body = null;
        $csrf = false;
        if ($spec->method !== 'GET') {
            $csrf = in_array('web', $spec->middleware, true);
            $rules = $this->facts->bodyRules($spec->action);
            if ($rules === null) {
                $body = [];
            } else {
                $built = $this->bodies->build($rules);
                if ($built->files() !== []) {
                    return self::failed($spec, 'file_dependency', 'the body needs an uploaded file: ' . implode(', ', $built->files()));
                }
                if ($built->unfilled !== []) {
                    return self::failed($spec, 'unfilled_param', 'body: ' . implode('; ', array_map(
                        static fn (array $u): string => $u['field'] . ': ' . $u['reason'],
                        $built->unfilled,
                    )));
                }
                $body = $built->fields;
            }
        }

        return new RouteTask($spec, $uri, null, null, $actAs, $body, false, $csrf);
    }

    private static function failed(RouteSpec $spec, string $class, string $detail): RouteTask
    {
        return new RouteTask($spec, null, $class, $detail);
    }
}
