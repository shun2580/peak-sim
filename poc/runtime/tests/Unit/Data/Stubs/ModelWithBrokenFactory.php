<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data\Stubs;

final class ModelWithBrokenFactory
{
    public static function factory(): object
    {
        throw new \RuntimeException(str_repeat('boom ', 100));
    }
}
