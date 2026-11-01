<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data;

use PeakSim\Runtime\Data\FactoryRunner;
use PeakSim\Runtime\Data\Reason;
use PeakSim\Runtime\Tests\Unit\Data\Stubs\ModelWithFactory;
use PeakSim\Runtime\Tests\Unit\Data\Stubs\ModelWithoutFactory;
use PeakSim\Runtime\Tests\Unit\Data\Stubs\ModelWithBrokenFactory;
use PHPUnit\Framework\TestCase;

final class FactoryRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        ModelWithFactory::$created = [];
    }

    public function testItCreatesTheRowsThroughTheModelFactory(): void
    {
        $result = (new FactoryRunner(fn () => true))->run(ModelWithFactory::class, 3, ['name' => 'x']);

        $this->assertTrue($result->ok());
        $this->assertSame(3, $result->created);
        $this->assertSame([['count' => 3, 'attributes' => ['name' => 'x']]], ModelWithFactory::$created);
    }

    public function testAModelWithoutAFactoryIsReported(): void
    {
        $result = (new FactoryRunner(fn () => true))->run(ModelWithoutFactory::class, 2);

        $this->assertFalse($result->ok());
        $this->assertSame(0, $result->created);
        $this->assertSame(Reason::NO_FACTORY, $result->reason);
    }

    public function testAMissingModelClassIsReported(): void
    {
        $result = (new FactoryRunner(fn () => true))->run('No\\Such\\Model', 2);

        $this->assertSame(Reason::NO_FACTORY, $result->reason);
    }

    public function testWithoutFakerNothingIsTried(): void
    {
        $result = (new FactoryRunner(fn () => false))->run(ModelWithFactory::class, 2);

        $this->assertSame(Reason::FAKER_MISSING, $result->reason);
        $this->assertSame([], ModelWithFactory::$created);
    }

    public function testAFailingFactoryIsReportedWithAShortMessage(): void
    {
        $result = (new FactoryRunner(fn () => true))->run(ModelWithBrokenFactory::class, 2);

        $this->assertSame(Reason::FACTORY_ERROR, $result->reason);
        $this->assertSame(200, strlen($result->detail));
        $this->assertSame(0, $result->created);
    }

    public function testACountBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FactoryRunner(fn () => true))->run(ModelWithFactory::class, 0);
    }

    public function testTheDefaultCheckLooksForFakerItself(): void
    {
        $runner = new FactoryRunner();

        $this->assertSame(class_exists(\Faker\Generator::class), $runner->fakerAvailable());
    }
}
