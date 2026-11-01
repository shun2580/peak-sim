<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\DatabasePoint;
use PHPUnit\Framework\TestCase;

final class DatabasePointTest extends TestCase
{
    public function test_the_key_names_the_tag_and_the_scale(): void
    {
        $this->assertSame('ord:1000', (new DatabasePoint('ord', 1000, ['orders' => 1000]))->key());
    }

    public function test_a_tag_is_what_a_database_name_may_hold(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DatabasePoint('Bad Tag', 10, []);
    }

    public function test_a_negative_scale_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DatabasePoint('ord', -1, []);
    }
}
