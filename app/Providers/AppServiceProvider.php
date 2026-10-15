<?php

namespace App\Providers;

use App\Contracts\ImageGenerator;
use App\Services\FalAiImageGenerator;
use App\Services\MockImageGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ImageGenerator::class, function ($app) {
            if (filled(config('prompt.fal_key'))) {
                return $app->make(FalAiImageGenerator::class);
            }

            return $app->make(MockImageGenerator::class);
        });
    }

    public function boot(): void
    {
        //
    }
}
