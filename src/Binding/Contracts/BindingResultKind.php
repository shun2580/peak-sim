<?php

declare(strict_types=1);

namespace PeakSim\Binding;

enum BindingResultKind: string
{
    case Candidates = 'candidates';
    case None = 'none';
    case Unresolvable = 'unresolvable';
}
