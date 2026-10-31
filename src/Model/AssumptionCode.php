<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** What the analysis assumed instead of knowing. The set is frozen here; add a code only through the P0 owner. */
enum AssumptionCode: string
{
    case WORST_CASE_OF_CANDIDATES = 'WORST_CASE_OF_CANDIDATES';
    case BRANCH_WORST_CASE = 'BRANCH_WORST_CASE';
    case RELATION_INFERRED = 'RELATION_INFERRED';
    case DRIVER_ASSUMED = 'DRIVER_ASSUMED';
    case CACHE_DRIVER_SERIALIZING = 'CACHE_DRIVER_SERIALIZING';
    case SQL_NOT_PARSED = 'SQL_NOT_PARSED';
    case TABLE_NAME_UNKNOWN = 'TABLE_NAME_UNKNOWN';
    case ACCUMULATION_HEURISTIC = 'ACCUMULATION_HEURISTIC';
}
