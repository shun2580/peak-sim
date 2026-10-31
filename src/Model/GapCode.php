<?php

declare(strict_types=1);

namespace PeakSim\Model;

/**
 * Why the analysis could not follow something. The set is frozen here; add a code only through the P0 owner.
 * The case name is the code that appears in the output.
 */
enum GapCode: string
{
    /** A function / new / static call that could not be resolved. */
    case UNRESOLVED_CALL = 'UNRESOLVED_CALL';
    /** A vendor call that is not on the known-calls list. */
    case VENDOR_CALL_NOT_MODELLED = 'VENDOR_CALL_NOT_MODELLED';
    case VENDOR_PARENT = 'VENDOR_PARENT';
    case METHOD_NOT_FOUND = 'METHOD_NOT_FOUND';
    case TRAIT_CONFLICT = 'TRAIT_CONFLICT';
    case DYNAMIC_METHOD_NAME = 'DYNAMIC_METHOD_NAME';
    case DYNAMIC_CLASS_NAME = 'DYNAMIC_CLASS_NAME';
    case NEW_UNKNOWN_CLASS = 'NEW_UNKNOWN_CLASS';
    case UNKNOWN_BUILDER_METHOD = 'UNKNOWN_BUILDER_METHOD';
    case UNKNOWN_COLLECTION_METHOD = 'UNKNOWN_COLLECTION_METHOD';
    case LOOP_SOURCE_UNKNOWN = 'LOOP_SOURCE_UNKNOWN';
    /** for / while / do-while with events in the body. */
    case LOOP_BOUND_UNKNOWN = 'LOOP_BOUND_UNKNOWN';
    case GENERATOR_NOT_ANALYZED = 'GENERATOR_NOT_ANALYZED';
    case NO_BINDING = 'NO_BINDING';
    case UNRESOLVABLE_BINDING = 'UNRESOLVABLE_BINDING';
    case BINDING_DECORATED = 'BINDING_DECORATED';
    case DEPTH_LIMIT = 'DEPTH_LIMIT';
    case RECURSION = 'RECURSION';
    case BUDGET_EXCEEDED = 'BUDGET_EXCEEDED';
    case UNION_TOO_LARGE = 'UNION_TOO_LARGE';
    case MODEL_INFO_UNAVAILABLE = 'MODEL_INFO_UNAVAILABLE';
    case MODEL_OBSERVER_NOT_ANALYZED = 'MODEL_OBSERVER_NOT_ANALYZED';
    /** morphTo and similar. */
    case RELATION_TARGET_UNKNOWN = 'RELATION_TARGET_UNKNOWN';
    case VIEW_NOT_FOUND = 'VIEW_NOT_FOUND';
    case BLADE_UNSUPPORTED = 'BLADE_UNSUPPORTED';
    case PARSE_FAILED = 'PARSE_FAILED';
    case ACCESSOR_NOT_ANALYZED = 'ACCESSOR_NOT_ANALYZED';
    case RESOURCE_NOT_ANALYZED = 'RESOURCE_NOT_ANALYZED';
    case CACHE_VALUE_SIZE_UNKNOWN = 'CACHE_VALUE_SIZE_UNKNOWN';
    case CLOSURE_ROUTE = 'CLOSURE_ROUTE';
    /** P2. */
    case MIDDLEWARE_APP_CLASS = 'MIDDLEWARE_APP_CLASS';
    case WITH_CYCLE = 'WITH_CYCLE';
    /** A model's $with / $appends / $table is not a constant, so it was left out. A $with can add queries. */
    case MODEL_PROPERTY_UNRESOLVED = 'MODEL_PROPERTY_UNRESOLVED';
}
