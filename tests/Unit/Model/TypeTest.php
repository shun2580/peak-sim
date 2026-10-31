<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Model;

use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ClosureT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ResourceT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\Type;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Cardinality;
use PeakSim\Model\Delivery;
use PeakSim\Model\Origin;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class TypeTest extends TestCase
{
    public function testEveryTypeIsFinal(): void
    {
        foreach ([ModelT::class, BuilderT::class, ListT::class, ShapeT::class, ObjectT::class, ClosureT::class, ResourceT::class, UnionT::class, PlainT::class, UnknownT::class] as $class) {
            $ref = new \ReflectionClass($class);
            self::assertTrue($ref->isFinal(), "$class must be final");
            self::assertTrue($ref->isSubclassOf(Type::class), "$class must extend Type");
        }
    }

    public function testEveryPublicPropertyIsReadonly(): void
    {
        foreach ([ModelT::class, BuilderT::class, ListT::class, ShapeT::class, ObjectT::class, ClosureT::class, ResourceT::class, UnionT::class] as $class) {
            foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                self::assertTrue($property->isReadOnly(), "$class::\$" . $property->getName() . ' must be readonly');
            }
        }
    }

    public function testModelEagerSetIsNormalizedSoOrderAndDuplicatesDoNotChangeTheKey(): void
    {
        $a = new ModelT('App\Order', ['items', 'customer', 'items']);
        $b = new ModelT('App\Order', ['customer', 'items']);

        self::assertSame(['customer', 'items'], $a->eager);
        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function testEagerLoadedAndNotEagerLoadedModelsHaveDifferentFingerprints(): void
    {
        $lazy = new ModelT('App\Order');
        $eager = $lazy->withEager(['customer']);

        self::assertNotSame($lazy->fingerprint(), $eager->fingerprint());
        self::assertSame([], $lazy->eager, 'withEager() does not change the original');
        self::assertSame(['customer'], $eager->eager);
        self::assertSame(['customer', 'items'], $eager->withEager(['items', 'customer'])->eager);
    }

    public function testModelOriginIsPartOfTheFingerprint(): void
    {
        $model = new ModelT('App\Order');

        self::assertNotSame($model->fingerprint(), $model->withOrigin(new Origin('orders'))->fingerprint());
        self::assertNotSame($model->withOrigin(new Origin('orders'))->fingerprint(), $model->withOrigin(new Origin('customers'))->fingerprint());
        self::assertSame($model->fingerprint(), $model->withOrigin(new Origin('orders'))->withOrigin(null)->fingerprint());
    }

    public function testBuilderFingerprintSeesEagerBoundedAndRelationOf(): void
    {
        $base = new BuilderT('App\Order');

        self::assertNotSame($base->fingerprint(), $base->withBounded()->fingerprint());
        self::assertNotSame($base->fingerprint(), $base->withEager(['customer'])->fingerprint());
        self::assertNotSame($base->fingerprint(), $base->withRelationOf(['App\Order', 'items'])->fingerprint());
        self::assertTrue($base->withBounded()->bounded);
        self::assertFalse($base->bounded);
        self::assertSame((new BuilderT('App\Order', null, ['b', 'a']))->fingerprint(), (new BuilderT('App\Order', null, ['a', 'b']))->fingerprint());
        self::assertNotSame((new BuilderT(null, 'orders'))->fingerprint(), (new BuilderT(null, 'customers'))->fingerprint());
    }

    public function testListFingerprintSeesElementCardinalityDeliveryAndWindow(): void
    {
        $list = new ListT(new ModelT('App\Order'), Cardinality::table('orders'));

        self::assertSame(Delivery::Materialized, $list->delivery);
        self::assertFalse($list->window);

        $prints = [
            $list->fingerprint(),
            $list->withElem(new PlainT())->fingerprint(),
            $list->withCard(Cardinality::bounded())->fingerprint(),
            $list->withCard(Cardinality::table('customers'))->fingerprint(),
            $list->withCard(Cardinality::unknown())->fingerprint(),
            $list->withDelivery(Delivery::Streamed)->fingerprint(),
            $list->withDelivery(Delivery::PdoBuffered)->fingerprint(),
            $list->withWindow()->fingerprint(),
        ];
        self::assertSame($prints, array_values(array_unique($prints)));
        self::assertTrue($list->withWindow()->window);
        self::assertFalse($list->window, 'with…() does not change the original');
    }

    public function testShapeKeepsEntriesAndFingerprintsThem(): void
    {
        $shape = new ShapeT(['orders' => new ListT(new ModelT('App\Order'), Cardinality::table('orders'))]);
        $more = $shape->with('total', new PlainT());

        self::assertSame(['orders'], array_keys($shape->entries));
        self::assertSame(['orders', 'total'], array_keys($more->entries));
        self::assertNotSame($shape->fingerprint(), $more->fingerprint());
    }

    public function testResourceFingerprintSeesManyAndInner(): void
    {
        $one = new ResourceT('App\Http\Resources\OrderResource', new ModelT('App\Order'));

        self::assertNotSame($one->fingerprint(), (new ResourceT($one->resourceClass, $one->inner, true))->fingerprint());
        self::assertNotSame($one->fingerprint(), $one->withInner(new PlainT())->fingerprint());
    }

    public function testUnionIsOrderIndependentAndDeduplicated(): void
    {
        $a = new ModelT('App\Order');
        $b = new PlainT();

        $union = new UnionT([$a, $b, $a]);

        self::assertCount(2, $union->members);
        self::assertSame($union->fingerprint(), (new UnionT([$b, $a]))->fingerprint());
        self::assertCount(3, $union->withMember(new ObjectT('App\Foo'))->members);
        self::assertCount(2, $union->members, 'withMember() does not change the original');
    }

    public function testUnionKnowsWhenItIsTooLarge(): void
    {
        $members = [];
        for ($i = 0; $i < UnionT::MAX_MEMBERS; $i++) {
            $members[] = new ObjectT('App\C' . $i);
        }
        $full = new UnionT($members);

        self::assertSame(8, UnionT::MAX_MEMBERS);
        self::assertFalse($full->isTooLarge());
        self::assertTrue($full->withMember(new ObjectT('App\Extra'))->isTooLarge());
    }

    public function testPlainAndUnknownAreDistinctAndStable(): void
    {
        self::assertSame((new PlainT())->fingerprint(), (new PlainT())->fingerprint());
        self::assertSame((new UnknownT())->fingerprint(), (new UnknownT())->fingerprint());
        self::assertNotSame((new PlainT())->fingerprint(), (new UnknownT())->fingerprint());
    }

    public function testClosureFingerprintIsTheSourcePositionPlusCapturedVariables(): void
    {
        $closure = $this->closureAt("<?php\n\n\$f = fn(\$o) => \$o;\n");
        $other = $this->closureAt("<?php\n\$f = fn(\$o) => \$o;\n");

        $plain = new ClosureT($closure, [], 'app/A.php');
        $withModel = $plain->withCaptured(['order' => new ModelT('App\Order')]);

        self::assertSame($plain->fingerprint(), (new ClosureT($closure, [], 'app/A.php'))->fingerprint());
        self::assertNotSame($plain->fingerprint(), (new ClosureT($closure, [], 'app/B.php'))->fingerprint(), 'same position in another file');
        self::assertNotSame($plain->fingerprint(), (new ClosureT($other, [], 'app/A.php'))->fingerprint(), 'another position');
        self::assertNotSame($plain->fingerprint(), $withModel->fingerprint());
        self::assertSame(
            $withModel->fingerprint(),
            (new ClosureT($closure, ['order' => new ModelT('App\Order')], 'app/A.php'))->fingerprint(),
        );
        self::assertSame([], $plain->captured, 'withCaptured() does not change the original');
    }

    public function testFingerprintsAreDeterministicAcrossFreshConstruction(): void
    {
        $build = static fn (): Type => new ListT(
            new ModelT('App\Order', ['items', 'customer'], new Origin('orders')),
            Cardinality::table('orders'),
            Delivery::Streamed,
            true,
        );

        self::assertSame($build()->fingerprint(), $build()->fingerprint());
    }

    private function closureAt(string $code): Node\Expr\ArrowFunction
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        self::assertNotNull($ast);
        $stmt = $ast[0];
        self::assertInstanceOf(Node\Stmt\Expression::class, $stmt);
        $assign = $stmt->expr;
        self::assertInstanceOf(Node\Expr\Assign::class, $assign);
        self::assertInstanceOf(Node\Expr\ArrowFunction::class, $assign->expr);

        return $assign->expr;
    }
}
