<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Serialization;

use PeakSim\Analysis\Contracts\EvalContext;

/** Which classes are resources, and which resource shows the elements of a resource collection. */
final class ResourceClasses
{
    public const JSON_RESOURCE = 'Illuminate\Http\Resources\Json\JsonResource';
    public const RESOURCE_COLLECTION = 'Illuminate\Http\Resources\Json\ResourceCollection';

    public static function isResource(string $class, EvalContext $ctx): bool
    {
        return $ctx->source()->extendsVendor($class, self::JSON_RESOURCE) || self::isCollection($class, $ctx);
    }

    public static function isCollection(string $class, EvalContext $ctx): bool
    {
        return $ctx->source()->extendsVendor($class, self::RESOURCE_COLLECTION);
    }

    /** True when the app declares a toArray() for the class (its own, a trait's or an ancestor's). */
    public static function declaresToArray(string $class, EvalContext $ctx): bool
    {
        $found = $ctx->source()->resolveMethod($class, 'toArray');

        return $found->method !== null && $found->method->node->stmts !== null;
    }

    /**
     * The resource that shows each element of a ResourceCollection class, the way Laravel finds it: `$collects`,
     * else the class name without its "Collection" suffix, else with "Resource" in its place.
     */
    public static function elementOf(string $collectionClass, EvalContext $ctx): ?string
    {
        $declared = ClassProperties::default($ctx->source(), $collectionClass, 'collects');
        if ($declared !== null) {
            $class = is_string($declared->value) ? ltrim($declared->value, '\\') : '';

            return $declared->known && $class !== '' && $ctx->source()->findClass($class) !== null && self::isResource($class, $ctx) ? $class : null;
        }
        if (!str_ends_with($collectionClass, 'Collection')) {
            return null;
        }
        $base = substr($collectionClass, 0, -strlen('Collection'));
        foreach ([$base, $base . 'Resource'] as $candidate) {
            if ($ctx->source()->findClass($candidate) !== null && self::isResource($candidate, $ctx)) {
                return $candidate;
            }
        }

        return null;
    }
}
