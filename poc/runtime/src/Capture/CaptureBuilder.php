<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Capture;

use PeakSim\Runtime\Judge\CostFit;

/**
 * `capture.json` from the per-statement records (taps) of cold requests at several scales. Pure: no database, no app.
 * The shape is written down in `poc/runtime/CAPTURE_FORMAT.md`.
 *
 * Per route: the statements of every scale are grouped by type (`StatementShape`); a type keeps, per scale, how many
 * times it ran and how many rows the server read for it (the handler counts the tap measured), one representative
 * (the statement of the largest scale that read the most rows, with its bindings), and a formula for how many times
 * it runs, fitted over the scales by `CostFit` (a type that did not run at a scale ran 0 times there).
 */
final class CaptureBuilder
{
    public const VERSION = 1;

    /**
     * @param array<string, mixed>                                 $meta     app, profile, data, scales (the scales asked for)
     * @param list<array<string, mixed>>                           $attempts the lines of attempts.jsonl
     * @param array<string, array<int, array<string, mixed>>>      $taps     route key ("METHOD uri") => scale => tap document
     *
     * @return array<string, mixed>
     */
    public static function build(array $meta, array $attempts, array $taps): array
    {
        /** @var list<int> $scales */
        $scales = array_values(array_map('intval', $meta['scales']));
        sort($scales);
        $representative = $scales === [] ? 0 : max($scales);

        $coldOf = [];
        $routes = [];
        foreach ($attempts as $a) {
            if (strtolower((string) ($a['axis'] ?? 'N')) !== 'n' || (int) ($a['attempt'] ?? 0) !== 2) {
                continue;
            }
            $key = $a['method'] . ' ' . $a['route'];
            $coldOf[$key][(int) $a['scale']] = $a;
            $routes[$key] = ['method' => (string) $a['method'], 'uri' => (string) $a['route']];
        }
        foreach (array_keys($taps) as $key) {
            if (!isset($routes[$key])) {
                [$method, $uri] = explode(' ', $key, 2) + ['', ''];
                $routes[$key] = ['method' => $method, 'uri' => $uri];
            }
        }
        ksort($routes);

        $out = [];
        foreach ($routes as $key => $r) {
            $out[] = self::route($key, $r['method'], $r['uri'], $scales, $representative, $coldOf[$key] ?? [], $taps[$key] ?? []);
        }

        return [
            'capture_version' => self::VERSION,
            'app' => $meta['app'] ?? null,
            'profile' => $meta['profile'] ?? null,
            'data' => $meta['data'] ?? null,
            'scales' => $scales,
            'representative_scale' => $representative,
            'routes' => $out,
        ];
    }

    /**
     * @param list<int>                              $scales
     * @param array<int, array<string, mixed>>       $cold  scale => the cold attempt
     * @param array<int, array<string, mixed>>       $taps  scale => tap document
     *
     * @return array<string, mixed>
     */
    private static function route(string $key, string $method, string $uri, array $scales, int $representative, array $cold, array $taps): array
    {
        $captured = [];
        $why = [];
        foreach ($scales as $scale) {
            if (isset($taps[$scale]) && ($cold[$scale]['class'] ?? 'ok') === 'ok') {
                $captured[] = $scale;

                continue;
            }
            $a = $cold[$scale] ?? null;
            $why[] = sprintf('scale %d: %s', $scale, $a === null ? 'no cold attempt' : (($a['class'] ?? 'unknown') . (isset($a['detail']) && is_string($a['detail']) && $a['detail'] !== '' ? ' (' . $a['detail'] . ')' : '')));
        }

        $totals = [];
        $types = [];
        foreach ($captured as $scale) {
            $window = (array) ($taps[$scale]['window'] ?? []);
            $totals[(string) $scale] = [
                'statements' => $window['statement_events'] ?? null,
                'rows_sum' => $window['rows_sum'] ?? null,
                'cold_rows_examined' => $window['cold_rows_examined'] ?? null,
                'unaccounted' => $window['unaccounted'] ?? null,
                'unread_events' => $window['unread_events'] ?? null,
            ];
            foreach ((array) ($taps[$scale]['statements'] ?? []) as $s) {
                $pattern = StatementShape::pattern((string) $s['sql']);
                $types[$pattern]['kind'] = (string) ($s['type'] ?? '');
                $types[$pattern]['scales'][$scale]['texts'][] = $s;
            }
        }
        ksort($types);

        $list = [];
        foreach ($types as $pattern => $t) {
            $list[] = self::type((string) $pattern, $t['kind'], $t['scales'], $captured, $representative);
        }

        return [
            'route' => $key,
            'method' => $method,
            'uri' => $uri,
            'status' => count($captured) === count($scales) && $scales !== [] ? 'captured' : ($captured === [] ? 'not_captured' : 'partial'),
            'reason' => $why === [] ? null : implode('; ', $why),
            'captured_scales' => $captured,
            'totals' => $totals,
            'statement_types' => $list,
        ];
    }

