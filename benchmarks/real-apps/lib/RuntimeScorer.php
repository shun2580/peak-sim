<?php

declare(strict_types=1);

namespace PeakSim\Benchmarks\RealApps;

/**
 * Scores a runtime measurement (the routes.jsonl and attempts.jsonl a measurement run writes) against the fixed answers
 * of the query axis, in the classes A to E:
 *
 *   A  the code says O(N) or heavier, and the measurement found growth (measured_ON / measured_ONM)
 *   B  the code says O(N) or heavier, and the measurement did not reach the handler or learned nothing
 *   C  the code says O(N) or heavier, and the measurement said O(1) (a false O(1))
 *   D  the code says O(1) or "conditional", and the measurement said O(1) or showed growth
 *   D_candidate  the same code answers, but the measurement said nothing (not reached, no information)
 *   E  the code answer is not firm enough (confidence below medium, or not decided)
 *
 * A verdict only counts when the request reached the handler. "Reached" is the stop reason 'reached': an answer that
 * stopped at authentication, an exception, a missing argument or a client error did not reach it.
 * A / (A + B + C) is the share of the answered O(N)-or-heavier routes the measurement got right; conditional routes
 * are not in the denominator.
 */
final class RuntimeScorer
{
    public const CLASSES = ['A', 'B', 'C', 'D', 'D_candidate', 'E'];

    /** @param array<string, mixed> $truthAxis */
    public static function codeGroup(array $truthAxis): string
    {
        $growth = $truthAxis['growth'] ?? null;
        if (($truthAxis['decided'] ?? true) === false || !in_array($truthAxis['confidence'] ?? null, ['high', 'medium'], true)) {
            return 'E';
        }
        if ($growth === 'conditional') {
            return 'COND';
        }
        $degree = Truth::degree($growth);

        return match (true) {
            $degree === null => 'E',
            $degree === 0 => ($truthAxis['at_least'] ?? false) ? 'E' : 'O1',
            default => 'ON',
        };
    }

    /**
     * Why the run stopped for a route: 'reached' or the cause. $unreachedBytes lets a caller name a response size that is
     * known to be a prompt and not the handler (the earlier run of the first target app needed this for a two-factor
     * prompt); it is off by default.
     *
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $attempts
     */
    public static function stopReason(array $record, array $attempts, ?int $unreachedBytes = null): string
    {
        $class = (string) ($record['class'] ?? '');
        $detail = (string) ($record['reason_detail'] ?? '');
        if ($class === 'unauthenticated') {
            return 'auth';
        }
        if (str_contains($detail, 'Invalid key supplied')) {
            return 'passport_key';
        }
        if ($class === 'unfilled_param') {
            return 'argument';
        }
        if ($class === 'exception') {
            return 'exception: ' . substr($detail, 0, 80);
        }
        $last = [];
        if ($attempts !== []) {
            $max = max(array_map(static fn (array $a): int => (int) $a['scale'], $attempts));
            $last = array_values(array_filter($attempts, static fn (array $a): bool => (int) $a['scale'] === $max));
        }
        if ($unreachedBytes !== null && $last !== []) {
            $all = true;
            foreach ($last as $a) {
                $all = $all && (int) $a['status'] === 200 && (int) $a['bytes'] === $unreachedBytes;
            }
            if ($all) {
                return 'mfa_prompt';
            }
        }
        if ($class === 'client_error') {
            return 'client_error ' . ($last !== [] ? (string) $last[0]['status'] : '?');
        }
        if ($class === 'not_found') {
            return 'not_found';
        }

        return $class === 'ok' ? 'reached' : $class;
    }

    /**
     * True when the response size is identical at every scale on both axes (only the points that answered normally
     * count, and at least two are needed): then the rows the plan generated may never have reached the response.
     *
     * @param list<array<string, mixed>> $attempts
     */
    public static function bytesFlat(array $attempts): bool
    {
        $first = [];
        foreach ($attempts as $a) {
            $key = ($a['axis'] ?? '') . ':' . $a['scale'];
            if (!isset($first[$key]) || (int) $a['attempt'] < (int) $first[$key]['attempt']) {
                $first[$key] = $a;
            }
        }
        $sizes = [];
        $points = 0;
        foreach ($first as $a) {
            if (($a['class'] ?? '') === 'ok' && (int) ($a['status'] ?? 500) < 400 && ($a['bytes'] ?? null) !== null) {
                $points++;
                $sizes[(int) $a['bytes']] = true;
            }
        }

        return $points >= 2 && count($sizes) === 1;
    }

