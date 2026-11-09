<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Load;

/**
 * The scenario text as the first stage sees it: the `observed` of every `kind: target` scenario is cut out BEFORE the text is parsed,
 * so no value of it is ever parsed in the first stage, let alone carried into the loaded input or the files written from it
 * (section 3.4: "the tool does not read it until the prediction is out and recorded"). The second stage
 * ({@see TwoStage::stageTwo}) reads the whole file again.
 *
 * A YAML file cannot be read in part, so the bytes of the file are in memory; what is guaranteed is that no value of a target's
 * `observed` is parsed, kept or written by the first stage.
 */
final class StageOneText
{
    /**
     * @throws LoadFailure SCENARIO_INVALID when the scenario list cannot be told from the text
     */
    public static function withoutTargetObserved(string $text): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        // the scenario items: a "- " at the indent of the first item under "scenarios:"
        $start = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^scenarios:\s*(#.*)?$/', $line) === 1) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            return $text;
        }
        $itemIndent = null;
        $blocks = [];
        $current = null;
        for ($i = $start, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if (trim($line) === '' || ltrim($line)[0] === '#') {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            if ($indent === 0 && !str_starts_with($line, '- ')) {
                break; // the next top-level key
            }
            if (preg_match('/^(\s*)-(\s|$)/', $line, $m) === 1 && ($itemIndent === null || strlen($m[1]) === $itemIndent)) {
                $itemIndent = strlen($m[1]);
                $current = count($blocks);
                $blocks[$current] = ['from' => $i, 'to' => $i];
            }
            if ($current !== null) {
                $blocks[$current]['to'] = $i;
            }
        }
        $drop = [];
        foreach ($blocks as $b) {
            $kind = null;
            for ($i = $b['from']; $i <= $b['to']; $i++) {
                if (preg_match('/^\s*(?:-\s+)?kind:\s*["\']?([A-Za-z_-]+)["\']?\s*(#.*)?$/', $lines[$i], $m) === 1) {
                    $kind = $m[1];
                    break;
                }
            }
            if ($kind !== 'target') {
                continue;
            }
            for ($i = $b['from']; $i <= $b['to']; $i++) {
                if (preg_match('/^(\s*)(-\s+)?observed\s*:/', $lines[$i], $m) !== 1) {
                    continue;
                }
                if (($m[2] ?? '') !== '') {
                    throw new LoadFailure(LoadFailure::SCENARIO_INVALID, 'observed must not be the first key of a scenario item (line ' . ($i + 1) . ')');
                }
                $keyIndent = strlen($m[1]);
                $drop[$i] = true;
                for ($j = $i + 1; $j <= $b['to']; $j++) {
                    $t = $lines[$j];
                    if (trim($t) === '' || ltrim($t)[0] === '#') {
                        $drop[$j] = true;
                        continue;
                    }
                    if (strlen($t) - strlen(ltrim($t)) <= $keyIndent) {
                        break;
                    }
                    $drop[$j] = true;
                }
            }
        }
        $kept = [];
        foreach ($lines as $i => $line) {
            if (!isset($drop[$i])) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }
}
