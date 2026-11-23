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

    public function test_the_plan_logs_in_as_the_profiles_admin_by_its_id_when_login_as_says_so(): void
    {
        $profile = ['user' => ['id' => 1, 'role' => 'user'], 'admin' => ['id' => 2, 'role' => 'admin'], 'login_as' => 'admin'];

        $plan = UserProvider::plan(UserProvider::requirement(['auth']), $profile);

        $this->assertTrue($plan['admin']);
        $this->assertSame(2, $plan['login_id']);
    }

    public function test_the_plan_logs_in_as_the_profiles_user_by_its_id_otherwise(): void
    {
        $profile = ['user' => ['id' => 1, 'role' => 'user'], 'admin' => ['id' => 2, 'role' => 'admin']];

        foreach ([$profile, $profile + ['login_as' => 'user']] as $p) {
            $plan = UserProvider::plan(UserProvider::requirement(['auth']), $p);

            $this->assertFalse($plan['admin']);
            $this->assertSame(1, $plan['login_id']);
        }
    }

    public function test_without_an_id_in_the_profile_the_login_is_the_first_row_as_before(): void
    {
        $this->assertNull(UserProvider::plan(UserProvider::requirement(['auth']), null)['login_id']);
        $this->assertNull(UserProvider::plan(UserProvider::requirement(['auth']), ['user' => ['role' => 'member']])['login_id']);
        $this->assertNull(UserProvider::plan(UserProvider::requirement(['web']), ['user' => ['id' => 1]])['login_id'], 'an open route logs nobody in');
    }

    // ---- auth_gates: a login gate the app wrote for itself -----------------------------------------------------

    /** @return array<string, array{list<string>, bool}> */
    public static function gateMatches(): array
    {
        return [
            'the name itself' => [['web', 'gate'], true],
            'a class name that ends with it' => [['web', 'Vendor\\Http\\Middleware\\Gate'], true],
            'a class name with an argument' => [['Vendor\\Http\\Middleware\\Gate:strict'], true],
            'without regard to case' => [['GATE'], true],
            'a name that only ends with the same letters is not the gate' => [['web', 'BackGate', 'Vendor\\BackGate'], false],
            'a name that only starts with it is not the gate' => [['Gatekeeper'], false],
            'no middleware' => [[], false],
        ];
    }

    /** @param list<string> $middleware */
    #[DataProvider('gateMatches')]
    public function test_a_profiles_gate_is_found_by_the_name_or_the_end_of_the_class_name(array $middleware, bool $required): void
    {
        $r = UserProvider::requirement($middleware, ['auth_gates' => ['g' => ['middleware' => ['Gate']]]]);

        $this->assertSame($required, $r->required);
        $this->assertNull($r->guard);
        $this->assertNull($r->as);
    }

    public function test_the_same_middleware_without_the_profile_is_not_a_login(): void
    {
        $this->assertFalse(UserProvider::requirement(['web', 'Gate'])->required);
        $this->assertFalse(UserProvider::requirement(['web', 'Gate'], ['user' => ['id' => 1]])->required);
    }

    public function test_the_gate_says_which_guard_and_which_login(): void
    {
        $profile = ['auth_gates' => [
            'shop' => ['middleware' => 'ShopLogin', 'guard' => 'shopper', 'as' => 'user'],
            'office' => ['middleware' => ['OfficeLogin', 'Other\\Staff'], 'guard' => 'staff', 'as' => 'admin'],
        ]];

        $shop = UserProvider::requirement(['web', 'App\\Http\\Middleware\\ShopLogin'], $profile);
        $this->assertTrue($shop->required);
        $this->assertSame('shopper', $shop->guard);
        $this->assertSame('user', $shop->as);

        $staff = UserProvider::requirement(['web', 'Vendor\\Other\\Staff'], $profile);
        $this->assertSame('staff', $staff->guard);
        $this->assertSame('admin', $staff->as);
    }

    public function test_a_gate_adds_to_the_judgement_and_never_changes_what_the_names_already_say(): void
    {
        $profile = ['auth_gates' => ['g' => ['middleware' => ['Gate'], 'guard' => 'shopper']]];

        $named = UserProvider::requirement(['auth:api', 'Gate'], $profile);
        $this->assertSame('api', $named->guard, 'the route\'s own auth:api keeps the guard');

        $verified = UserProvider::requirement(['Gate', 'verified', 'can:view'], $profile);
        $this->assertTrue($verified->verified);
        $this->assertSame('view', $verified->ability);
        $this->assertSame('shopper', $verified->guard);

        $this->assertFalse(UserProvider::requirement(['guest', 'Gate'], $profile)->guestOnly, 'a login is required, so the route is not guest-only');
    }

    public function test_the_plan_enters_as_the_login_the_gate_names(): void
    {
        $profile = [
            'user' => ['id' => 1], 'admin' => ['id' => 2],
            'auth_gates' => ['g' => ['middleware' => ['Gate'], 'guard' => 'staff', 'as' => 'admin'], 'h' => ['middleware' => ['Door'], 'as' => 'user']],
        ];

        $admin = UserProvider::plan(UserProvider::requirement(['Gate'], $profile), $profile);
        $this->assertTrue($admin['login']);
        $this->assertTrue($admin['admin']);
        $this->assertSame(2, $admin['login_id']);
        $this->assertSame('staff', $admin['guard']);

        $user = UserProvider::plan(UserProvider::requirement(['Door'], $profile + ['login_as' => 'admin']), $profile + ['login_as' => 'admin']);
        $this->assertFalse($user['admin'], 'as=user wins over the profile\'s login_as');
        $this->assertSame(1, $user['login_id']);

        $default = UserProvider::plan(UserProvider::requirement(['Gate'], ['user' => ['id' => 1], 'auth_gates' => ['g' => ['middleware' => ['Gate']]]]), ['user' => ['id' => 1], 'auth_gates' => ['g' => ['middleware' => ['Gate']]]]);
        $this->assertFalse($default['admin'], 'no as: the profile\'s login_as (user) decides');
        $this->assertSame(1, $default['login_id']);
    }
}
