<?php

declare(strict_types=1);

namespace PeakSim\Model;

enum CardinalityKind: string
{
    case Bounded = 'bounded';
    case Table = 'table';
    case Unknown = 'unknown';
}
