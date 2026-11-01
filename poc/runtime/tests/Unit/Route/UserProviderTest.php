<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Route;

use PeakSim\Runtime\Route\UserProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserProviderTest extends TestCase
{
    /** @return array<string, array{list<string>, bool, ?string, bool, ?string}> */
    public static function middleware(): array
    {
        return [
            'none' => [[], false, null, false, null],
            'web only' => [['web'], false, null, false, null],
            'auth' => [['web', 'auth'], true, null, false, null],
            'auth with a guard' => [['api', 'auth:sanctum'], true, 'sanctum', false, null],
            'auth with two guards takes the first' => [['auth:api,web'], true, 'api', false, null],
            'auth.basic' => [['auth.basic'], true, null, false, null],
            'verified needs a login and a verified user' => [['auth', 'verified'], true, null, true, null],
            'can' => [['auth', 'can:update,post'], true, null, false, 'update'],
            'can without auth still needs a login' => [['can:manage-users'], true, null, false, 'manage-users'],
            'the class form of authenticate' => [['Illuminate\\Auth\\Middleware\\Authenticate:sanctum'], true, 'sanctum', false, null],
            'throttle and signed are not about login' => [['throttle:60,1', 'signed'], false, null, false, null],
        ];
    }

    /**
     * @param list<string> $middleware
     */
    #[DataProvider('middleware')]
    public function test_the_requirement_is_read_from_the_middleware(array $middleware, bool $required, ?string $guard, bool $verified, ?string $ability): void
    {
        $r = UserProvider::requirement($middleware);

        $this->assertSame($required, $r->required);
        $this->assertSame($guard, $r->guard);
        $this->assertSame($verified, $r->verified);
        $this->assertSame($ability, $r->ability);
    }

    public function test_a_guest_only_route_must_not_be_logged_in(): void
    {
        $r = UserProvider::requirement(['guest']);

        $this->assertFalse($r->required);
        $this->assertTrue($r->guestOnly);
    }

    public function test_a_forbidden_response_gets_one_retry_with_the_gate_bypassed(): void
    {
        $this->assertTrue(UserProvider::shouldRetryWithGateBypass(403, false));
        $this->assertFalse(UserProvider::shouldRetryWithGateBypass(403, true));
        $this->assertFalse(UserProvider::shouldRetryWithGateBypass(200, false));
        $this->assertFalse(UserProvider::shouldRetryWithGateBypass(401, false));
        $this->assertFalse(UserProvider::shouldRetryWithGateBypass(404, false));
    }

    public function test_the_plan_for_an_open_route_is_not_to_log_in(): void
    {
        $plan = UserProvider::plan(UserProvider::requirement(['web']), null);

        $this->assertFalse($plan['login']);
    }

    public function test_the_plan_uses_the_generator_user_when_there_is_no_profile(): void
    {
        $plan = UserProvider::plan(UserProvider::requirement(['auth:sanctum']), null);

        $this->assertTrue($plan['login']);
        $this->assertSame('sanctum', $plan['guard']);
        $this->assertSame('generator', $plan['source']);
        $this->assertFalse($plan['admin']);
    }

    public function test_the_plan_follows_the_profile_when_it_has_a_user(): void
    {
        $plan = UserProvider::plan(UserProvider::requirement(['auth']), ['user' => ['role' => 'member'], 'login_as' => 'admin']);

        $this->assertSame('profile', $plan['source']);
        $this->assertTrue($plan['admin']);
    }

    public function test_the_first_stage_is_the_ordinary_user_when_the_profile_does_not_name_an_admin(): void
    {
        $plan = UserProvider::plan(UserProvider::requirement(['auth']), ['user' => ['role' => 'member']]);

        $this->assertFalse($plan['admin']);
    }
}
