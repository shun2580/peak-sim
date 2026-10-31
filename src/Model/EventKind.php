<?php

declare(strict_types=1);

namespace PeakSim\Model;

enum EventKind: string
{
    case Select = 'select';
    case Aggregate = 'aggregate';
    case Write = 'write';
    case LazyLoad = 'lazy_load';
    case EagerLoad = 'eager_load';
    case Payload = 'payload';
    case CacheRestore = 'cache_restore';
    case Accumulate = 'accumulate';
    case PdoBuffer = 'pdo_buffer';
}
