<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Readers\LazyOrderReader;
use Illuminate\Support\ServiceProvider;

class ReaderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, LazyOrderReader::class);
    }
}
