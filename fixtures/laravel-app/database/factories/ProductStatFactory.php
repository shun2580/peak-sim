<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductStat> */
class ProductStatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'units_sold' => 0,
        ];
    }
}
