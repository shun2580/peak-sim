<?php

declare(strict_types=1);

namespace PeakSim\Source;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Class index of one app. Classes are found through the PSR-4 map of the app's own composer.json
 * (classmap and files autoloading are not read), and every file is parsed at most once.
 *
 * A class that is not found through that map is a vendor class (or does not exist): the index never
 * looks inside vendor/.
 */
final class AppSourceIndex implements SourceIndex
{
    private const MAX_DEPTH = 50;
    private const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', '.git'];

    private readonly string $root;

    /** @var list<array{prefix: string, dirs: list<string>}> longest prefix first */
    private array $psr4 = [];

    /** @var array<string, list<Node\Stmt>> relative path => name-resolved AST */
    private array $parsed = [];

    /** @var array<string, string> relative path => message of the syntax error */
    private array $failed = [];

    /** @var array<string, ClassInfo|null> */
    private array $classes = [];

    /** @var array<string, ClassInfo>|null every class declared under the PSR-4 directories */
    private ?array $everyClass = null;

    private readonly Parser $parser;

    private readonly ConstantEvaluator $constants;

    public function __construct(string $appRoot)
    {
        $this->root = rtrim($appRoot, '/');
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->constants = new ConstantEvaluator($this);
        $this->readComposer();
    }

    public function findClass(string $fqcn): ?ClassInfo
    {
        $fqcn = self::normalize($fqcn);
        if (array_key_exists($fqcn, $this->classes)) {
            return $this->classes[$fqcn];
        }
        $file = $this->fileFor($fqcn);
        if ($file === null) {
            return $this->classes[$fqcn] = null;
        }
        foreach ($this->declaredIn($file) as $info) {
            if ($info->fqcn === $fqcn) {
                return $this->classes[$fqcn] = $info;
            }
        }

        return $this->classes[$fqcn] = null;
    }

    /**
     * An unknown top-level class counts as "left the app": it may be a vendor class that has the method.
     */
    public function resolveMethod(string $fqcn, string $method): MethodResolution
    {
        return $this->resolveInClass(self::normalize($fqcn), $method, 0);
    }

    /**
     * Every class below $fqcn: subclasses at any depth, and for an interface its implementers.
     *
     * @return list<string>
     * @throws ParseFailed when any file under the PSR-4 directories has a syntax error: a subclass could hide there
     */
    public function subclassesOf(string $fqcn): array
    {
        $fqcn = self::normalize($fqcn);
        $children = [];
        foreach ($this->allClasses() as $info) {
            foreach (array_filter([$info->parent, ...$info->interfaces]) as $ancestor) {
                $children[$ancestor][] = $info->fqcn;
            }
        }
        $found = [];
        $queue = $children[$fqcn] ?? [];
        while ($queue !== []) {
            $next = array_shift($queue);
            if (isset($found[$next]) || $next === $fqcn) {
                continue;
            }
            $found[$next] = true;
            array_push($queue, ...($children[$next] ?? []));
        }
        $names = array_keys($found);
        sort($names, SORT_STRING);

        return $names;
    }

