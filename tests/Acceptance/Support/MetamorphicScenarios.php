<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance\Support;

use PeakSim\Tests\Support\MiniAppAnalyzer;
use PeakSim\Tests\Support\MiniAppWorkspace;
use PeakSim\Tests\Support\ReportAssert;

/**
 * Metamorphic scenarios (design 3.2-3): change the source of a copy of the fixture app and say how the verdict of
 * one route must move. A tool that only copies fixtures/expected.json gives the same answer before and after and
 * fails the scenarios that expect a change.
 *
 * A scenario is an array:
 *   name      string
 *   kind      'worse' | 'better' | 'unrelated'
 *   route     the route judged before and after
 *   edits     list of ['file' => app-relative path, 'find' => text occurring exactly once, 'replace' => text]
 *             | ['file' => path, 'append_to_class' => code]    (inserted before the closing brace of the class)
 *             | ['file' => path, 'transform' => callable(string): string]
 *             | ['file' => path, 'create' => content]          (a new file)
 *   axes      ['query' => relation, 'memory' => relation]  relation: higher | lower | same | not_lower | not_higher
 *   same      true: the whole verdict (see projection()) must be unchanged
 *   check     optional callable(array $before, array $after): list<string>
 *
 * Worse and better edits also say what they are not allowed to do: a worsening never lowers the axis it names.
 */
final class MetamorphicScenarios
{
    private const OK = 'app/Http/Controllers/Ok/OrderController.php';
    private const NPLUS = 'app/Http/Controllers/Ng/BasicNPlusOneController.php';