    /**
     * @param array<string, mixed> $record     one line of routes.jsonl
     * @param list<array<string, mixed>> $attempts  that route's lines of attempts.jsonl
     *
     * @return array{class: string, detail: string, reached: bool, bytes_flat: bool, verdict: string, measured: string}
     */
    public static function classify(string $group, array $record, array $attempts, ?int $unreachedBytes = null): array
    {
        $verdict = (string) ($record['verdict'] ?? 'not_measured');
        $stop = self::stopReason($record, $attempts, $unreachedBytes);
        $reached = $stop === 'reached';
        $flat = $reached && self::bytesFlat($attempts);

        $measured = match ($verdict) {
            'measured_ON', 'measured_ONM' => 'ON',
            'measured_O1' => 'O1',
            default => 'NONE',
        };
        $spurious = $measured !== 'NONE' && !$reached;

        $why = $stop;
        if ($stop === 'reached') {
            $detail = (string) ($record['reason'] ?? '');
            $why = $verdict === 'no_info'
                ? (str_contains($detail, 'suspect_empty') ? 'suspect_empty' : 'no_info(' . $detail . ')')
                : 'reached(' . $verdict . ')';
        } elseif ($spurious) {
            $why .= ' (the verdict ' . $verdict . ' is from a response that did not reach the handler)';
        }

        $class = match (true) {
            $group === 'E' => 'E',
            $group === 'ON' && $measured === 'ON' && !$spurious => 'A',
            $group === 'ON' && $measured === 'O1' && !$spurious => 'C',
            $group === 'ON' => 'B',
            ($measured === 'O1' || $measured === 'ON') && !$spurious => 'D',
            default => 'D_candidate',
        };

        return [
            'class' => $class, 'detail' => $why, 'reached' => $reached, 'bytes_flat' => $flat,
            'verdict' => $verdict, 'measured' => $spurious ? 'SPURIOUS_' . $measured : $measured,
        ];
    }

    /**
     * @param array<string, mixed> $truth
     * @param list<array<string, mixed>> $records   routes.jsonl lines of one run
     * @param list<array<string, mixed>> $attempts  attempts.jsonl lines of the same run
     * @param array{apps?: list<string>, sets?: list<string>} $filter
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function score(array $truth, string $app, array $records, array $attempts, array $filter = [], ?int $unreachedBytes = null): array
    {
        $byRoute = [];
        foreach ($records as $r) {
            $byRoute[($r['method'] ?? '') . ' ' . ($r['route'] ?? '')] = $r;
        }
        $attemptsOf = [];
        foreach ($attempts as $a) {
            $attemptsOf[($a['method'] ?? '') . ' ' . ($a['route'] ?? '')][] = $a;
        }

        $rows = [];
        foreach ($truth['routes'] as $tr) {
            if ($tr['app'] !== $app || !isset($tr['axes']['query'])) {
                continue;
            }
            if (isset($filter['sets']) && $filter['sets'] !== [] && array_intersect($filter['sets'], $tr['sets'] ?? []) === []) {
                continue;
            }
            $group = self::codeGroup($tr['axes']['query']);
            $key = $tr['method'] . ' ' . $tr['uri'];
            if (!isset($byRoute[$key])) {
                $rows[] = ['id' => $tr['id'], 'sets' => $tr['sets'] ?? [], 'group' => $group, 'class' => 'not_run', 'detail' => 'the route is not in the run', 'reached' => false, 'bytes_flat' => false, 'verdict' => '-', 'measured' => '-'];
                continue;
            }
            $rows[] = ['id' => $tr['id'], 'sets' => $tr['sets'] ?? [], 'group' => $group] + self::classify($group, $byRoute[$key], $attemptsOf[$key] ?? [], $unreachedBytes);
        }

        return ['rows' => $rows, 'summary' => self::summarise($rows)];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    public static function summarise(array $rows): array
    {
        $counts = array_fill_keys(self::CLASSES, 0);
        $counts['not_run'] = 0;
        $reasons = [];
        $falseO1 = [];
        $reached = 0;
        foreach ($rows as $row) {
            $counts[$row['class']]++;
            $reached += $row['reached'] ? 1 : 0;
            if ($row['class'] === 'B') {
                $reasons[$row['detail']] = ($reasons[$row['detail']] ?? 0) + 1;
            }
            if ($row['class'] === 'C') {
                $falseO1[] = $row['id'];
            }
        }
        ksort($reasons);
        $denominator = $counts['A'] + $counts['B'] + $counts['C'];

        return [
            'routes' => count($rows),
            'reached' => $reached,
            'counts' => $counts,
            'denominator' => $denominator,
            'a_over_abc' => $denominator === 0 ? null : $counts['A'] / $denominator,
            'b_reasons' => $reasons,
            'c_routes' => $falseO1,
        ];
    }
}
