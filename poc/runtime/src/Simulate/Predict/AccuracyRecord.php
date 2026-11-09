<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

/**
 * "The accuracy on this app" of the output (simulator-spec.md sections 6.4 and 8.1): the newest saved result of `selfcheck`, or "not
 * checked". Only the figures that selfcheck wrote are carried over; nothing is worked out here.
 */
final class AccuracyRecord
{
    public const FILE = 'selfcheck-result.json';

    public const CHECKED = 'checked';

    public const NOT_CHECKED = 'not_checked';

    /** @return array<string, mixed> */
    public static function notChecked(string $why = 'no selfcheck result was given'): array
    {
        return ['status' => self::NOT_CHECKED, 'text' => 'not checked', 'why' => $why];
    }

    /**
     * @param array<string, mixed> $result a decoded selfcheck result (`kind: selfcheck-result`)
     *
     * @return array<string, mixed>
     */
    public static function fromResult(array $result): array
    {
        if (($result['kind'] ?? null) !== 'selfcheck-result' || !is_array($result['summary'] ?? null)) {
            throw new \InvalidArgumentException('not a selfcheck result (kind must be selfcheck-result and it must have a summary)');
        }
        $s = $result['summary'];

        return [
            'status' => self::CHECKED,
            'app' => $result['app'] ?? null,
            'target_scale' => $result['target_scale'] ?? null,
            'checked_at' => $result['checked_at'] ?? null,
            'verdict' => $s['verdict'] ?? null,
            'routes' => $s['routes'] ?? null,
            'marked_routes' => $s['marked_routes'] ?? null,
            'unmarked_routes' => $s['unmarked_routes'] ?? null,
            'quantities' => $s['quantities'] ?? null,
            'plan_unstable_statements' => $s['plan_unstable_statements'] ?? null,
            'explain_unavailable_statements' => $s['explain_unavailable_statements'] ?? null,
        ];
    }

    /**
     * The saved result at $path, or "not checked" when there is no file. A file that is there but is not a result is an error: it is not
     * passed over as "not checked".
     *
     * @return array<string, mixed>
     */
    public static function load(?string $path): array
    {
        if ($path === null || !is_file($path)) {
            return self::notChecked($path === null ? 'no selfcheck result was given' : 'no selfcheck result at the given path');
        }
        $raw = file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('the selfcheck result is not readable JSON: ' . $path);
        }

        return self::fromResult($data);
    }
}
