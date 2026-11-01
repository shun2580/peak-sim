<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Boot;

/** A started target app and what the tool did to it. `report` is what the evidence files are made from. */
final class BootedApp
{
    /**
     * @param object               $app     Illuminate\Foundation\Application
     * @param object               $kernel  Illuminate\Contracts\Http\Kernel
     * @param array<string, mixed> $report
     */
    public function __construct(
        public readonly object $app,
        public readonly object $kernel,
        public readonly ExceptionSink $exceptions,
        public readonly ExternalCallLedger $externalCalls,
        public readonly ScratchDir $scratch,
        public readonly string $dbName,
        public readonly array $report,
    ) {
    }
}
