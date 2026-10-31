<?php

declare(strict_types=1);

namespace PeakSim\Model;

enum Axis: string
{
    case Query = 'query';
    case Memory = 'memory';
}
