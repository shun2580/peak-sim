<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Known;

use PeakSim\Model\Growth;
use PeakSim\Model\RouteResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The allow-list behind the real engine and the real Judge: the CallSites the engine builds for functions, static
 * calls and vendor-typed parameters, and what a route comes out as. These are the k-* mini-apps in miniature.
 */
final class KnownEngineTest extends TestCase
{
    use JudgesRoutes;

    public function testKnownFunctionsLeaveTheRouteConstant(): void
    {
        $r = $this->judge(<<<'PHP'
                $order = Order::first();

                return response()->json([
                    'at' => now(),
                    'rounded' => round(count($order) / 2),
                    'listed' => in_array('new', ['new', 'paid'], true),
                ]);
        PHP);

        $this->assertSame(Growth::Constant, $r->query->growth);
        $this->assertSame(Growth::Constant, $r->memory->growth);
        $this->assertSame([], $this->gapCodes($r));
        $this->assertSame([], $r->assumptions);
        $this->assertGreaterThanOrEqual(1, $r->eventsFound);
        $this->assertSame([], $this->engine?->violations());
    }

    public function testTheQueryStringAndAbortUnlessAreKnown(): void
    {
        $r = $this->judge(<<<'PHP'
                abort_unless($request->query('via') === 'update', 404);

                return response()->json(Order::first());
        PHP, 'use Illuminate\Http\Request;', 'Request $request');

        $this->assertSame([], $this->gapCodes($r));
        $this->assertSame(Growth::Constant, $r->query->growth);
        $this->assertSame([], $this->engine?->violations());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function unknownRoutes(): array
    {
        return [
            'k-gate-gap' => [
                "Gate::authorize('list-orders');\n\n        return response()->json(Order::first());",
                'use Illuminate\Support\Facades\Gate;',
                '',
            ],
            'k-validator-gap' => [
                "\$request->validate(['customer_id' => 'exists:customers,id']);\n\n        return response()->json(Order::first());",
                'use Illuminate\Http\Request;',
                'Request $request',
            ],
            'k-dispatch-gap' => [
                "event(new \\App\\Events\\OrderViewed());\n\n        return response()->json(Order::first());",
                '',
                '',
            ],
            'k-unknown-facade-gap' => [
                "Storage::disk('s3')->put('report.txt', 'x');\n\n        return response()->json(Order::first());",
                'use Illuminate\Support\Facades\Storage;',
                '',
            ],
        ];
    }

    #[DataProvider('unknownRoutes')]
    public function testARefusedOrUnlistedCallMakesBothAxesUnknown(string $body, string $uses, string $params): void
    {
        $r = $this->judge($body, $uses, $params, ['app/Events/OrderViewed.php' => "<?php\n\nnamespace App\\Events;\n\nclass OrderViewed\n{\n}\n"]);

        $this->assertSame(Growth::Unknown, $r->query->growth);
        $this->assertSame(Growth::Unknown, $r->memory->growth);
        $this->assertNull($r->confidence);
        $this->assertContains('VENDOR_CALL_NOT_MODELLED', $this->gapCodes($r));
        $this->assertGreaterThanOrEqual(1, $r->eventsFound);
        $this->assertSame([], $this->engine?->violations());
    }

    public function testAnAccessorClosureIsHandedBackNotRun(): void
    {
        $r = $this->judge(<<<'PHP'
                $accessor = \Illuminate\Database\Eloquent\Casts\Attribute::get(fn () => Order::get());

                return response()->json(['ok' => true]);
        PHP);

        $this->assertSame([], $this->gapCodes($r));
        $this->assertSame(0, $r->eventsFound, 'the closure body is for whoever invokes the accessor, not for Attribute::get()');
    }
}
