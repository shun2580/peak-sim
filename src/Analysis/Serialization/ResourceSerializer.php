<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Serialization;

use PeakSim\Analysis\Contracts\EvalContext;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ResourceT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Model\GapCode;
use PhpParser\Node;

/**
 * A resource turned into an array: toArray() runs once for every element, with `$this` the element's resource
 * (a ResourceT whose inner value is the row), inside the loop of the list the elements came from. What toArray()
 * returns is serialised again, so the models and nested resources it hands back are followed.
 *
 * A resource class without a toArray() of its own serialises the wrapped model as it is (accessors included).
 * A collection class with a toArray() of its own runs that once, with `$this->collection` standing for the
 * elements as resources.
 */
final class ResourceSerializer
{
    public function __construct(private readonly Serializer $serializer)
    {
    }

    public function serialize(ResourceT $resource, Node $at, EvalContext $ctx): void
    {
        if (!$resource->many) {
            $this->element($resource->resourceClass, $resource->inner, $at, $ctx);

            return;
        }
        $isCollection = ResourceClasses::isCollection($resource->resourceClass, $ctx);
        if ($isCollection && ResourceClasses::declaresToArray($resource->resourceClass, $ctx)) {
            $key = 'collection:' . $resource->resourceClass . ':' . $resource->inner->fingerprint();
            $this->serializer->guarded($key, $resource->resourceClass, $at, $ctx, function () use ($resource, $at, $ctx): void {
                $result = $ctx->callMethod($resource->resourceClass, 'toArray', [new PlainT()], $at, $resource);
                $this->serializer->serialize($result, $at, $ctx);
            });

            return;
        }
        $elementClass = $isCollection ? ResourceClasses::elementOf($resource->resourceClass, $ctx) : $resource->resourceClass;
        if ($elementClass === null) {
            $ctx->gap(GapCode::RESOURCE_NOT_ANALYZED, $at, "{$resource->resourceClass} names no element resource (no \$collects, no matching resource class): what its elements run is not known");

            return;
        }
        $this->elements($elementClass, $resource->inner, $at, $ctx);
    }

    /** Every element of $inner, each through $class::toArray(). */
    private function elements(string $class, Type $inner, Node $at, EvalContext $ctx): void
    {
        switch (true) {
            case $inner instanceof ListT:
                $this->serializer->overRows($inner, $at, $ctx, fn (Type $elem) => $this->element($class, $elem, $at, $ctx));

                return;
            case $inner instanceof ShapeT:
                foreach ($inner->entries as $entry) {
                    $this->element($class, $entry, $at, $ctx);
                }

                return;
            case $inner instanceof UnionT:
                foreach ($inner->members as $member) {
                    $this->elements($class, $member, $at, $ctx);
                }

                return;
            default:
                // A value that is not a list of rows (a plain array, an unknown): its size is not a table's.
                $this->serializer->onceOfUnknownSize($at, $ctx, fn () => $this->element($class, $inner, $at, $ctx));

                return;
        }
    }

    /** One element: the resource's toArray() with the element as `$this`, or the model's own serialisation. */
    private function element(string $class, Type $elem, Node $at, EvalContext $ctx): void
    {
        if ($elem instanceof UnionT) {
            foreach ($elem->members as $member) {
                $this->element($class, $member, $at, $ctx);
            }

            return;
        }
        $key = 'resource:' . $class . ':' . ($elem instanceof ModelT ? $elem->class . '[' . implode(',', $elem->eager) . ']' : $elem->fingerprint());
        $this->serializer->guarded($key, $class, $at, $ctx, function () use ($class, $elem, $at, $ctx): void {
            if (!ResourceClasses::declaresToArray($class, $ctx)) {
                $this->serializer->serialize($elem, $at, $ctx);

                return;
            }
            $result = $ctx->callMethod($class, 'toArray', [new PlainT()], $at, new ResourceT($class, $elem, false));
            $this->serializer->serialize($result, $at, $ctx);
        });
    }
}
