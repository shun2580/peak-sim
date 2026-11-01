<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Judge;

/**
 * What a runtime measurement says about one route. Never an unqualified "O(1)": it always carries the scope it was
 * measured in and is worded as "this input, this path". The words differ from the static analysis on purpose
 * (measured_O1 is not O(1)).
 *
 *  - measured_O1 / measured_ON / measured_ONM : the sweep decided, on a path that touched the scaled data
 *  - inconclusive : the slope fell between the two limits, or the linear claim failed the spread check
 *  - no_info      : the sweep looked flat, but the scaled tables were never read (untouched_data) or the response
 *                   stayed empty (suspect_empty), so the flatness proves nothing
 *  - not_measured : the route could not be measured (the reason says why), or it had too few points
 */
final class Verdict
{
    public const MEASURED_O1 = 'measured_O1';

    public const MEASURED_ON = 'measured_ON';

    public const MEASURED_ONM = 'measured_ONM';

    public const INCONCLUSIVE = 'inconclusive';

    public const NO_INFO = 'no_info';

    public const NOT_MEASURED = 'not_measured';

    private const SCOPE_PATTERN = '/^(?:global|targeted:[A-Za-z0-9_.]+)$/';

    /**
     * @param list<int> $scales
     * @param list<int|float> $values
     * @param list<int> $mScales
     * @param list<int|float> $mValues
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $scope,
        public readonly ?string $reason,
        public readonly ?float $slope,
        public readonly ?float $slopeM,
        public readonly array $scales,
        public readonly array $values,
        public readonly array $mScales,
        public readonly array $mValues,
        public readonly bool $gateBypass,
    ) {
    }

    /**
     * @param list<int> $scales ascending
     * @param list<int|float> $values query counts per scale
     */
    public static function fromSweep(
        string $scope,
        array $scales,
        array $values,
        bool $untouchedData = false,
        bool $suspectEmpty = false,
        bool $gateBypass = false,
    ): self {
        self::assertScope($scope);
        $raw = GrowthRules::verdict($scales, $values);
        $slope = count($scales) >= 2 && count($values) === count($scales) ? GrowthRules::slope($scales, $values) : null;

        return self::decide($raw, $scope, $slope, null, $scales, $values, [], [], $untouchedData, $suspectEmpty, $gateBypass);
    }

    /**
     * Two sweeps: the rows of the scaled table (N) and the children per parent row (M). Only a targeted scope can
     * say O(N*M); a global scope scales every table together and cannot tell N from M.
     *
     * @param list<int> $nScales
     * @param list<int|float> $nValues
     * @param list<int> $mScales
     * @param list<int|float> $mValues
     */
    public static function fromSweeps(
        string $scope,
        array $nScales,
        array $nValues,
        array $mScales,
        array $mValues,
        bool $untouchedData = false,
        bool $suspectEmpty = false,
        bool $gateBypass = false,
    ): self {
        self::assertScope($scope);
        if ($scope === 'global') {
            throw new \InvalidArgumentException('a global scope scales every table together; it cannot say O(N*M)');
        }
        $raw = GrowthRules::productVerdict($nScales, $nValues, $mScales, $mValues);
        $slopeN = count($nScales) >= 2 && count($nValues) === count($nScales) ? GrowthRules::slope($nScales, $nValues) : null;
        $slopeM = count($mScales) >= 2 && count($mValues) === count($mScales) ? GrowthRules::slope($mScales, $mValues) : null;

        return self::decide($raw, $scope, $slopeN, $slopeM, $nScales, $nValues, $mScales, $mValues, $untouchedData, $suspectEmpty, $gateBypass);
    }

