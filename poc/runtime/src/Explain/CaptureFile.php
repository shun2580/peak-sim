<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

/**
 * Reads `capture.json` (the form is described in `CAPTURE_FORMAT.md`): for every route that was captured, the statement
 * types with their representative statement and its bound values. Nothing here changes a statement.
 *
 * A representative whose bindings were redacted (`[redacted]`, a secret column) is still explained, with the redacted text as
 * the value, and is marked `bindings_redacted`: the plan for it may differ from the plan for the real value.
 */
final class CaptureFile
{
    public const REDACTED = '[redacted]';

    /**
     * @param array<string, mixed> $capture the decoded file
     *
     * @return array{routes: list<string>, statements: list<array{route: string, id: string, kind: string, pattern: string, sql: ?string, bindings: list<mixed>, bindings_redacted: bool, representative_scale: ?int, not_explained: ?string}>, scales: list<int>}
     */
    public static function read(array $capture): array
    {
        $routes = [];
        $statements = [];
        foreach ((array) ($capture['routes'] ?? []) as $route) {
            if (!is_array($route) || !isset($route['route'])) {
                continue;
            }
            $key = (string) $route['route'];
            $routes[] = $key;
            foreach ((array) ($route['statement_types'] ?? []) as $type) {
                if (!is_array($type)) {
                    continue;
                }
                $rep = is_array($type['representative'] ?? null) ? $type['representative'] : null;
                $bindings = $rep === null ? [] : array_values((array) ($rep['bindings'] ?? []));
                $sql = $rep === null ? null : (string) ($rep['sql'] ?? '');
                $redacted = in_array(self::REDACTED, $bindings, true) || ($sql !== null && str_contains($sql, self::REDACTED));
                $statements[] = [
                    'route' => $key,
                    'id' => (string) ($type['id'] ?? ''),
                    'kind' => (string) ($type['kind'] ?? ''),
                    'pattern' => (string) ($type['pattern'] ?? ''),
                    'sql' => $sql === '' ? null : $sql,
                    'bindings' => $bindings,
                    'bindings_redacted' => $redacted,
                    'representative_scale' => $rep !== null && isset($rep['scale']) ? (int) $rep['scale'] : null,
                    'not_explained' => $rep === null || $sql === '' ? 'no_representative_in_the_capture' : null,
                ];
            }
        }

        return [
            'routes' => array_values(array_unique($routes)),
            'statements' => $statements,
            'scales' => array_values(array_map('intval', (array) ($capture['scales'] ?? []))),
        ];
    }
}
