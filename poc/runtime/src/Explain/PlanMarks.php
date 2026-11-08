<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

/**
 * The marks of one statement type, from the plans taken for it.
 *
 *  - `plan_unstable`: the plan (signature: tables in order, each with its access type and key) is not the same in every
 *    statistics state of the target scale. Needs two states that were taken.
 *  - `plan_changes_at_scale`: the plan at the small scale (default state) is not the plan at the target scale (default
 *    state). Needs both.
 *  - `explain_unavailable`: the reason, when the plan could not be taken at the target scale in the default state (null
 *    otherwise). A state that failed while the default worked is named in `unavailable_states` and is not a reason here.
 *
 * Plans that were not taken are never compared: a missing plan is not "the same" and not "different".
 */
final class PlanMarks
{
    public const STATE_DEFAULT = 'default';

    /**
     * @param array<string, array<string, mixed>|null> $target states of the target scale: state name => PlanSummary::summarize() or null
     * @param array<string, string|null>                $targetWhy state name => the reason it was not taken (for the states whose summary is null)
     * @param array<string, mixed>|null                 $small summary at the small scale (default state), or null
     *
     * @return array{plan_unstable: bool, plan_changes_at_scale: bool, explain_unavailable: ?string, unavailable_states: list<string>, unstable_states: list<string>}
     */
    public static function of(array $target, array $targetWhy, ?array $small): array
    {
        $signatures = [];
        $unavailable = [];
        foreach ($target as $state => $summary) {
            if ($summary === null) {
                $unavailable[] = (string) $state;
                continue;
            }
            $signatures[(string) $state] = (string) $summary['signature'];
        }
        $unstable = count($signatures) >= 2 && count(array_unique($signatures)) > 1;

        $default = $target[self::STATE_DEFAULT] ?? null;
        $changes = $default !== null && $small !== null && $small['signature'] !== $default['signature'];
        $why = $default === null ? ($targetWhy[self::STATE_DEFAULT] ?? 'not_taken') : null;

        return [
            'plan_unstable' => $unstable,
            'plan_changes_at_scale' => $changes,
            'explain_unavailable' => $why,
            'unavailable_states' => $unavailable,
            'unstable_states' => $unstable ? array_keys($signatures) : [],
        ];
    }
}