    /**
     * A verdict rebuilt from its array form (`toArray()`, or the same keys in a run report row), to put a stored
     * result beside other data without measuring again.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $kind = (string) ($row['verdict'] ?? '');
        $known = [self::MEASURED_O1, self::MEASURED_ON, self::MEASURED_ONM, self::INCONCLUSIVE, self::NO_INFO, self::NOT_MEASURED];
        if (!in_array($kind, $known, true)) {
            throw new \InvalidArgumentException('not a verdict: ' . $kind);
        }
        $scope = isset($row['scope']) ? (string) $row['scope'] : null;
        if ($scope !== null) {
            self::assertScope($scope);
        }

        return new self(
            $kind,
            $scope,
            isset($row['reason']) ? (string) $row['reason'] : null,
            isset($row['slope']) ? (float) $row['slope'] : null,
            isset($row['slope_m']) ? (float) $row['slope_m'] : null,
            array_map('intval', (array) ($row['scales'] ?? [])),
            array_values((array) ($row['values'] ?? [])),
            array_map('intval', (array) ($row['m_scales'] ?? [])),
            array_values((array) ($row['m_values'] ?? [])),
            (bool) ($row['gate_bypass'] ?? false),
        );
    }

    public static function notMeasured(string $reason, ?string $scope = null, bool $gateBypass = false): self
    {
        if ($scope !== null) {
            self::assertScope($scope);
        }

        return new self(self::NOT_MEASURED, $scope, $reason, null, null, [], [], [], [], $gateBypass);
    }

    /** The growth word of the static analysis ('O(1)', 'O(N)', 'O(N*M)'), or null when the measurement decided nothing. */
    public function growth(): ?string
    {
        return match ($this->kind) {
            self::MEASURED_O1 => 'O(1)',
            self::MEASURED_ON => 'O(N)',
            self::MEASURED_ONM => 'O(N*M)',
            default => null,
        };
    }

    public function isNoInfo(): bool
    {
        return $this->kind === self::NO_INFO;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'verdict' => $this->kind,
            'scope' => $this->scope,
            'reason' => $this->reason,
            'scales' => $this->scales,
            'values' => $this->values,
            'm_scales' => $this->mScales,
            'm_values' => $this->mValues,
            'slope' => $this->slope === null ? null : round($this->slope, 4),
            'slope_m' => $this->slopeM === null ? null : round($this->slopeM, 4),
            'gate_bypass' => $this->gateBypass,
            'statement' => $this->statement(),
        ];
    }

    /** The sentence a report may print for this verdict. */
    public function statement(): string
    {
        $where = $this->scope === null ? '' : sprintf(' (scope: %s)', $this->scope);
        $bypass = $this->gateBypass ? ' [authorization bypassed: policy queries not counted, reference only]' : '';

        return match ($this->kind) {
            self::MEASURED_O1, self::MEASURED_ON, self::MEASURED_ONM => sprintf(
                '%s: measured on this input along the path that was taken%s%s',
                $this->kind,
                $where,
                $bypass,
            ),
            self::INCONCLUSIVE => sprintf(
                'inconclusive: the measured slope is between the limits or not steady, so no growth class is claimed%s%s',
                $where,
                $bypass,
            ),
            self::NO_INFO => sprintf(
                'no information: the count stayed flat, but %s, so flatness proves nothing%s%s',
                str_replace(['untouched_data', 'suspect_empty', ','], ['the scaled tables were never read', 'the response stayed empty', ' and '], (string) $this->reason),
                $where,
                $bypass,
            ),
            default => sprintf('not measured: %s%s', $this->reason ?? 'unknown reason', $bypass),
        };
    }

    /**
     * @param list<int> $scales
     * @param list<int|float> $values
     * @param list<int> $mScales
     * @param list<int|float> $mValues
     */
    private static function decide(
        string $raw,
        string $scope,
        ?float $slope,
        ?float $slopeM,
        array $scales,
        array $values,
        array $mScales,
        array $mValues,
        bool $untouchedData,
        bool $suspectEmpty,
        bool $gateBypass,
    ): self {
        $kind = match ($raw) {
            GrowthRules::O1 => self::MEASURED_O1,
            GrowthRules::ON => self::MEASURED_ON,
            GrowthRules::ONM => self::MEASURED_ONM,
            GrowthRules::INCONCLUSIVE => self::INCONCLUSIVE,
            default => self::NOT_MEASURED,
        };
        $reason = $kind === self::NOT_MEASURED ? GrowthRules::INSUFFICIENT : null;

        if ($kind === self::MEASURED_O1 && ($untouchedData || $suspectEmpty)) {
            $kind = self::NO_INFO;
            $reason = implode(',', array_filter([
                $untouchedData ? 'untouched_data' : null,
                $suspectEmpty ? 'suspect_empty' : null,
            ]));
        }

        return new self($kind, $scope, $reason, $slope, $slopeM, $scales, $values, $mScales, $mValues, $gateBypass);
    }

    private static function assertScope(string $scope): void
    {
        if (preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
            throw new \InvalidArgumentException(sprintf("a measured verdict needs a scope of 'global' or 'targeted:<table>', got '%s'", $scope));
        }
    }
}
