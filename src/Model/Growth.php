<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** Display label of a growth class. The degree (an integer) is carried separately. */
enum Growth: string
{
    case Constant = 'O(1)';
    case Linear = 'O(N)';
    case Nested = 'O(N*M)';
    case Unknown = 'unknown';

    /** 0 => O(1), 1 => O(N), 2 or more => O(N*M). */
    public static function fromDegree(int $degree): self
    {
        if ($degree < 0) {
            throw new \InvalidArgumentException('degree must not be negative');
        }

        return match (true) {
            $degree === 0 => self::Constant,
            $degree === 1 => self::Linear,
            default => self::Nested,
        };
    }
}