    /**
     * @param array<int, array{texts: list<array<string, mixed>>}> $byScale
     * @param list<int>                                            $captured
     *
     * @return array<string, mixed>
     */
    private static function type(string $pattern, string $kind, array $byScale, array $captured, int $representative): array
    {
        $perScale = [];
        $series = [];
        foreach ($captured as $scale) {
            $texts = $byScale[$scale]['texts'] ?? [];
            $count = 0;
            $sum = 0;
            $min = null;
            $max = null;
            $unread = 0;
            $readable = true; // false when the counters could not be read for some text of this type
            foreach ($texts as $s) {
                $count += (int) $s['count'];
                $unread += (int) $s['unread'];
                if ($s['rows_sum'] === null) {
                    $readable = false;

                    continue;
                }
                $sum += (int) $s['rows_sum'];
                if ($s['rows_min'] !== null) {
                    $min = $min === null ? (int) $s['rows_min'] : min($min, (int) $s['rows_min']);
                }
                if ($s['rows_max'] !== null) {
                    $max = $max === null ? (int) $s['rows_max'] : max($max, (int) $s['rows_max']);
                }
            }
            $perScale[(string) $scale] = [
                'count' => $count,
                'texts' => count($texts),
                'rows_sum' => $readable ? $sum : null,
                'rows_min' => $readable ? $min : null,
                'rows_max' => $readable ? $max : null,
                'unread' => $unread,
            ];
            $series[$scale] = $count;
        }

        return [
            'id' => 's' . substr(sha1($pattern), 0, 10),
            'kind' => $kind,
            'pattern' => $pattern,
            'representative' => self::representative($byScale, $captured, $representative),
            'per_scale' => $perScale,
            'count_fit' => self::fit($series, $captured),
        ];
    }

    /**
     * The statement of the largest captured scale (the representative scale when it was captured) that read the most
     * rows; on a tie the one that ran most often, then the first.
     *
     * @param array<int, array{texts: list<array<string, mixed>>}> $byScale
     * @param list<int>                                            $captured
     *
     * @return array<string, mixed>|null
     */
    private static function representative(array $byScale, array $captured, int $representative): ?array
    {
        $scale = null;
        foreach (array_reverse($captured) as $candidate) {
            if (($byScale[$candidate]['texts'] ?? []) !== []) {
                $scale = $candidate;

                break;
            }
        }
        if ($scale === null) {
            return null;
        }
        $best = null;
        foreach ($byScale[$scale]['texts'] as $s) {
            $rank = [(int) ($s['rows_sum'] ?? 0), (int) $s['count']];
            if ($best === null || $rank > $best[0]) {
                $best = [$rank, $s];
            }
        }
        $s = $best[1];
        $sql = (string) $s['sql'];
        $bindings = array_values((array) ($s['first_bindings'] ?? []));

        return [
            'scale' => $scale,
            'at_representative_scale' => $scale === $representative,
            'sql' => SecretBindings::redactSql($sql),
            'bindings' => SecretBindings::redactBindings($sql, $bindings),
        ];
    }

    /**
     * How many times a type ran, over the scales (0 where it did not run).
     *
     * @param array<int, int> $series   scale => count
     * @param list<int>       $captured
     *
     * @return array<string, mixed>
     */
    private static function fit(array $series, array $captured): array
    {
        if (count($captured) < 3) {
            return ['fit_status' => CostFit::INSUFFICIENT_SCALES, 'reason' => sprintf('%d scales captured, at least 3 are needed', count($captured))];
        }
        $values = array_map(static fn (int $s): int => $series[$s], $captured);
        $f = CostFit::fit($captured, $values);

        return [
            'fit_status' => CostFit::OK,
            'a' => CostFit::sig($f['a']),
            'b' => CostFit::sig($f['b']),
            'k' => CostFit::sig($f['k']),
            'sse' => CostFit::sig($f['sse']),
            'scales' => $captured,
        ];
    }
}
