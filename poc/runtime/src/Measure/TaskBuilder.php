<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Route\ArgumentResolver;
use PeakSim\Runtime\Route\BodyBuilder;
use PeakSim\Runtime\Route\ProfileRoutes;
use PeakSim\Runtime\Route\RequestCredentials;
use PeakSim\Runtime\Route\RouteSpec;
use PeakSim\Runtime\Route\UserProvider;
use PeakSim\Runtime\Run\ActionDefinition;
use PeakSim\Runtime\Run\Classification;

/** Turns a route of the catalogue into a task: fills the parameters, picks the login, builds the body. */
final class TaskBuilder
{
    /** @param array<string, mixed> $profile the app's profile (empty when none was asked for): who logs in */
    public function __construct(
        private readonly ArgumentResolver $arguments,
        private readonly BodyBuilder $bodies,
        private readonly AppFacts $facts,
        private readonly array $profile = [],
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

        // A GET route the profile has carry credentials in its query is prepared (not explained away): the credentials are
        // what it needs to be reached through the app's own authentication. The secret stays a token in the task.
        $query = [];
        $credentials = null;
        $querying = ProfileRoutes::querying($this->profile, $spec->uri, $spec->middleware, $spec->action, $spec->method);
        if ($querying !== null) {
            $as = $querying['as'] ?? ($this->profile['login_as'] ?? 'user');
            try {
                $query = RequestCredentials::template($querying['query'], (array) ($this->profile[$as] ?? []));
            } catch (\InvalidArgumentException $e) {
                return self::failed($spec, 'unfilled_param', 'profile:' . $querying['label'] . ': ' . $e->getMessage());
            }
            $credentials = sprintf(
                'profile:%s: reached with the profile\'s credentials (%s) in the query, through the route\'s own authentication and not a session login',
                $querying['label'],
                implode(', ', array_keys($query)),
            );
        }

        // A route the profile says cannot be prepared (and why) is recorded with that reason, before anything is asked of the app.
        $explained = $querying !== null ? null : ProfileRoutes::explaining($this->profile, $spec->uri, $spec->middleware, $spec->action);
        if ($explained !== null) {
            return self::failed($spec, $explained['reason'], 'profile:' . $explained['label'] . ($explained['why'] === '' ? '' : ': ' . $explained['why']));
        }

        $resolution = $this->arguments->resolve($spec);
        if (!$resolution->ok()) {
            // An app profile may say why a parameter cannot be prepared; the first such reason is the route's class.
            $class = 'unfilled_param';
            foreach ($resolution->unfilled as $u) {
                if (isset($u['class'])) {
                    $class = $u['class'];
                    break;
                }
            }

            return self::failed($spec, $class, implode('; ', array_map(
                static fn (array $u): string => $u['param'] . ': ' . $u['reason'],
                $resolution->unfilled,
            )));
        }
        $uri = $resolution->fill($spec->uri);
        if ($uri === null) {
            return self::failed($spec, 'unfilled_param', 'the uri could not be filled');
        }

        $profile = $this->profile === [] ? null : $this->profile;
        $plan = UserProvider::plan(UserProvider::requirement($spec->middleware, $profile), $profile);
        $actAs = null;
        if ($plan['login']) {
            $actAs = $this->facts->loginUser($plan['guard'], $plan['login_id']);
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

        return new RouteTask($spec, $uri, null, null, $actAs, $body, false, $csrf, [], $resolution->guessed, $query, $credentials);
    }

    private static function failed(RouteSpec $spec, string $class, string $detail): RouteTask
    {
        return new RouteTask($spec, null, $class, $detail);
    }
}
