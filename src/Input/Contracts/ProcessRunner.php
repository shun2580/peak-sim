<?php

declare(strict_types=1);

namespace PeakSim\Input;

interface ProcessRunner
{
    public function run(string $commandLine, string $cwd, int $timeoutSeconds = 120): ProcessResult;
}
