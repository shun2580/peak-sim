<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Route;

use PeakSim\Runtime\Route\ProfileRoutes;
use PHPUnit\Framework\TestCase;

/** Which route an `unfillable` entry of an app profile is about: by uri prefix, middleware name, action prefix. */
final class ProfileRoutesTest extends TestCase
{
    private const PROFILE = ['unfillable' => [
        'subsonic' => ['routes' => ['uri_prefix' => ['rest/', 'api/subsonic']], 'reason' => 'unauthenticated', 'why' => 'a token made from an encrypted key'],
        'signed_links' => ['routes' => ['middleware' => ['signed']], 'reason' => 'signature_required', 'why' => 'a signed URL'],
        'licence' => ['routes' => ['action_prefix' => ['App\\Http\\Controllers\\License']], 'reason' => 'external_call_blocked', 'why' => 'calls an outside service'],
        'both_keys' => ['routes' => ['uri_prefix' => ['admin/'], 'middleware' => ['auth']], 'reason' => 'forbidden', 'why' => 'x'],
        'an_argument' => ['params' => ['key'], 'reason' => 'external_call_blocked', 'why' => 'x'],
        'an_argument_in_a_route' => ['routes' => ['uri_prefix' => ['files/']], 'params' => ['id'], 'reason' => 'file_dependency', 'why' => 'x'],
        'only_a_note' => ['reason' => 'unfilled_param', 'why' => 'nothing to match'],
    ]];

    /** @param list<string> $middleware */
    private static function explain(string $uri, array $middleware = [], ?string $action = null): ?array
    {
        return ProfileRoutes::explaining(self::PROFILE, $uri, $middleware, $action);
    }

    public function test_a_uri_prefix_matches_the_uri_without_its_leading_slash(): void
    {
        $this->assertSame(['label' => 'subsonic', 'reason' => 'unauthenticated', 'why' => 'a token made from an encrypted key'], self::explain('/rest/ping'));
        $this->assertSame('subsonic', self::explain('rest/getSong.view')['label']);
        $this->assertSame('subsonic', self::explain('/api/subsonic/x')['label'], 'any one of the prefixes');
    }

    public function test_a_prefix_is_a_prefix_not_a_substring(): void
    {
        $this->assertNull(self::explain('/api/rest/ping'));
        $this->assertNull(self::explain('/restaurants'));
    }

    public function test_a_middleware_matches_by_its_name_without_the_arguments(): void
    {
        $this->assertSame('signed_links', self::explain('/confirm', ['web', 'signed'])['label']);
        $this->assertSame('signed_links', self::explain('/confirm', ['signed:relative'])['label']);
        $this->assertNull(self::explain('/confirm', ['web', 'designed']));
    }

    public function test_an_action_prefix_matches_the_action(): void
    {
        $this->assertSame('licence', self::explain('/api/licences', [], 'App\\Http\\Controllers\\LicenseController@show')['label']);
        $this->assertNull(self::explain('/api/licences', [], 'App\\Http\\Controllers\\SongController@show'));
        $this->assertNull(self::explain('/api/licences', [], null));
    }

    public function test_every_key_an_entry_names_must_match(): void
    {
        $this->assertSame('both_keys', self::explain('/admin/users', ['auth'])['label']);
        $this->assertNull(self::explain('/admin/users', ['web']));
        $this->assertNull(self::explain('/public/users', ['auth']));
    }

    public function test_an_entry_about_an_argument_or_with_no_route_key_never_explains_a_whole_route(): void
    {
        $this->assertNull(self::explain('/dropbox/abc'));
        $this->assertNull(self::explain('/files/1'));
        $this->assertNull(self::explain('/anything'));
    }

    public function test_the_first_entry_that_matches_wins(): void
    {
        $this->assertSame('subsonic', self::explain('/rest/x', ['signed'])['label']);
    }

    public function test_without_a_profile_nothing_explains_anything(): void
    {
        $this->assertNull(ProfileRoutes::explaining([], '/rest/ping', [], null));
    }

    public function test_an_entry_with_routes_and_params_is_about_those_arguments_in_those_routes_only(): void
    {
        $entry = self::PROFILE['unfillable']['an_argument_in_a_route'];

        $this->assertTrue(ProfileRoutes::covers($entry, '/files/9', [], null));
        $this->assertFalse(ProfileRoutes::covers($entry, '/other/9', [], null));
        $this->assertTrue(ProfileRoutes::covers(self::PROFILE['unfillable']['an_argument'], '/anything', [], null), 'no routes key: every route');
    }
}
