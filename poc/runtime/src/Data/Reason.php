<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

/** The fixed vocabulary of why a table (or a factory) could not be generated, or was left out on purpose. */
final class Reason
{
    public const FK_CYCLE = 'fk_cycle';
    public const UNSUPPORTED_TYPE = 'unsupported_type';
    public const CHECK_CONSTRAINT = 'check_constraint';
    public const UNIQUE_VIOLATION = 'unique_violation';
    public const FK_VIOLATION = 'fk_violation';
    public const NOT_NULL_VIOLATION = 'not_null_violation';
    public const NO_DEFAULT = 'no_default';
    public const DATA_TOO_LONG = 'data_too_long';
    public const OUT_OF_RANGE = 'out_of_range';
    public const INSERT_ERROR = 'insert_error';
    public const PARENT_NOT_GENERATED = 'parent_not_generated';
    public const COUNT_MISMATCH = 'count_mismatch';
    public const AUTOINC_GAP = 'autoinc_gap';
    public const FAKER_MISSING = 'faker_missing';
    public const NO_FACTORY = 'no_factory';
    public const FACTORY_ERROR = 'factory_error';

    /** Left out on purpose (a skipped table is not a failure). */
    public const FRAMEWORK_TABLE = 'framework_table';
    public const PROFILE_SKIP = 'profile_skip';

    private function __construct()
    {
    }
}
