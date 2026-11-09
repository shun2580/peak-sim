<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Yaml;

/**
 * The small part of YAML a scenario file uses, read without a package (the runtime has none and takes none in).
 *
 * Read: block mappings and block sequences (indent by spaces; a sequence may sit at the indent of its key), `- key: value` items,
 * flow mappings and flow sequences on one line (`{ "GET /x": 5.0, "GET /y": 1 }`), plain / 'single' / "double" quoted scalars,
 * integers, decimals, `true`/`false`, `null`/`~`, comments (`#` at a start or after a space). One leading `---` is accepted.
 *
 * Refused with a {@see YamlError} (never read differently): tabs in the indent, anchors/aliases/tags, block scalars (`|` `>`),
 * flow collections over several lines, a duplicate key, a mapping or sequence at a wrong indent, a second document.
 * Pure and deterministic.
 */
final class MiniYaml
{
    /** @var list<array{int, string, int}> indent, content, line number */
    private array $lines = [];

    private int $pos = 0;

    public static function parse(string $text): mixed
    {
        $p = new self();
        $p->lines = self::lines($text);
        if ($p->lines === []) {
            return null;
        }
        $value = $p->node($p->lines[0][0]);
        if ($p->pos < count($p->lines)) {
            throw new YamlError(sprintf('line %d: unexpected indentation or content', $p->lines[$p->pos][2]));
        }

        return $value;
    }

    /** @return list<array{int, string, int}> */
    private static function lines(string $text): array
    {
        $out = [];
        $seenStart = false;
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $i => $raw) {
            $no = $i + 1;
            $content = self::stripComment($raw);
            if (trim($content) === '') {
                continue;
            }
            if (preg_match('/^(\s*)/', $content, $m) === 1 && str_contains($m[1], "\t")) {
                throw new YamlError(sprintf('line %d: a tab in the indent', $no));
            }
            $trim = trim($content);
            if ($trim === '---' && !$seenStart) {
                $seenStart = true;
                continue;
            }
            if ($trim === '---' || $trim === '...') {
                throw new YamlError(sprintf('line %d: a second document is not read', $no));
            }
            $seenStart = true;
            $out[] = [strlen($content) - strlen(ltrim($content)), trim($content), $no];
        }

