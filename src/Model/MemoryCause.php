<?php

declare(strict_types=1);

namespace PeakSim\Model;

enum MemoryCause: string
{
    case Materialize = 'materialize';
    case LazyToMany = 'lazy_to_many';
    case EagerToMany = 'eager_to_many';
    case PdoBuffered = 'pdo_buffered';
    case Payload = 'payload';
    case CacheRestore = 'cache_restore';
    case Accumulate = 'accumulate';
}
