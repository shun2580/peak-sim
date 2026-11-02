<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PeakSim\Runtime\Measure\MeasureRequest;
use PeakSim\Runtime\Route\BodyBuilder;
use PeakSim\Runtime\Run\Attempt;
use PeakSim\Runtime\Run\Requester;
use PHPUnit\Framework\TestCase;

/** The names of the fields a 422 answer says failed, carried from the answer to the request that is made next. */
final class ValidationKeysTest extends TestCase
{
    public function testTheNamesOfTheFailedFieldsAreReadFromA422Answer(): void
    {
        $body = json_encode(['message' => 'x', 'errors' => ['name' => ['required'], 'items.0.sku' => ['bad']]], JSON_THROW_ON_ERROR);

        self::assertSame(['name', 'items.0.sku'], Requester::errorKeysOf(422, $body));
    }

    public function testOnlyA422AnswerGivesKeys(): void
    {
        $body = json_encode(['errors' => ['name' => ['required']]], JSON_THROW_ON_ERROR);

        self::assertSame([], Requester::errorKeysOf(400, $body));
        self::assertSame([], Requester::errorKeysOf(200, $body));
    }

    public function testAnAnswerThatIsNotJsonOrHasNoErrorsGivesNone(): void
    {
        self::assertSame([], Requester::errorKeysOf(422, '<html></html>'));
        self::assertSame([], Requester::errorKeysOf(422, '{"message":"x"}'));
        self::assertSame([], Requester::errorKeysOf(422, '{"errors":"nope"}'));
    }

    public function testAKeyIsKeptOnlyWhenItIsAPlainFieldName(): void
    {
        $body = json_encode(['errors' => ['ok_name' => [], 'a.b.0' => [], "bad key\n" => [], str_repeat('x', 200) => []]], JSON_THROW_ON_ERROR);

        self::assertSame(['ok_name', 'a.b.0'], Requester::errorKeysOf(422, $body));
    }

    public function testNoMoreThanTwentyKeysAreKept(): void
    {
        $errors = [];
        for ($i = 1; $i <= 30; $i++) {
            $errors['f' . $i] = ['bad'];
        }

        self::assertCount(20, Requester::errorKeysOf(422, json_encode(['errors' => $errors], JSON_THROW_ON_ERROR)));
    }

    public function testTheKeysTravelInTheAttemptRecordOnlyWhenThereAreSome(): void
    {
        $with = new Attempt('r', 'POST', 10, 'N', Attempt::WARMUP, 422, 1, [], [], 10, 1, false, 'validation_422', null, errorKeys: ['name']);
        $without = new Attempt('r', 'POST', 10, 'N', Attempt::WARMUP, 200, 1, [], [], 10, 1, false, 'ok', null);

        self::assertSame(['name'], $with->toArray()['error_keys']);
        self::assertArrayNotHasKey('error_keys', $without->toArray());
        self::assertSame(['name'], Attempt::fromArray(json_decode($with->toJson(), true, 512, JSON_THROW_ON_ERROR))->errorKeys);
        self::assertSame([], Attempt::fromArray($without->toArray())->errorKeys);
    }

    public function testTheRetryBodyIsTheErrorKeysFilledWithAValueOfTheRoundsType(): void
    {
        self::assertSame(['name' => 'x_1'], BodyBuilder::bodyFromErrorKeys(['name'], 1));
        self::assertSame(['items' => [['sku' => 1]]], BodyBuilder::bodyFromErrorKeys(['items.0.sku'], 2));
        self::assertSame(['tags' => []], BodyBuilder::bodyFromErrorKeys(['tags'], 3));
    }

    public function testARequestCanBeSentAgainWithAnotherBody(): void
    {
        $request = new MeasureRequest('t', 10, 'N', '/r', '/r', 'POST', 'U:1', ['a' => 1], false, true, [], false, [Attempt::WARMUP, Attempt::COLD], []);
        $again = $request->withBody(['a' => 1, 'b' => 'x_1']);

        self::assertSame(['a' => 1, 'b' => 'x_1'], $again->body);
        self::assertSame(['a' => 1], $request->body);
        self::assertSame('U:1', $again->actAs);
        self::assertTrue($again->csrf);
    }
}
