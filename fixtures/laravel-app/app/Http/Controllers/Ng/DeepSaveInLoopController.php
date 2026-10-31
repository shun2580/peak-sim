<?php

namespace App\Http\Controllers\Ng;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class DeepSaveInLoopController
{
    /** Planted: save() inside a loop over every customer (one UPDATE each; the name changes, so each save writes). */
    public function __invoke(): JsonResponse
    {
        $updated = 0;

        foreach (Customer::all() as $customer) {
            $customer->name = $customer->name.' (reviewed)';
            $customer->save();
            $updated++;
        }

        return response()->json(['updated' => $updated]);
    }
}
