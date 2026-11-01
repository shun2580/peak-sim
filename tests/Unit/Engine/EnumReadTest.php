<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;
use PeakSim\Model\Summary;

/**
 * D45: reading `->name` / `->value` is not a gap when the receiver is statically an enum of the app (a case written as
 * `Foo::BAR`, or a declared enum type). Every other property read on a value stays a gap (D21, FIX3).
 */
final class EnumReadTest extends EngineCase
{
    private const STATUS = <<<'PHP'
    <?php

    namespace App\Enums;

    use App\Models\Order;

    enum Status: string
    {
        case Active = 'active';
        case Gone = 'gone';

        const ALIAS = self::Active;
        const LIMIT = 10;

        public function code(): string
        {
            return self::Active->value . self::Gone->name;
        }

        public function orders(): int
        {
            Order::first();

            return 1;
        }
    }
    PHP;

    private const FLAG = <<<'PHP'
    <?php

    namespace App\Enums;

    enum Flag
    {
        case On;
        case Off;
    }
    PHP;

    private const BOX = <<<'PHP'
    <?php

    namespace App\Support;

    class Box
    {
        const VALUE = 5;
        public $value;
    }
    PHP;

    /** @return array<string, string> */
    private function enums(): array
    {
        return [
            'app/Enums/Status.php' => self::STATUS,
            'app/Enums/Flag.php' => self::FLAG,
            'app/Support/Box.php' => self::BOX,
        ];
    }

    /** A controller whose __invoke has the given signature and body. */
    private function action(string $signature, string $body, string $members = ''): Summary
    {
        $code = <<<PHP
        <?php

        namespace App\Http\Controllers;

        use App\Enums\Flag;
        use App\Enums\Status;
        use App\Models\Order;
        use App\Support\Box;

        class T
        {
            {$members}

            public function __invoke({$signature})
            {
        {$body}
            }
        }
        PHP;

        return $this->analyze([self::CONTROLLER . 'T.php' => $code] + $this->enums(), 'App\Http\Controllers\T');
    }

    // ----- the receiver is a case written as Foo::BAR -----------------------------------------------------

    public function testTheValueAndNameOfACaseWrittenOutAreNotGaps(): void
    {
        $summary = $this->action('', <<<'PHP'
                $a = Status::Active->value;
                $b = Status::Gone->name;
                $c = Flag::On->name;
        PHP);

        self::assertSame([], $this->gapCodes($summary));
        self::assertSame([], $summary->events);
        $this->assertNoUnknownWithoutGap();
    }

    public function testSelfInsideTheEnumIsTheEnum(): void
    {
        $summary = $this->action('Status $status', '        return $status->code();');

        self::assertSame([], $this->gapCodes($summary), 'self::Active->value and self::Gone->name inside the enum');
        $this->assertNoUnknownWithoutGap();
    }

    public function testTheValueOfACaseStaysAPlainValueSoReadingFurtherIsAGap(): void
    {
        $summary = $this->action('', '        $x = Status::Active->value->deeper;');

        self::assertSame([GapCode::UNRESOLVED_CALL], $this->gapCodes($summary));
    }

    // ----- the receiver has a declared enum type -----------------------------------------------------------

    public function testTheValueAndNameOfAParameterTypedAsAnEnumAreNotGaps(): void
    {
        $summary = $this->action('Status $status, Flag $flag', <<<'PHP'
                $a = $status->value;
                $b = $status->name;
                $c = $flag->name;
        PHP);

        self::assertSame([], $this->gapCodes($summary));
        $this->assertNoUnknownWithoutGap();
    }

    public function testANullableEnumParameterIsStillAnEnum(): void
    {
        $summary = $this->action('?Status $status', '        $a = $status?->value; $b = $status->name;');

        self::assertSame([], $this->gapCodes($summary));
    }

    public function testAPropertyAndAReturnTypeDeclaredAsAnEnumAreEnums(): void
    {
        $summary = $this->action('', <<<'PHP'
                $a = $this->pick()->value;
                $b = $this->held->name;
        PHP, <<<'PHP'
            private Status $held;

            private function pick(): Status
            {
                return Status::Active;
            }
        PHP);

        self::assertSame([], $this->gapCodes($summary));
        $this->assertNoUnknownWithoutGap();
    }

    // ----- everything else stays a gap (D21) ---------------------------------------------------------------

    public function testAnUntypedReceiverIsStillAGap(): void
    {
        $summary = $this->action('$thing', '        $a = $thing->value; $b = $thing->name;');

        self::assertNotSame([], $this->gapCodes($summary), 'nothing says $thing is an enum');
    }

    public function testAValueFromAnUnknownCallIsStillAGap(): void
    {
        $summary = $this->action('', '        $a = mystery_function(); $b = $a->value;');

        self::assertContains(GapCode::UNRESOLVED_CALL, $this->gapCodes($summary));
    }

    public function testAColumnOrScalarReceiverIsStillAGap(): void
    {
        $summary = $this->action('', <<<'PHP'
                $order = Order::first();
                $a = $order->status;
                $b = $a->value;
                $n = 3;
                $c = $n->name;
        PHP);

        self::assertContains(GapCode::UNRESOLVED_CALL, $this->gapCodes($summary));
        self::assertGreaterThanOrEqual(2, count(array_filter($this->gapCodes($summary), static fn (GapCode $g): bool => $g === GapCode::UNRESOLVED_CALL)));
    }

    public function testAnObjectOfANonEnumClassIsStillAGap(): void
    {
        $summary = $this->action('Box $box', '        $a = $box->value;');

        self::assertSame([GapCode::UNRESOLVED_CALL], $this->gapCodes($summary), 'a declared class that is not an enum');
    }

    public function testAConstantThatIsNotACaseIsStillAGap(): void
    {
        $summary = $this->action('', <<<'PHP'
                $a = Status::ALIAS->value;
                $b = Status::LIMIT->value;
                $c = Box::VALUE->value;
        PHP);

        self::assertCount(3, $this->gapCodes($summary), 'an alias of a case, a scalar constant, a constant of a plain class: none is a declared case');
    }

    public function testAPureEnumHasNoValue(): void
    {
        $summary = $this->action('Flag $flag', '        $a = $flag->value; $b = Flag::On->value;');

        self::assertCount(2, $this->gapCodes($summary), 'only backed cases have a value');
    }

    public function testOtherPropertiesOfAnEnumAreStillGaps(): void
    {
        $summary = $this->action('Status $status', '        $a = $status->label;');

        self::assertSame([GapCode::UNRESOLVED_CALL], $this->gapCodes($summary), 'only name and value are the exception');
    }

    public function testAnUnknownCaseOfAnEnumIsStillAGap(): void
    {
        $summary = $this->action('', '        $a = Status::Missing->value;');

        self::assertCount(1, $this->gapCodes($summary), 'there is no case Missing');
    }

    // ----- the exception does not reach calls into the enum (D21) -----------------------------------------

    public function testAMethodOfAnEnumTypedReceiverIsStillFollowed(): void
    {
        $summary = $this->action('Status $status', '        $n = $status->orders();');

        self::assertCount(1, $this->events($summary, EventKind::Select), 'the query inside the enum method is counted, not skipped');
        self::assertSame([], $this->gapCodes($summary));
    }

    public function testAMethodCalledOnAWrittenOutCaseIsStillAGap(): void
    {
        $summary = $this->action('', '        $n = Status::Active->orders();');

        self::assertNotSame([], $this->gapCodes($summary), 'the exception covers the two property names only, not calls on a case');
    }
}
