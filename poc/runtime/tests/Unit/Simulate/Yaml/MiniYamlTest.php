<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Yaml;

use PeakSim\Runtime\Simulate\Yaml\MiniYaml;
use PeakSim\Runtime\Simulate\Yaml\YamlError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MiniYamlTest extends TestCase
{
    public function test_the_scenario_shape_of_the_spec_is_read(): void
    {
        $yaml = <<<'YAML'
            schema_version: "0.1"
            resources:
              db_cpu:
                cores: 2              # comment after a value
                threshold: 0.8
              php_memory_limit: 128M
            scenarios:
              - id: normal-1
                kind: calibration
                tables: { pages: 10000, books: 500 }
                rps: { "GET /books/{slug}": 5.0, "GET /search": 1.0 }
                observed: { db_cpu_utilization: 0.21 }
              - id: peak-1
                kind: target
                rps:
                  "GET /books/{slug}": 15
            YAML;
        $this->assertSame([
            'schema_version' => '0.1',
            'resources' => ['db_cpu' => ['cores' => 2, 'threshold' => 0.8], 'php_memory_limit' => '128M'],
            'scenarios' => [
                ['id' => 'normal-1', 'kind' => 'calibration', 'tables' => ['pages' => 10000, 'books' => 500], 'rps' => ['GET /books/{slug}' => 5.0, 'GET /search' => 1.0], 'observed' => ['db_cpu_utilization' => 0.21]],
                ['id' => 'peak-1', 'kind' => 'target', 'rps' => ['GET /books/{slug}' => 15]],
            ],
        ], MiniYaml::parse($yaml));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function values(): iterable
    {
        yield 'integer' => ["a: 12\n", ['a' => 12]];
        yield 'negative decimal' => ["a: -0.5\n", ['a' => -0.5]];
        yield 'exponent' => ["a: 1e3\n", ['a' => 1000.0]];
        yield 'booleans and null' => ["a: true\nb: false\nc: null\nd: ~\ne:\n", ['a' => true, 'b' => false, 'c' => null, 'd' => null, 'e' => null]];
        yield 'plain text with a slash and a colon not followed by a space' => ["a: http://x/y\n", ['a' => 'http://x/y']];
        yield 'quoted keeps a hash' => ["a: \"x # y\"\nb: 'it''s'\n", ['a' => 'x # y', 'b' => "it's"]];
        yield 'a version is text when quoted and a number when not' => ["a: \"0.1\"\nb: 0.1\n", ['a' => '0.1', 'b' => 0.1]];
        yield 'quoted key with braces and a space in a flow mapping' => ['r: { "GET /a/{b}": 1, \'POST /c\': 2.5 }' . "\n", ['r' => ['GET /a/{b}' => 1, 'POST /c' => 2.5]]];
        yield 'flow sequence' => ["a: [1, two, \"3\"]\n", ['a' => [1, 'two', '3']]];
        yield 'empty flow collections' => ["a: {}\nb: []\n", ['a' => [], 'b' => []]];
        yield 'sequence at the indent of its key' => ["a:\n- 1\n- 2\nb: 3\n", ['a' => [1, 2], 'b' => 3]];
        yield 'items that are mappings' => ["a:\n  - x: 1\n    y: 2\n  - x: 3\n", ['a' => [['x' => 1, 'y' => 2], ['x' => 3]]]];
        yield 'nested sequence under a dash' => ["a:\n  -\n    - 1\n    - 2\n", ['a' => [[1, 2]]]];
        yield 'a leading document marker and comment lines' => ["---\n# top\na: 1\n\n# between\nb: 2\n", ['a' => 1, 'b' => 2]];
        yield 'an empty text' => ["\n# nothing\n", null];
    }

    #[DataProvider('values')]
    public function test_values(string $text, mixed $expected): void
    {
        $this->assertSame($expected, MiniYaml::parse($text));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'a tab in the indent' => ["a:\n\tb: 1\n", 'tab'];
        yield 'an anchor' => ["a: &x 1\n", 'not read'];
        yield 'an alias' => ["a: *x\n", 'not read'];
        yield 'a block scalar' => ["a: |\n  text\n", 'not read'];
        yield 'a duplicate key' => ["a: 1\na: 2\n", 'twice'];
        yield 'a duplicate key in a flow mapping' => ["a: { x: 1, x: 2 }\n", 'twice'];
        yield 'a wrong indent' => ["a:\n    b: 1\n  c: 2\n", 'indentation'];
        yield 'a flow mapping that does not end' => ["a: { x: 1,\n  y: 2 }\n", 'must end on its line'];
        yield 'text after a flow value' => ["a: { x: 1 } extra\n", 'unexpected text'];
        yield 'an unclosed quote' => ["a: \"abc\n", 'not closed'];
        yield 'a second document' => ["a: 1\n---\nb: 2\n", 'second document'];
        yield 'a line that is not an entry' => ["a: 1\njust text\n", 'mapping entry'];
    }

    #[DataProvider('refused')]
    public function test_what_is_not_read_is_refused_and_never_read_differently(string $text, string $messagePart): void
    {
        $this->expectException(YamlError::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messagePart, '/') . '/');
        MiniYaml::parse($text);
    }
}
