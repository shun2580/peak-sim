<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Route;

use PeakSim\Runtime\Measure\Profile;
use PeakSim\Runtime\Route\UserProvider;
use PHPUnit\Framework\TestCase;

/**
 * A profile with no `auth_gates` changes nothing. `tests/vectors/auth_gate_baseline.json` holds, byte for byte, what the
 * login plan (the requirement read from a route's middleware, and the plan made from it) was before the `auth_gates` key
 * existed: for no profile and for every profile that exists and has no such key, over a spread of middleware lists. The
 * output now must be the same file.
 */
final class AuthGateBaselineTest extends TestCase
{
    /** @return list<list<string>> */
    private static function middlewareLists(): array
    {
        return [
            [], ['web'], ['web', 'auth'], ['api', 'auth:sanctum'], ['auth:api,web'], ['auth.basic'], ['auth.session'], ['auth', 'verified'],
            ['auth', 'can:update,post'], ['can:manage-users'], ['Illuminate\\Auth\\Middleware\\Authenticate:sanctum'],
            ['throttle:60,1', 'signed'], ['guest'], ['web', 'guest'], ['App\\Http\\Middleware\\RedirectIfAuthenticated'],
            ['Illuminate\\Auth\\Middleware\\EnsureEmailIsVerified'], ['Illuminate\\Auth\\Middleware\\Authorize:view,post'],
            ['web', 'CheckShopper'], ['web', 'Vendor\\Pkg\\Http\\Middleware\\Doorman'], ['web', 'admin'], ['web', 'auth:admin', 'can:edit'],
            ['api', 'auth:sanctum', 'verified', 'throttle:api'], ['web', 'locale', 'currency'], ['custom.gate'], ['web', 'Vendor\\Pkg\\CheckAccess:admin'],
        ];
    }

    public function test_without_auth_gates_the_login_plan_is_byte_for_byte_what_it_was(): void
    {
        $out = [];
        foreach ([null, 'bookstack', 'firefly', 'koel', 'koel_admin', 'koel_userbound', 'fixtures'] as $name) {
            $profile = $name === null ? null : Profile::load($name);
            $this->assertArrayNotHasKey('auth_gates', $profile ?? [], 'the baseline is for profiles without the key');
            foreach (self::middlewareLists() as $middleware) {
                $r = UserProvider::requirement($middleware, $profile);
                $out[($name ?? '(none)') . ' | ' . implode(',', $middleware)] = [
                    'requirement' => [$r->required, $r->guard, $r->verified, $r->ability, $r->guestOnly],
                    'plan' => UserProvider::plan($r, $profile === [] ? null : $profile),
                ];
            }
        }

        $now = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";

        $this->assertSame(file_get_contents(__DIR__ . '/../../vectors/auth_gate_baseline.json'), $now);
    }

    public function test_the_requirement_of_a_profile_without_the_key_equals_the_one_made_with_no_profile_at_all(): void
    {
        $profile = Profile::load('koel');
        foreach (self::middlewareLists() as $middleware) {
            $this->assertEquals(UserProvider::requirement($middleware), UserProvider::requirement($middleware, $profile), implode(',', $middleware));
        }
    }
}