    /** @return array<string, array<string, mixed>> keyed by name */
    public static function all(): array
    {
        $list = [
            // ---- worse: the verdict must not get lighter, and here it gets heavier ----
            ['name' => 'worse: drop with() from /ok/eager', 'kind' => 'worse', 'route' => '/ok/eager',
                'edits' => [['file' => self::OK, 'find' => "Order::with('customer')->get()", 'replace' => 'Order::get()']],
                'axes' => ['query' => 'higher', 'memory' => 'same']],
            ['name' => 'worse: paginate() becomes get() in /ok/paginate', 'kind' => 'worse', 'route' => '/ok/paginate',
                'edits' => [['file' => self::OK, 'find' => 'Order::paginate(self::PER_PAGE)', 'replace' => 'Order::get()']],
                'axes' => ['query' => 'same', 'memory' => 'higher']],
            ['name' => 'worse: chunk() becomes get()->chunk() in /ok/chunk', 'kind' => 'worse', 'route' => '/ok/chunk',
                'edits' => [['file' => self::OK,
                    'find' => 'Order::chunk(self::CHUNK_SIZE, function ($orders) use (&$count, &$total): void {',
                    'replace' => 'Order::get()->chunk(self::CHUNK_SIZE)->each(function ($orders) use (&$count, &$total): void {']],
                'axes' => ['memory' => 'higher']],
            ['name' => 'worse: lazy() becomes get() in /ok/lazy', 'kind' => 'worse', 'route' => '/ok/lazy',
                'edits' => [['file' => self::OK, 'find' => 'foreach (Order::lazy(self::CHUNK_SIZE) as $order) {', 'replace' => 'foreach (Order::get() as $order) {']],
                'axes' => ['memory' => 'higher']],
            ['name' => 'worse: withCount() becomes with(items) in /ok/with-count', 'kind' => 'worse', 'route' => '/ok/with-count',
                'edits' => [['file' => self::OK,
                    'find' => "Order::withCount('items')->withSum('items', 'quantity')->get()",
                    'replace' => "Order::with('items')->get()"]],
                'axes' => ['query' => 'same', 'memory' => 'higher']],
            ['name' => 'worse: a binding moves to the lazy implementation (both candidates lazy)', 'kind' => 'worse', 'route' => '/hidden/conditional-binding',
                'edits' => [['file' => 'app/Hidden/Providers/ConditionalBindingServiceProvider.php',
                    'find' => '$app->make(EagerOrderSummaries::class)', 'replace' => '$app->make(LazyOrderSummaries::class)']],
                'axes' => ['query' => 'same', 'memory' => 'same'],
                'check' => static fn (array $b, array $a): array => ($a['worst_case_adopted'] === false && $a['confidence'] === 'high')
                    ? [] : ['with one candidate left the worst-case adoption must disappear and the confidence be high']],

            // ---- better: the verdict must not get heavier, and here it gets lighter ----
            ['name' => 'better: /ng/n-plus-one loses the relation read', 'kind' => 'better', 'route' => '/ng/n-plus-one',
                'edits' => [['file' => self::NPLUS, 'find' => "'customer' => \$order->customer->name", 'replace' => "'customer' => \$order->total"]],
                'axes' => ['query' => 'lower', 'memory' => 'same']],
            ['name' => 'better: /ng/n-plus-one gains with(customer)', 'kind' => 'better', 'route' => '/ng/n-plus-one',
                'edits' => [['file' => self::NPLUS, 'find' => '$orders = Order::get();', 'replace' => "\$orders = Order::with('customer')->get();"]],
                'axes' => ['query' => 'lower', 'memory' => 'same']],
            ['name' => 'better: /ng/nested loses the inner relation read', 'kind' => 'better', 'route' => '/ng/nested',
                'edits' => [['file' => 'app/Http/Controllers/Ng/BasicNestedController.php', 'find' => '$products[] = $item->product->name;', 'replace' => '$products[] = $item->id;']],
                'axes' => ['query' => 'lower', 'memory' => 'same']],
            ['name' => 'better: /ng/unbounded gets a limit', 'kind' => 'better', 'route' => '/ng/unbounded',
                'edits' => [['file' => 'app/Http/Controllers/Ng/BasicUnboundedController.php', 'find' => 'response()->json(Order::all())', 'replace' => 'response()->json(Order::limit(100)->get())']],
                'axes' => ['memory' => 'lower']],
            ['name' => 'better: /ng/save-in-loop loses the save()', 'kind' => 'better', 'route' => '/ng/save-in-loop',
                'edits' => [['file' => 'app/Http/Controllers/Ng/DeepSaveInLoopController.php', 'find' => '$customer->save();', 'replace' => '']],
                'axes' => ['query' => 'lower', 'memory' => 'same']],
            ['name' => 'better: the layered repository eager-loads', 'kind' => 'better', 'route' => '/ng/layered',
                'edits' => [['file' => 'app/Ng/Repositories/EloquentOrderRepository.php', 'find' => 'foreach (Order::all() as $order) {', 'replace' => "foreach (Order::with('customer')->get() as \$order) {"]],
                'axes' => ['query' => 'lower', 'memory' => 'same']],
            ['name' => 'better: the resource reads the relation through whenLoaded()', 'kind' => 'better', 'route' => '/hidden/resource',
                'edits' => [['file' => 'app/Hidden/Resources/OrderResource.php', 'find' => "'customer' => \$this->customer->name,", 'replace' => "'customer' => \$this->whenLoaded('customer'),"]],
                'axes' => ['query' => 'lower', 'memory' => 'same']],
            ['name' => 'better: /hidden/cache is no longer cached', 'kind' => 'better', 'route' => '/hidden/cache',
                'edits' => [
                    ['file' => 'app/Http/Controllers/Hidden/CacheController.php', 'find' => 'Cache::remember(self::KEY, 300, fn () => Order::get()', 'replace' => '(fn () => Order::get()'],
                    ['file' => 'app/Http/Controllers/Hidden/CacheController.php', 'find' => '->all());', 'replace' => '->all())();'],
                ],
                'axes' => ['query' => 'same', 'memory' => 'same'],
                'check' => static fn (array $b, array $a): array => ($b['cache'] !== null && $a['cache'] === null)
                    ? [] : ['the cache view must disappear when Cache::remember is removed']],

            // ---- unrelated: the verdict must not move at all ----
            ['name' => 'unrelated: a comment inside /ng/unbounded', 'kind' => 'unrelated', 'route' => '/ng/unbounded', 'same' => true,
                'edits' => [['file' => 'app/Http/Controllers/Ng/BasicUnboundedController.php', 'find' => 'return response()->json(Order::all());', 'replace' => "// A comment that changes nothing.\n        return response()->json(Order::all());"]]],
            ['name' => 'unrelated: an unused method in /ng/n-plus-one', 'kind' => 'unrelated', 'route' => '/ng/n-plus-one', 'same' => true,
                'edits' => [['file' => self::NPLUS, 'append_to_class' => "\n    private function unused(): int\n    {\n        return Order::count();\n    }\n"]]],
            ['name' => 'unrelated: a local variable renamed in /ng/n-plus-one', 'kind' => 'unrelated', 'route' => '/ng/n-plus-one', 'same' => true,
                'edits' => [['file' => self::NPLUS, 'transform' => static fn (string $s): string => preg_replace('/\$orders\b/', '$allOrders', $s) ?? $s]]],
            ['name' => 'unrelated: the methods of OrderController reordered (eager)', 'kind' => 'unrelated', 'route' => '/ok/eager', 'same' => true,
                'edits' => [['file' => self::OK, 'transform' => self::moveMethodToEnd('paginate')]]],
            ['name' => 'unrelated: the methods of OrderController reordered (paginate)', 'kind' => 'unrelated', 'route' => '/ok/paginate', 'same' => true,
                'edits' => [['file' => self::OK, 'transform' => self::moveMethodToEnd('eager')]]],
            ['name' => 'unrelated: the methods of OrderController reordered (chunk)', 'kind' => 'unrelated', 'route' => '/ok/chunk', 'same' => true,
                'edits' => [['file' => self::OK, 'transform' => self::moveMethodToEnd('eager')]]],
            ['name' => 'unrelated: an unused class with an N+1 (layered)', 'kind' => 'unrelated', 'route' => '/ng/layered', 'same' => true,
                'edits' => [['file' => 'app/Unused/UnusedReport.php', 'create' => self::unusedClass()]]],
            ['name' => 'unrelated: an unused class with an N+1 (paginate)', 'kind' => 'unrelated', 'route' => '/ok/paginate', 'same' => true,
                'edits' => [['file' => 'app/Unused/UnusedReport.php', 'create' => self::unusedClass()]]],
            ['name' => 'unrelated: another cache key', 'kind' => 'unrelated', 'route' => '/hidden/cache', 'same' => true,
                'edits' => [['file' => 'app/Http/Controllers/Hidden/CacheController.php', 'find' => "'hidden.orders'", 'replace' => "'hidden.orders.v2'"]]],
        ];
        $byName = [];
        foreach ($list as $s) {
            $byName[$s['name']] = $s;
        }

        return $byName;
    }

