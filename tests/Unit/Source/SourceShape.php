<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Source;

use PeakSim\Source\ConstValue;
use PeakSim\Source\MethodResolution;
use PeakSim\Source\ModelDescriptor;
use PhpParser\Node\Stmt\ClassMethod;

/** The one place that knows how the contract value objects expose their fields. */
final class SourceShape
{
    /** @return array{owner: string, file: string, name: string, via: ?string}|null null when the method was not found */
    public static function found(MethodResolution $r): ?array
    {
        $ref = $r->method;
        if ($ref === null) {
            return null;
        }
        $node = $ref->node;

        return [
            'owner' => $ref->owner,
            'file' => $ref->file,
            'name' => $node instanceof ClassMethod ? $node->name->toString() : '',
            'via' => $ref->via,
        ];
    }

    /** @return string|null 'VendorParent' | 'Missing' | 'TraitConflict', null when the method was found */
    public static function notFoundReason(MethodResolution $r): ?string
    {
        return $r->miss?->name;
    }

    /** @return array{known: bool, value: mixed} */
    public static function constant(ConstValue $c): array
    {
        return ['known' => $c->known, 'value' => $c->known ? $c->value : null];
    }

    /** @return array<string, string> relation name => 'export'|'inferred' (the catalog marks the whole set) */
    public static function relationSources(ModelDescriptor $d): array
    {
        $out = [];
        foreach (array_keys($d->relations) as $name) {
            $out[(string) $name] = $d->relationsSource;
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, string> attribute name => accessor method name */
    public static function accessors(ModelDescriptor $d): array
    {
        $out = $d->accessors;
        ksort($out);

        return $out;
    }
}
