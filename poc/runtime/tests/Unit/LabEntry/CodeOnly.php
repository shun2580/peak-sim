<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabEntry;

/** A PHP file's code as one line of text: comments and doc comments dropped, white space collapsed. */
final class CodeOnly
{
    public static function of(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT || $token[0] === T_OPEN_TAG) {
                    $out .= ' ';
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $out));
    }

    public static function file(string $path): string
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException('cannot read ' . $path);
        }

        return self::of($source);
    }
}