    /**
     * Applies the edits to a copy. Every 'find' must occur exactly once, so a pattern that stops matching fails
     * loudly instead of turning a scenario green by doing nothing.
     *
     * @param array<string, mixed> $scenario
     * @return list<string> the files that were written (app-relative)
     */
    public static function apply(array $scenario, string $root): array
    {
        $written = [];
        foreach ($scenario['edits'] as $edit) {
            $file = $edit['file'];
            if (isset($edit['create'])) {
                MiniAppWorkspace::write($root, $file, $edit['create']);
                $written[] = $file;
                continue;
            }
            $before = MiniAppWorkspace::read($root, $file);
            if (isset($edit['find'])) {
                $count = substr_count($before, $edit['find']);
                if ($count !== 1) {
                    throw new \RuntimeException("{$scenario['name']}: '{$edit['find']}' occurs {$count} times in {$file} (expected exactly once)");
                }
                $after = str_replace($edit['find'], $edit['replace'], $before);
            } elseif (isset($edit['append_to_class'])) {
                $end = strrpos($before, '}');
                $after = substr($before, 0, (int) $end) . $edit['append_to_class'] . substr($before, (int) $end);
            } else {
                $after = $edit['transform']($before);
            }
            if ($after === $before) {
                throw new \RuntimeException("{$scenario['name']}: the edit changed nothing in {$file}");
            }
            MiniAppWorkspace::write($root, $file, $after);
            $written[] = $file;
        }

        return $written;
    }