    public function extendsVendor(string $fqcn, string $vendorFqcn): bool
    {
        $vendorFqcn = self::normalize($vendorFqcn);
        $queue = [self::normalize($fqcn)];
        $seen = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $vendorFqcn) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            $info = $this->findClass($current);
            if ($info === null) {
                continue;
            }
            if ($info->parent !== null) {
                $queue[] = $info->parent;
            }
            array_push($queue, ...$info->interfaces);
        }

        return false;
    }

    public function propertyType(string $fqcn, string $property): ?string
    {
        $current = self::normalize($fqcn);
        for ($depth = 0; $current !== '' && $depth < self::MAX_DEPTH; $depth++) {
            $info = $this->findClass($current);
            if ($info === null) {
                return null;
            }
            $declared = $this->declaredPropertyType($info, $property);
            if ($declared !== false) {
                return $declared;
            }
            $current = $info->parent ?? '';
        }

        return null;
    }

    public function classConstant(string $fqcn, string $name): ConstValue
    {
        return $this->constants->classConstant(self::normalize($fqcn), $name);
    }

    public function evaluate(Node\Expr $e, ?string $classContext): ConstValue
    {
        return $this->constants->evaluate($e, $classContext === null ? null : self::normalize($classContext));
    }

    public function relative(string $absolute): string
    {
        if (str_starts_with($absolute, $this->root . '/')) {
            return substr($absolute, strlen($this->root) + 1);
        }

        return $absolute;
    }

    /** @return list<string> */
    public function files(string $dir): array
    {
        $dir = self::cleanDirectory($dir);
        $base = $dir === '' ? $this->root : $this->root . '/' . $dir;
        if (!is_dir($base)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), self::SKIPPED_DIRECTORIES, true)),
        ));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $this->relative(str_replace('\\', '/', $file->getPathname()));
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<Node\Stmt> */
    public function parse(string $file): array
    {
        $relative = $this->relative($file);
        if (isset($this->parsed[$relative])) {
            return $this->parsed[$relative];
        }
        if (isset($this->failed[$relative])) {
            throw new ParseFailed($this->failed[$relative]);
        }
        $path = str_starts_with($file, '/') ? $file : $this->root . '/' . $file;
        $code = is_file($path) ? @file_get_contents($path) : false;
        if ($code === false) {
            $this->failed[$relative] = "cannot read {$relative}";
            throw new ParseFailed($this->failed[$relative]);
        }
        try {
            $stmts = $this->parser->parse($code) ?? [];
            $stmts = (new NodeTraverser(new NameResolver()))->traverse($stmts);
        } catch (\PhpParser\Error $e) {
            $this->failed[$relative] = "syntax error in {$relative}: {$e->getRawMessage()} on line {$e->getStartLine()}";
            throw new ParseFailed($this->failed[$relative], 0, $e);
        }

        return $this->parsed[$relative] = $stmts;
    }

    /** The declared default of a property of the class itself (not its parents), or null when there is none. */
    public function propertyDefault(ClassInfo $info, string $property): ?Node\Expr
    {
        foreach ($info->node->getProperties() as $declaration) {
            foreach ($declaration->props as $item) {
                if ($item->name->toString() === $property) {
                    return $item->default;
                }
            }
        }

        return null;
    }

    public static function normalize(string $fqcn): string
    {
        return ltrim($fqcn, '\\');
    }

    private function resolveInClass(string $fqcn, string $method, int $depth): MethodResolution
    {
        $info = $this->findClass($fqcn);
        if ($info === null || $depth >= self::MAX_DEPTH) {
            return MethodResolution::notFound(MethodMiss::VendorParent);
        }
        $own = $info->node->getMethod($method);
        if ($own !== null) {
            return MethodResolution::found(new MethodRef($info->fqcn, $info->file, $own));
        }
        $viaTraits = $this->lookupViaTraits($info, $method, 0);
        if ($viaTraits['conflict']) {
            return MethodResolution::notFound(MethodMiss::TraitConflict);
        }
        if ($viaTraits['ref'] !== null) {
            return MethodResolution::found($viaTraits['ref']);
        }
        if ($viaTraits['vendor']) {
            // a vendor trait may define it, and would win over the parent: the search leaves the app here
            return MethodResolution::notFound(MethodMiss::VendorParent);
        }
        if ($info->parent === null) {
            return MethodResolution::notFound(MethodMiss::Missing);
        }

        return $this->resolveInClass($info->parent, $method, $depth + 1);
    }

    /**
     * What the traits used by $holder expose under the name $name, honouring insteadof and as.
     *
     * @return array{ref: ?MethodRef, conflict: bool, vendor: bool}
     */
    private function lookupViaTraits(ClassInfo $holder, string $name, int $depth): array
    {
        $none = ['ref' => null, 'conflict' => false, 'vendor' => false];
        if ($depth >= self::MAX_DEPTH) {
            return ['ref' => null, 'conflict' => true, 'vendor' => false];
        }
        $used = [];
        $precedences = [];
        $aliases = [];
        foreach ($holder->node->stmts as $stmt) {
            if (!$stmt instanceof TraitUse) {
                continue;
            }
            foreach ($stmt->traits as $trait) {
                $used[] = $trait->toString();
            }
            foreach ($stmt->adaptations as $adaptation) {
                if ($adaptation instanceof TraitUseAdaptation\Precedence) {
                    $precedences[] = $adaptation;
                } elseif ($adaptation instanceof TraitUseAdaptation\Alias) {
                    $aliases[] = $adaptation;
                }
            }
        }
        if ($used === []) {
            return $none;
        }

        $exposed = [];
        $conflict = false;
        $vendor = false;
        foreach (array_unique($used) as $trait) {
            $traitInfo = $this->findClass($trait);
            if ($traitInfo === null) {
                $vendor = true;
                continue;
            }
            if (!$this->isExcluded($trait, $name, $precedences)) {
                $found = $this->lookupInTrait($traitInfo, $name, $depth + 1);
                $conflict = $conflict || $found['conflict'];
                $vendor = $vendor || $found['vendor'];
                if ($found['ref'] !== null) {
                    $exposed[] = new MethodRef($found['ref']->owner, $found['ref']->file, $found['ref']->node, $trait);
                }
            }
            foreach ($aliases as $alias) {
                if ($alias->newName === null || strcasecmp($alias->newName->toString(), $name) !== 0) {
                    continue;
                }
                if ($alias->trait !== null && $alias->trait->toString() !== $trait) {
                    continue;
                }
                $found = $this->lookupInTrait($traitInfo, $alias->method->toString(), $depth + 1);
                $conflict = $conflict || $found['conflict'];
                if ($found['ref'] !== null) {
                    $exposed[] = new MethodRef($found['ref']->owner, $found['ref']->file, $found['ref']->node, $trait);
                }
            }
        }

        $distinct = [];
        foreach ($exposed as $ref) {
            $distinct[$ref->owner . '#' . spl_object_id($ref->node)] = $ref;
        }
        if ($conflict || count($distinct) > 1) {
            return ['ref' => null, 'conflict' => true, 'vendor' => $vendor];
        }

        return ['ref' => $distinct === [] ? null : array_values($distinct)[0], 'conflict' => false, 'vendor' => $vendor];
    }

    /** @return array{ref: ?MethodRef, conflict: bool, vendor: bool} */
    private function lookupInTrait(ClassInfo $trait, string $name, int $depth): array
    {
        $own = $trait->node->getMethod($name);
        if ($own !== null) {
            return ['ref' => new MethodRef($trait->fqcn, $trait->file, $own), 'conflict' => false, 'vendor' => false];
        }

        return $this->lookupViaTraits($trait, $name, $depth);
    }

    /** @param list<TraitUseAdaptation\Precedence> $precedences */
    private function isExcluded(string $trait, string $method, array $precedences): bool
    {
        foreach ($precedences as $precedence) {
            if (strcasecmp($precedence->method->toString(), $method) !== 0) {
                continue;
            }
            foreach ($precedence->insteadof as $excluded) {
                if ($excluded->toString() === $trait) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return string|null|false the class name, null when declared but not a plain class type, false when not declared here */
    private function declaredPropertyType(ClassInfo $info, string $property): string|null|false
    {
        $constructor = $info->node->getMethod('__construct');
        foreach ($constructor?->params ?? [] as $param) {
            if ($param->isPromoted() && $param->var instanceof Node\Expr\Variable && $param->var->name === $property) {
                return $this->classNameOfType($param->type, $info->fqcn);
            }
        }
        foreach ($info->node->stmts as $stmt) {
            if (!$stmt instanceof Property) {
                continue;
            }
            foreach ($stmt->props as $item) {
                if ($item->name->toString() === $property) {
                    return $this->classNameOfType($stmt->type, $info->fqcn);
                }
            }
        }

        return false;
    }

    private function classNameOfType(?Node $type, string $self): ?string
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }
        if (!$type instanceof Node\Name) {
            return null;
        }
        $name = $type->toString();

        return match (strtolower($name)) {
            'self' => $self,
            'static', 'parent' => null,
            default => $name,
        };
    }

    /** @return list<ClassInfo> */
    private function declaredIn(string $file): array
    {
        $infos = [];
        foreach ((new NodeFinder())->findInstanceOf($this->parse($file), ClassLike::class) as $node) {
            if ($node->namespacedName === null) {
                continue;
            }
            $infos[] = $this->describeNode($node, $file);
        }

        return $infos;
    }

    private function describeNode(ClassLike $node, string $file): ClassInfo
    {
        $traits = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traits[] = $trait->toString();
                }
            }
        }
        $parent = $node instanceof Class_ ? $node->extends?->toString() : null;
        $interfaces = match (true) {
            $node instanceof Class_, $node instanceof Enum_ => $node->implements,
            $node instanceof Interface_ => $node->extends,
            default => [],
        };

        return new ClassInfo(
            $node->namespacedName->toString(),
            $this->relative($file),
            match (true) {
                $node instanceof Interface_ => 'interface',
                $node instanceof Trait_ => 'trait',
                $node instanceof Enum_ => 'enum',
                default => 'class',
            },
            $node instanceof Class_ && $node->isAbstract(),
            $parent,
            array_values(array_map(static fn (Node\Name $n): string => $n->toString(), $interfaces)),
            array_values(array_unique($traits)),
            $node,
        );
    }

    /** @return array<string, ClassInfo> */
    private function allClasses(): array
    {
        if ($this->everyClass !== null) {
            return $this->everyClass;
        }
        $all = [];
        foreach ($this->psr4 as $entry) {
            foreach ($entry['dirs'] as $dir) {
                foreach ($this->files($dir) as $file) {
                    foreach ($this->declaredIn($file) as $info) {
                        $all[$info->fqcn] = $info;
                    }
                }
            }
        }
        ksort($all, SORT_STRING);

        return $this->everyClass = $all;
    }

    private function fileFor(string $fqcn): ?string
    {
        foreach ($this->psr4 as $entry) {
            if ($entry['prefix'] !== '' && !str_starts_with($fqcn, $entry['prefix'])) {
                continue;
            }
            $rest = str_replace('\\', '/', substr($fqcn, strlen($entry['prefix']))) . '.php';
            foreach ($entry['dirs'] as $dir) {
                $relative = $dir === '' ? $rest : $dir . '/' . $rest;
                if (is_file($this->root . '/' . $relative)) {
                    return $relative;
                }
            }
        }

        return null;
    }

    private function readComposer(): void
    {
        $path = $this->root . '/composer.json';
        $json = is_file($path) ? @file_get_contents($path) : false;
        $data = $json === false ? null : json_decode($json, true);
        $map = is_array($data) && is_array($data['autoload']['psr-4'] ?? null) ? $data['autoload']['psr-4'] : [];
        foreach ($map as $prefix => $dirs) {
            $prefix = (string) $prefix;
            if ($prefix !== '' && !str_ends_with($prefix, '\\')) {
                $prefix .= '\\';
            }
            $this->psr4[] = [
                'prefix' => $prefix,
                'dirs' => array_values(array_map(self::cleanDirectory(...), (array) $dirs)),
            ];
        }
        usort($this->psr4, static fn (array $a, array $b): int => strlen($b['prefix']) <=> strlen($a['prefix']) ?: strcmp($a['prefix'], $b['prefix']));
    }

    private static function cleanDirectory(string $dir): string
    {
        $dir = trim($dir, '/');

        return $dir === '.' ? '' : (str_starts_with($dir, './') ? substr($dir, 2) : $dir);
    }
}
