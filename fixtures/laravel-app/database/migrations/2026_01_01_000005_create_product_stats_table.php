<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One row per product, created lazily by /ng/first-or-create-in-loop (the seeder leaves it empty).
    public function up(): void
    {
        Schema::create('product_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained();
            $table->unsignedInteger('units_sold')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stats');
    }
};
