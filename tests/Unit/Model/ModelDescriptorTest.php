<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Model;

use PeakSim\Source\ModelDescriptor;
use PHPUnit\Framework\TestCase;

/** The descriptor's trailing `$unresolved` argument: defaults to none, and keeps what it is given. */
final class ModelDescriptorTest extends TestCase
{
    public function testUnresolvedDefaultsToNoneSoExistingCallsStillWork(): void
    {
        $descriptor = new ModelDescriptor('App\Models\Order', 'orders', 'mysql', [], ModelDescriptor::SOURCE_EXPORT, [], [], []);

        self::assertSame([], $descriptor->unresolved);
    }

    public function testUnresolvedKeepsTheNamesItIsGiven(): void
    {
        $descriptor = new ModelDescriptor(
            'App\Models\DynamicOrder',
            'orders',
            'mysql',
            [],
            ModelDescriptor::SOURCE_EXPORT,
            [],
            [],
            [],
            ['with', 'appends'],
        );

        self::assertSame(['with', 'appends'], $descriptor->unresolved);
    }

    public function testObserversDefaultsToNoneSoExistingCallsStillWork(): void
    {
        $descriptor = new ModelDescriptor('App\Models\Order', 'orders', 'mysql', [], ModelDescriptor::SOURCE_EXPORT, [], [], []);

        self::assertSame([], $descriptor->observers);
    }

    public function testObserversKeepsTheNamesItIsGiven(): void
    {
        $descriptor = new ModelDescriptor(
            'App\Models\ObservedOrder',
            'orders',
            'mysql',
            [],
            ModelDescriptor::SOURCE_EXPORT,
            [],
            [],
            [],
            [],
            ['App\Observers\OrderObserver'],
        );

        self::assertSame(['App\Observers\OrderObserver'], $descriptor->observers);
    }
}
