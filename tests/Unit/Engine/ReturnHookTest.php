<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Engine\ActionReturnHook;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Model\EventKind;
use PeakSim\Model\GapCode;
use PhpParser\Node;

final class ReturnHookTest extends EngineCase
{
    public function testTheHookSeesTheActionsReturnValueOnceAndItsFindingsJoinTheActionsSummary(): void
    {
        $hook = new class () implements ActionReturnHook {
            /** @var list<Type> */
            public array $seen = [];

            public function onActionReturn(Type $returned, Node $action, EvalContext $ctx): void
            {
                $this->seen[] = $returned;
                $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $action, 'the hook found something it cannot follow');
            }
        };

        $summary = $this->analyze([
            'app/Act.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass Act\n{\n    public function __construct(private readonly Helper \$h) {}\n    public function run()\n    {\n        return \$this->h->orders();\n    }\n}\n",
            'app/Helper.php' => "<?php\nnamespace App;\nuse App\\Models\\Order;\nclass Helper\n{\n    public function orders()\n    {\n        return Order::get();\n    }\n}\n",
        ], 'App\Act', 'run', [], null, $hook);

        self::assertCount(1, $hook->seen, 'only the action (level 1), not the helper it called');
        self::assertInstanceOf(ListT::class, $hook->seen[0]);
        self::assertSame([GapCode::RESOURCE_NOT_ANALYZED], $this->gapCodes($summary));
        self::assertCount(1, $this->events($summary, EventKind::Select));
    }
}
