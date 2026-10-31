<?php

namespace App\Http\Controllers\Ng;

use App\Models\Product;
use App\Models\ProductStat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeepFirstOrCreateInLoopController
{
    /**
     * Planted: firstOrCreate / updateOrCreate inside a loop over every product, two queries each.
     * Default: firstOrCreate on a product_stats table that has no row yet (SELECT + INSERT).
     * ?via=update: updateOrCreate on a row that exists, with a changed value (SELECT + UPDATE).
     * The two are never mixed in one request (on the same product that would be four queries).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $viaUpdate = $request->query('via') === 'update';
        $processed = 0;

        foreach (Product::all() as $product) {
            if ($viaUpdate) {
                ProductStat::updateOrCreate(['product_id' => $product->id], ['units_sold' => 1]);
            } else {
                ProductStat::firstOrCreate(['product_id' => $product->id], ['units_sold' => 0]);
            }
            $processed++;
        }

        return response()->json(['processed' => $processed]);
    }
}
