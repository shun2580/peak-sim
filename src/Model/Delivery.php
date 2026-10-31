<?php

declare(strict_types=1);

namespace PeakSim\Model;

enum Delivery: string
{
    /** All rows are held in memory. */
    case Materialized = 'materialized';
    /** chunk / lazy: only a window is held. */
    case Streamed = 'streamed';
    /** cursor: the driver buffers all rows. */
    case PdoBuffered = 'pdo_buffered';
}
