<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

/**
 * What the copies of the statistics show about one state, written into the record so it can be read without trusting
 * the tool's word.
 *
 *  - `options_ok`: on every relevant table the table options say `stats_sample_pages=<the state's pages>` and
 *    `stats_auto_recalc=0`.
 *  - `max_sample_size`: the largest `sample_size` of the index statistics of each relevant table after the state was made
 *    (what the server says it sampled). It is recorded, not judged against the page count: InnoDB counts pages of more than
 *    one level of the tree, so it can be a little above the count (5 for 4 pages was seen). `ordering` judges it across states.
 *  - `restated_tables`: how many relevant tables have another statistic after ANALYZE TABLE than before (an unchanged one is
 *    possible: the same pages sampled again from small tables; it is counted, not judged).
 *  - `unchanged_during_explain`: the copy taken after the EXPLAINs is the copy taken before them (the statistics did not
 *    move while the plans were taken).
 *  - `ok`: `options_ok` and `unchanged_during_explain` hold.
 *
 * `ordering` (across the states of one database): on every relevant table the sample size is not larger in a state of fewer
 * pages than in a state of more pages (low <= default <= high). Equal is allowed (a small table is read whole in every state);
 * `lower_with_fewer_pages` counts the tables where low < high, which is where the state can be seen to have taken effect.
 */
final class StatsCheck
{
    /**
     * @param list<string> $relevant
     *
     * @return array<string, mixed>
     */
    public static function state(StatsSnapshot $before, StatsSnapshot $afterSet, StatsSnapshot $afterExplain, array $relevant, int $pages): array
    {
        $optionsBad = [];
        $restated = 0;
        $maxSample = [];
        foreach ($relevant as $table) {
            $options = $afterSet->createOptions($table);
            if ($options === null || !str_contains(strtolower($options), 'stats_sample_pages=' . $pages) || !str_contains(strtolower($options), 'stats_auto_recalc=0')) {
                $optionsBad[] = $table;
            }
            $size = $afterSet->maxSampleSize($table);
            $maxSample[$table] = $size;
            if ($before->tableStats($table) !== $afterSet->tableStats($table) || $before->maxSampleSize($table) !== $size || $before->lastUpdate($table) !== $afterSet->lastUpdate($table)) {
                ++$restated;
            }
        }
        $unchanged = $afterSet->sameAs($afterExplain);
        $optionsOk = $optionsBad === [] && $relevant !== [];

        return [
            'pages' => $pages,
            'relevant_tables' => count($relevant),
            'options_ok' => $optionsOk,
            'options_wrong_on' => $optionsBad,
            'max_sample_size' => $maxSample,
            'restated_tables' => $restated,
            'changed_by_the_state' => $before->differences($afterSet),
            'unchanged_during_explain' => $unchanged,
            'changed_during_explain' => $unchanged ? ['changed' => 0, 'added' => 0, 'removed' => 0] : $afterSet->differences($afterExplain),
            'ok' => $optionsOk && $unchanged,
        ];
    }

    /**
     * @param array<string, array<string, int|null>> $maxSampleByState state => table => the largest sample size
     *
     * @return array{ok: bool, tables_compared: int, lower_with_fewer_pages: int, out_of_order_on: list<string>}
     */
    public static function ordering(array $maxSampleByState): array
    {
        $low = $maxSampleByState['low'] ?? [];
        $default = $maxSampleByState['default'] ?? [];
        $high = $maxSampleByState['high'] ?? [];
        $compared = 0;
        $strict = 0;
        $bad = [];
        foreach (array_keys($default) as $table) {
            if (!isset($low[$table], $default[$table], $high[$table])) {
                continue;
            }
            ++$compared;
            if ($low[$table] > $default[$table] || $default[$table] > $high[$table]) {
                $bad[] = (string) $table;
            }
            if ($low[$table] < $high[$table]) {
                ++$strict;
            }
        }

        return ['ok' => $bad === [] && $compared > 0, 'tables_compared' => $compared, 'lower_with_fewer_pages' => $strict, 'out_of_order_on' => $bad];
    }
}
