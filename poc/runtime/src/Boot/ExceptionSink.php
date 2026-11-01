<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Boot;

/** The last exception the app's handler reported during a request (Laravel turns it into a 500 response). */
final class ExceptionSink
{
    private ?\Throwable $last = null;

    public function record(\Throwable $e): void
    {
        $this->last = $e;
    }

    /** Returns the recorded exception and forgets it. */
    public function take(): ?\Throwable
    {
        $e = $this->last;
        $this->last = null;

        return $e;
    }
}
