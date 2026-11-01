<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PeakSim\Runtime\Run\Requester;
use PeakSim\Runtime\Run\RequestSpec;
use PHPUnit\Framework\TestCase;

/** What a request carries as its query string or its form, decided before the app's kernel sees it. */
final class RequesterInputTest extends TestCase
{
    public function test_a_get_carries_the_specs_query_as_its_query_string(): void
    {
        $spec = new RequestSpec('GET', '/rest/ping', query: ['u' => 'a@b.invalid', 'apiKey' => 'k']);

        $this->assertSame(['u' => 'a@b.invalid', 'apiKey' => 'k'], Requester::parametersFor($spec));
    }

    public function test_a_get_without_a_query_carries_nothing_as_before(): void
    {
        $this->assertSame([], Requester::parametersFor(new RequestSpec('GET', '/x')));
        $this->assertSame([], Requester::parametersFor(new RequestSpec('GET', '/x', body: ['a' => 1], json: false)), 'a body is not a query');
    }

    public function test_a_form_post_carries_its_body_and_never_the_query(): void
    {
        $spec = new RequestSpec('POST', '/x', body: ['a' => '1'], json: false, query: ['u' => 'x']);

        $this->assertSame(['a' => '1'], Requester::parametersFor($spec));
    }

    public function test_a_json_post_carries_no_parameters(): void
    {
        $this->assertSame([], Requester::parametersFor(new RequestSpec('POST', '/x', body: ['a' => 1], query: ['u' => 'x'])));
    }

    public function test_the_spec_has_no_query_unless_one_is_given(): void
    {
        $this->assertSame([], (new RequestSpec('GET', '/x'))->query);
    }
}
