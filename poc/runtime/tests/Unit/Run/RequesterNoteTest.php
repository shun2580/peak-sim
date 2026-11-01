<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PeakSim\Runtime\Run\Requester;
use PHPUnit\Framework\TestCase;

/** What the tool keeps of a failed answer, so that "422" or "404" can be told apart afterwards. */
final class RequesterNoteTest extends TestCase
{
    public function testAValidationAnswerKeepsTheMessageAndTheNamesOfTheFieldsThatFailed(): void
    {
        $body = json_encode(['message' => 'The name field is required.', 'errors' => ['name' => ['The name field is required.'], 'email' => ['bad']]], JSON_THROW_ON_ERROR);

        self::assertSame('The name field is required. | fields: name, email', Requester::noteOf(422, $body));
    }

    public function testANotFoundAnswerKeepsItsMessage(): void
    {
        self::assertSame('No query results for model [App\\Models\\Song] 1', Requester::noteOf(404, json_encode(['message' => 'No query results for model [App\\Models\\Song] 1'], JSON_THROW_ON_ERROR)));
    }

    public function testASuccessfulAnswerKeepsNothing(): void
    {
        self::assertNull(Requester::noteOf(200, json_encode(['message' => 'ok'], JSON_THROW_ON_ERROR)));
        self::assertNull(Requester::noteOf(302, ''));
    }

    public function testAnAnswerThatIsNotJsonKeepsNothing(): void
    {
        self::assertNull(Requester::noteOf(422, '<html>nope</html>'));
        self::assertNull(Requester::noteOf(500, ''));
        self::assertNull(Requester::noteOf(404, '"just a string"'));
    }

    public function testTheNoteIsCutAt200Characters(): void
    {
        self::assertSame(200, mb_strlen((string) Requester::noteOf(422, json_encode(['message' => str_repeat('x', 500)], JSON_THROW_ON_ERROR))));
    }

    public function testOnlyTheNamesOfTheFieldsAreKeptNotTheirMessages(): void
    {
        $note = (string) Requester::noteOf(422, json_encode(['errors' => ['a' => ['secret-looking value']]], JSON_THROW_ON_ERROR));

        self::assertSame('fields: a', $note);
    }
}
