<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Engine;

use PeakSim\Analysis\Engine\TypeMerge;
use PeakSim\Analysis\Type\BuilderT;
use PeakSim\Analysis\Type\ListT;
use PeakSim\Analysis\Type\ModelT;
use PeakSim\Analysis\Type\ObjectT;
use PeakSim\Analysis\Type\PlainT;
use PeakSim\Analysis\Type\ShapeT;
use PeakSim\Analysis\Type\UnionT;
use PeakSim\Analysis\Type\UnknownT;
use PeakSim\Model\Cardinality;
use PeakSim\Model\Delivery;
use PeakSim\Model\Origin;
use PHPUnit\Framework\TestCase;

final class TypeMergeTest extends TestCase
{
    public function testEqualTypesJoinToThemselvesWithoutLoss(): void
    {
        $a = new ModelT('App\Order', ['customer']);

        $joined = TypeMerge::join($a, new ModelT('App\Order', ['customer']));

        self::assertSame($a->fingerprint(), $joined->type->fingerprint());
        self::assertFalse($joined->lossy);
    }

    public function testEagerLoadsKeepOnlyWhatBothSidesLoaded(): void
    {
        $joined = TypeMerge::join(new ModelT('App\Order', ['customer', 'items']), new ModelT('App\Order', ['items']));

        self::assertInstanceOf(ModelT::class, $joined->type);
        self::assertSame(['items'], $joined->type->eager);
        self::assertTrue($joined->lossy);
    }

    public function testAnUnknownWinsOverEverything(): void
    {
        $unknown = new UnknownT();

        self::assertSame($unknown, TypeMerge::join(new ModelT('App\Order'), $unknown)->type);
        self::assertSame($unknown, TypeMerge::join($unknown, new PlainT())->type);
    }

    public function testAPlainValueDisappearsIntoTheOtherSideLikeANull(): void
    {
        $model = new ModelT('App\Order');

        $joined = TypeMerge::join($model, new PlainT());

        self::assertSame($model, $joined->type);
        self::assertFalse($joined->lossy);
        self::assertInstanceOf(PlainT::class, TypeMerge::join(new PlainT(), new PlainT())->type);
    }

    public function testListsTakeTheWorseCardinalityAndDelivery(): void
    {
        $bounded = new ListT(new PlainT(), Cardinality::bounded());
        $table = new ListT(new ModelT('App\Order'), Cardinality::table('orders'), Delivery::Streamed);
        $materialized = new ListT(new ModelT('App\Order'), Cardinality::table('orders'), Delivery::Materialized);
        $unknown = new ListT(new PlainT(), Cardinality::unknown());

        $a = TypeMerge::join($bounded, $table);
        self::assertTrue($a->lossy);
        self::assertInstanceOf(ListT::class, $a->type);
        self::assertSame('table', $a->type->card->kind->value);

        $b = TypeMerge::join($table, $materialized);
        self::assertInstanceOf(ListT::class, $b->type);
        self::assertSame(Delivery::Materialized, $b->type->delivery);

        $c = TypeMerge::join($table, $unknown);
        self::assertInstanceOf(ListT::class, $c->type);
        self::assertSame('unknown', $c->type->card->kind->value, 'a loop over it must stay a gap');
    }

    public function testAWindowStaysAWindowOnlyIfBothAre(): void
    {
        $window = new ListT(new PlainT(), Cardinality::table('orders'), Delivery::Streamed, true);
        $whole = new ListT(new PlainT(), Cardinality::table('orders'), Delivery::Streamed, false);

        $joined = TypeMerge::join($window, $whole);

        self::assertInstanceOf(ListT::class, $joined->type);
        self::assertFalse($joined->type->window);
    }

    public function testDifferentKindsBecomeAUnionInAStableOrder(): void
    {
        $x = TypeMerge::join(new ModelT('App\Order'), new ObjectT('App\Foo'));
        $y = TypeMerge::join(new ObjectT('App\Foo'), new ModelT('App\Order'));

        self::assertInstanceOf(UnionT::class, $x->type);
        self::assertSame($x->type->fingerprint(), $y->type->fingerprint());
        self::assertFalse($x->lossy);
    }

    public function testAUnionAbsorbsAMemberOfItsOwnKind(): void
    {
        $union = new UnionT([new ModelT('App\Order', ['a']), new ObjectT('App\Foo')]);

        $joined = TypeMerge::join($union, new ModelT('App\Order', ['b']));

        self::assertInstanceOf(UnionT::class, $joined->type);
        self::assertCount(2, $joined->type->members);
        self::assertTrue($joined->lossy);
    }

    public function testBuildersAndShapesJoin(): void
    {
        $builder = TypeMerge::join(new BuilderT('App\Order', null, ['a'], true), new BuilderT('App\Order', null, ['a', 'b'], false));
        self::assertInstanceOf(BuilderT::class, $builder->type);
        self::assertSame(['a'], $builder->type->eager);
        self::assertFalse($builder->type->bounded, 'bounded only if both are');

        $shape = TypeMerge::join(new ShapeT(['a' => new PlainT()]), new ShapeT(['b' => new ModelT('App\Order')]));
        self::assertInstanceOf(ShapeT::class, $shape->type);
        self::assertSame(['a', 'b'], array_keys($shape->type->entries));
    }

    public function testModelsFromDifferentRowSetsLoseTheirOrigin(): void
    {
        $a = (new ModelT('App\Order'))->withOrigin(new Origin('orders'));
        $b = (new ModelT('App\Order'))->withOrigin(new Origin('archive'));

        $joined = TypeMerge::join($a, $b);

        self::assertInstanceOf(ModelT::class, $joined->type);
        self::assertNull($joined->type->origin);
        self::assertTrue($joined->lossy);
    }
}