    /**
     * Judges one scenario with an analyzer.
     *
     * @param array<string, mixed> $scenario
     * @return list<string> what went wrong; empty when the verdict moved as the scenario says
     */
    public static function evaluate(array $scenario, MiniAppAnalyzer $analyzer): array
    {
        $app = MiniAppWorkspace::fixtureApp();
        $export = MiniAppWorkspace::fixtureExport();
        $copy = MiniAppWorkspace::copyApp($app, 'meta');
        try {
            $before = $analyzer->analyzeRoute($app, $export, $scenario['route']);
            self::apply($scenario, $copy);
            $after = $analyzer->analyzeRoute($copy, $export, $scenario['route']);
        } finally {
            MiniAppWorkspace::remove($copy);
        }
        $name = $scenario['name'];
        $problems = [];
        foreach ($scenario['axes'] ?? [] as $axis => $relation) {
            $b = ReportAssert::rank($before[$axis]['growth']);
            $a = ReportAssert::rank($after[$axis]['growth']);
            $ok = match ($relation) {
                'higher' => $a > $b,
                'lower' => $a < $b,
                'same' => $a === $b,
                'not_lower' => $a >= $b,
                'not_higher' => $a <= $b,
            };
            if (!$ok) {
                $problems[] = "{$name}: {$axis} must be {$relation} after the change (before {$before[$axis]['growth']}, after {$after[$axis]['growth']})";
            }
        }
        if (($scenario['same'] ?? false) && self::projection($before) !== self::projection($after)) {
            $problems[] = "{$name}: an unrelated change moved the verdict: "
                . json_encode(self::projection($before)) . ' -> ' . json_encode(self::projection($after));
        }
        foreach (($scenario['check'] ?? static fn (): array => [])($before, $after) as $p) {
            $problems[] = "{$name}: {$p}";
        }

        return $problems;
    }

    /**
     * What a verdict says, without the places it points at (a comment shifts line numbers; that is not a change of verdict).
     *
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public static function projection(array $v): array
    {
        $axis = static fn (array $a): array => [$a['growth'], $a['degree'], $a['confidence'], $a['lower_bound']];
        $codes = static function (array $entries): array {
            $c = array_column($entries, 'code');
            sort($c);

            return $c;
        };

        return [
            'query' => $axis($v['query']),
            'memory' => $axis($v['memory']),
            'n_table' => $v['n_table'],
            'm_table' => $v['m_table'],
            'm_per' => $v['m_per'],
            'confidence' => $v['confidence'],
            'cache' => $v['cache'] === null ? null : [
                $axis($v['cache']['on_miss']['query']), $axis($v['cache']['on_miss']['memory']),
                $axis($v['cache']['on_hit']['query']), $axis($v['cache']['on_hit']['memory']), $v['cache']['restores_all_on_hit'],
            ],
            'worst_case_adopted' => $v['worst_case_adopted'],
            'gaps' => $codes($v['gaps']),
            'assumptions' => $codes($v['assumptions']),
            'events_found' => $v['events_found'],
            'truncated' => $v['truncated'],
        ];
    }

    /** @return callable(string): string moves the named public method of a controller to the end of the class */
    private static function moveMethodToEnd(string $method): callable
    {
        return static function (string $source) use ($method): string {
            if (preg_match('/\n(    public function ' . preg_quote($method, '/') . '\(\): JsonResponse\n    \{\n.*?\n    \}\n)/s', $source, $m) !== 1) {
                return $source;
            }
            $without = str_replace($m[0], "\n", $source);
            $end = strrpos($without, '}');

            return substr($without, 0, (int) $end) . "\n" . $m[1] . substr($without, (int) $end);
        };
    }

    private static function unusedClass(): string
    {
        return <<<'PHP'
<?php

namespace App\Unused;

use App\Models\Order;

class UnusedReport
{
    public function build(): array
    {
        $rows = [];
        foreach (Order::all() as $order) {
            $rows[] = $order->customer->name;
        }

        return $rows;
    }
}
PHP;
    }
}