        return $out;
    }

    private static function stripComment(string $line): string
    {
        $quote = null;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($quote !== null) {
                if ($quote === '"' && $c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if (($c === '"' || $c === "'") && ($i === 0 || strpbrk($line[$i - 1], " \t[{,:-") !== false)) {
                $quote = $c;
            } elseif ($c === '#' && ($i === 0 || $line[$i - 1] === ' ' || $line[$i - 1] === "\t")) {
                return rtrim(substr($line, 0, $i));
            }
        }

        return rtrim($line);
    }

    private static function isSeq(string $content): bool
    {
        return $content === '-' || str_starts_with($content, '- ');
    }

    private function node(int $indent): mixed
    {
        return self::isSeq($this->lines[$this->pos][1]) ? $this->seq($indent) : $this->map($indent);
    }

    /** @return array<string, mixed> */
    private function map(int $indent): array
    {
        $map = [];
        while ($this->pos < count($this->lines) && $this->lines[$this->pos][0] === $indent && !self::isSeq($this->lines[$this->pos][1])) {
            [, $content, $no] = $this->lines[$this->pos];
            $split = self::splitKey($content, $no);
            if ($split === null) {
                throw new YamlError(sprintf('line %d: a mapping entry (key: value) was expected', $no));
            }
            [$key, $rest] = $split;
            if (array_key_exists($key, $map)) {
                throw new YamlError(sprintf('line %d: the key "%s" is given twice', $no, $key));
            }
            $this->pos++;
            if ($rest === '') {
                $next = $this->lines[$this->pos] ?? null;
                if ($next !== null && ($next[0] > $indent || ($next[0] === $indent && self::isSeq($next[1])))) {
                    $map[$key] = $this->node($next[0]);
                } else {
                    $map[$key] = null;
                }
            } else {
                $map[$key] = self::inline($rest, $no);
            }
        }
        if ($this->pos < count($this->lines) && $this->lines[$this->pos][0] > $indent) {
            throw new YamlError(sprintf('line %d: the indentation does not match the entries above it', $this->lines[$this->pos][2]));
        }

        return $map;
    }

    /** @return list<mixed> */
    private function seq(int $indent): array
    {
        $list = [];
        while ($this->pos < count($this->lines) && $this->lines[$this->pos][0] === $indent && self::isSeq($this->lines[$this->pos][1])) {
            [, $content, $no] = $this->lines[$this->pos];
            $rest = ltrim(substr($content, 1));
            if ($rest === '') {
                $this->pos++;
                $next = $this->lines[$this->pos] ?? null;
                $list[] = $next !== null && $next[0] > $indent ? $this->node($next[0]) : null;
                continue;
            }
            if (self::splitKey($rest, $no) !== null) {
                // "- key: value": the entry is the first line of a mapping whose other keys sit under the key
                $childIndent = $indent + (strlen($content) - strlen($rest));
                $this->lines[$this->pos] = [$childIndent, $rest, $no];
                $list[] = $this->map($childIndent);
                continue;
            }
            $this->pos++;
            $list[] = self::inline($rest, $no);
        }
        if ($this->pos < count($this->lines) && $this->lines[$this->pos][0] > $indent) {
            throw new YamlError(sprintf('line %d: the indentation does not match the items above it', $this->lines[$this->pos][2]));
        }

        return $list;
    }

    /** @return array{string, string}|null the key and what follows "key:", or null when the line is not a mapping entry */
    private static function splitKey(string $content, int $no): ?array
    {
        $c = $content[0];
        if ($c === '{' || $c === '[') {
            return null;
        }
        if ($c === '"' || $c === "'") {
            $at = 0;
            $key = self::quoted($content, $at, $no);
            $after = substr($content, $at);
            if ($after === ':' || str_starts_with($after, ': ')) {
                return [$key, trim(substr($after, 1))];
            }

            return null;
        }
        if (preg_match('/^(.*?):(?: +(.*))?$/', $content, $m) !== 1) {
            return null;
        }
        $key = trim($m[1]);
        if ($key === '' || str_contains($key, ': ')) {
            return null;
        }
        if (preg_match('/^[&*!|>%@`]/', $key) === 1) {
            throw new YamlError(sprintf('line %d: anchors, tags and block scalars are not read', $no));
        }

        return [$key, trim($m[2] ?? '')];
    }

    private static function inline(string $text, int $no): mixed
    {
        if (preg_match('/^[&*!|>%@`]/', $text) === 1) {
            throw new YamlError(sprintf('line %d: anchors, aliases, tags and block scalars are not read', $no));
        }
        $at = 0;
        $value = self::flow($text, $at, $no, false);
        if (trim(substr($text, $at)) !== '') {
            throw new YamlError(sprintf('line %d: unexpected text after the value: %s', $no, trim(substr($text, $at))));
        }

        return $value;
    }

    private static function flow(string $s, int &$at, int $no, bool $inside): mixed
    {
        self::skip($s, $at);
        $c = $s[$at] ?? '';
        if ($c === '{') {
            $at++;
            $map = [];
            self::skip($s, $at);
            if (($s[$at] ?? '') === '}') {
                $at++;

                return $map;
            }
            while (true) {
                self::skip($s, $at);
                if ($at >= strlen($s)) {
                    throw new YamlError(sprintf('line %d: a flow mapping must end on its line', $no));
                }
                $key = in_array($s[$at] ?? '', ['"', "'"], true) ? self::quoted($s, $at, $no) : trim(self::plainUntil($s, $at, ':', $no));
                self::skip($s, $at);
                if (($s[$at] ?? '') !== ':') {
                    throw new YamlError(sprintf('line %d: ":" was expected after the key "%s"', $no, $key));
                }
                $at++;
                if (array_key_exists($key, $map)) {
                    throw new YamlError(sprintf('line %d: the key "%s" is given twice', $no, $key));
                }
                $map[$key] = self::flow($s, $at, $no, true);
                self::skip($s, $at);
                $d = $s[$at] ?? '';
                $at++;
                if ($d === '}') {
                    return $map;
                }
                if ($d !== ',') {
                    throw new YamlError(sprintf('line %d: "," or "}" was expected (a flow mapping must end on its line)', $no));
                }
            }
        }
        if ($c === '[') {
            $at++;
            $list = [];
            self::skip($s, $at);
            if (($s[$at] ?? '') === ']') {
                $at++;

                return $list;
            }
            while (true) {
                $list[] = self::flow($s, $at, $no, true);
                self::skip($s, $at);
                $d = $s[$at] ?? '';
                $at++;
                if ($d === ']') {
                    return $list;
                }
                if ($d !== ',') {
                    throw new YamlError(sprintf('line %d: "," or "]" was expected (a flow sequence must end on its line)', $no));
                }
            }
        }
        if ($c === '"' || $c === "'") {
            return self::quoted($s, $at, $no);
        }

        return self::scalar(trim($inside ? self::plainUntil($s, $at, ',', $no, true) : substr($s, $at, strlen($s) - $at)), $at, $s, $inside);
    }

    private static function skip(string $s, int &$at): void
    {
        while (($s[$at] ?? '') === ' ') {
            $at++;
        }
    }

    /** Plain text up to (not including) the stop character or a closing bracket of the flow collection. */
    private static function plainUntil(string $s, int &$at, string $stop, int $no, bool $valueEnd = false): string
    {
        $start = $at;
        $len = strlen($s);
        while ($at < $len) {
            $c = $s[$at];
            if ($c === $stop || ($valueEnd && ($c === '}' || $c === ']'))) {
                break;
            }
            if (!$valueEnd && ($c === ',' || $c === '}' || $c === ']')) {
                throw new YamlError(sprintf('line %d: a key was expected before "%s"', $no, $c));
            }
            $at++;
        }

        return substr($s, $start, $at - $start);
    }

    /** A plain scalar already cut out as text. For a block-level value the text runs to the end of the line, so the position moves there. */
    private static function scalar(string $text, int &$at, string $s, bool $inside): mixed
    {
        if (!$inside) {
            $at = strlen($s);
        }
        if ($text === '' || $text === '~' || $text === 'null') {
            return null;
        }
        if ($text === 'true') {
            return true;
        }
        if ($text === 'false') {
            return false;
        }
        if (preg_match('/^-?(0|[1-9][0-9]*)$/', $text) === 1) {
            return (int) $text;
        }
        if (preg_match('/^-?(0|[1-9][0-9]*)\.[0-9]+([eE][+-]?[0-9]+)?$|^-?(0|[1-9][0-9]*)[eE][+-]?[0-9]+$/', $text) === 1) {
            return (float) $text;
        }

        return $text;
    }

    private static function quoted(string $s, int &$at, int $no): string
    {
        $q = $s[$at];
        $at++;
        $out = '';
        $len = strlen($s);
        while ($at < $len) {
            $c = $s[$at];
            if ($q === "'") {
                if ($c === "'") {
                    if (($s[$at + 1] ?? '') === "'") {
                        $out .= "'";
                        $at += 2;
                        continue;
                    }
                    $at++;

                    return $out;
                }
            } elseif ($c === '\\') {
                $n = $s[$at + 1] ?? '';
                $map = ['n' => "\n", 't' => "\t", '"' => '"', '\\' => '\\', '/' => '/', '0' => "\0"];
                if (!isset($map[$n])) {
                    throw new YamlError(sprintf('line %d: the escape \\%s is not read', $no, $n));
                }
                $out .= $map[$n];
                $at += 2;
                continue;
            } elseif ($c === '"') {
                $at++;

                return $out;
            }
            $out .= $c;
            $at++;
        }

        throw new YamlError(sprintf('line %d: a quoted string is not closed', $no));
    }
}
