<?php

declare(strict_types=1);

namespace PeakSim\Source;

use PhpParser\Node;

/** Read-only index of the app's classes. Parses each file once. */
interface SourceIndex
{
    public function findClass(string $fqcn): ?ClassInfo;

    /** Own class, then its traits (nested too), then the parent (each with its traits). */
    public function resolveMethod(string $fqcn, string $method): MethodResolution;

    /** @return list<string> */
    public function subclassesOf(string $fqcn): array;

    /** True when the ancestry reaches the vendor class, e.g. JsonResource or Model. */
    public function extendsVendor(string $fqcn, string $vendorFqcn): bool;

    /** Promoted constructor argument or typed property; null when unknown. */
    public function propertyType(string $fqcn, string $property): ?string;

    public function classConstant(string $fqcn, string $name): ConstValue;

    public function evaluate(Node\Expr $e, ?string $classContext): ConstValue;

    /** The path relative to the app root; never an absolute path. */
    public function relative(string $absolute): string;

    /**
     * @param string $dir relative to the app root
     * @return list<string> sorted
     */
    public function files(string $dir): array;

    /**
     * @return list<Node\Stmt> name-resolved AST
     * @throws ParseFailed on a syntax error
     */
    public function parse(string $file): array;
}
