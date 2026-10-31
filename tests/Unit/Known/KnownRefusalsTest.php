<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Known;

use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\UnknownT;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The mouths the allow-list refuses on purpose (design 1.10): each one calls back into app code or into the
 * database, so a route that uses one is unknown. Not one of them may come out as a plain value.
 */
final class KnownRefusalsTest extends KnownCase
{
    /** @return array<string, array{0: string}> */
    public static function refused(): array
    {
        return [
            // k-gate-gap
            'Gate::authorize' => ["Gate::authorize('list-orders')"],
            'Gate::allows' => ["Gate::allows('list-orders')"],
            'Gate::forUser' => ['Gate::forUser($user)'],
            'a gate contract' => ["\$gate->check('list-orders')"],
            // k-validator-gap
            'Request::validate' => ["\$request->validate(['customer_id' => 'exists:customers,id'])"],
            'Request::validateWithBag' => ["\$request->validateWithBag('post', [])"],
            'Validator::make' => ['Validator::make([], [])'],
            'validator()' => ['validator([], [])'],
            'a validation factory' => ['$factory->make([], [])'],
            // k-dispatch-gap
            'event()' => ['event(new OrderViewed())'],
            'dispatch()' => ['dispatch($job)'],
            'dispatch_sync()' => ['dispatch_sync($job)'],
            'broadcast()' => ['broadcast($event)'],
            'Event::dispatch' => ['Event::dispatch($event)'],
            'Bus::dispatch' => ['Bus::dispatch($job)'],
            'Queue::push' => ['Queue::push($job)'],
            'an events dispatcher' => ['$dispatcher->dispatch($event)'],
            // the signed-in user
            'Auth::user' => ['Auth::user()'],
            'auth()' => ['auth()'],
            'Request::user' => ['$request->user()'],
            // mail and notifications
            'Mail::to' => ["Mail::to('a@example.com')"],
            'Mail::send' => ["Mail::send('view', [], fn () => 1)"],
            'Notification::send' => ['Notification::send($users, $note)'],
            // outside the process
            'Http::get' => ["Http::get('https://example.com')"],
            // raw SQL and the container
            'DB::raw' => ["DB::raw('count(*)')"],
            'app()' => ['app()'],
            'app with a dynamic argument' => ['app($abstract)'],
            'app with a class' => ["app('Foo')"],
            'resolve()' => ["resolve('Foo')"],
            'App::make' => ["App::make('Foo')"],
            'a container make' => ["\$container->make('Foo')"],
        ];
    }

    #[DataProvider('refused')]
    public function testARefusedMouthIsAGapWithItsReasonAndNeverAPlainValue(string $php): void
    {
        $w = $this->known([
            'request' => new ObjectT(self::REQUEST),
            'gate' => new ObjectT('Illuminate\\Contracts\\Auth\\Access\\Gate'),
            'factory' => new ObjectT('Illuminate\\Contracts\\Validation\\Factory'),
            'dispatcher' => new ObjectT('Illuminate\\Contracts\\Events\\Dispatcher'),
            'container' => new ObjectT('Illuminate\\Contracts\\Container\\Container'),
        ]);

        $t = $w->run($php);

        $this->assertInstanceOf(UnknownT::class, $t);
        $this->assertSame(['VENDOR_CALL_NOT_MODELLED'], $this->gapCodes($w));
        $this->assertStringContainsString('refused', $this->gapMessages($w)[0]);
        $this->assertSame([], $w->unhandled, 'the allow-list answers the refused mouths itself, with the reason');
        $this->assertClean($w);
    }

    public function testTheGapOfARefusedMouthTouchesBothAxes(): void
    {
        $w = $this->known();

        $w->run("Gate::authorize('list-orders')");

        $this->assertSame([\PeakSim\Model\Axis::Query, \PeakSim\Model\Axis::Memory], $w->fake->gaps()[0]->affects);
    }
}
