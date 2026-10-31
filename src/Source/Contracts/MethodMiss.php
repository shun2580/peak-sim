<?php

declare(strict_types=1);

namespace PeakSim\Source;

enum MethodMiss: string
{
    /** The search left the app through a vendor ancestor. */
    case VendorParent = 'vendor_parent';
    /** No ancestor is a vendor class and none declares it. */
    case Missing = 'missing';
    case TraitConflict = 'trait_conflict';
}
