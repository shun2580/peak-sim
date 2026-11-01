<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

use PDO;

/** The contract of design 3.2. */
interface DataGenerator
{
    /**
     * Fills the measurement database the connection has selected, according to the plan.
     *
     * @param array<string, mixed> $profile optional per-app knowledge: skip (list of tables), rows (table => rows),
     *                                      values (table => column => constant)
     *
     * @throws \PeakSim\Runtime\Config\MeasureRefused when the selected database is not a measurement database
     */
    public function generate(PDO $pdo, ScalePlan $plan, array $profile): GenerationReport;
}
