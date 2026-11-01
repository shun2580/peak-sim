<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

/**
 * The test-only leaky stack (an ordinary bridge network). bin/preflight must refuse it with exit code 11.
 */
final class LeakyStackTest extends StackTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::bringUp('-leaky-t', 'measure.leaky.compose.yaml');
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownStack();
    }

    public function testPreflightRefusesANetworkThatIsNotSealed(): void
    {
        $result = self::bin('preflight');
        self::evidence('egress', 'preflight-leaky-refused.txt', self::transcript('bin/preflight (leaky compose)', $result));

        $this->assertSame(11, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString('internal=false', $result[1]);
        $this->assertStringContainsString('PREFLIGHT FAIL', $result[2]);
    }

    public function testTheInsideProbeAloneAlsoRefusesBecauseOutsideIsReachable(): void
    {
        $result = self::inPhp('/peak-sim/poc/runtime/bin/inner/preflight.php');
        self::evidence('egress', 'inside-probe-leaky-refused.txt', self::transcript('bin/inner/preflight.php (leaky compose, inside the container)', $result));

        $this->assertSame(11, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString('FAIL negative default-route', $result[1]);
        $this->assertStringContainsString('PREFLIGHT FAIL', $result[2]);
    }

    public function testTheCanaryLeaksOnTheLeakyStack(): void
    {
        $result = self::bin('canary-egress');
        self::evidence('egress', 'canary-leaky-control.txt', self::transcript('bin/canary-egress (leaky compose, positive control)', $result));

        $this->assertNotSame(0, $result[0], 'on the leaky stack at least one attempt must get out, or the canary proves nothing');
        $this->assertStringContainsString('LEAK ', $result[1]);
    }
}
