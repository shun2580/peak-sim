<?php

declare(strict_types=1);

namespace PeakSim\Model;

/** An unknown verdict carries no confidence (null), never "low". */
enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
}
