<?php

declare(strict_types=1);

namespace PeakSim\Input;

/** The boundary to artisan: an export directory or a live command. Both must give the same output. */
interface ArtisanSource
{
    /** @return list<RouteEntry> sorted by uri */
    public function routes(): array;

    /** Null when there is no information (the caller makes a MODEL_INFO_UNAVAILABLE gap). */
    public function modelInfo(string $class): ?ModelInfo;
}
