<?php

declare(strict_types=1);

namespace PeakSim\Binding;

use PeakSim\Model\Site;

final readonly class Candidate
{
    public function __construct(
        public string $impl,
        public Site $site,
        public bool $conditional,
        /** bind | singleton | scoped | property | contextual | self */
        public string $kind,
    ) {
    }
}
