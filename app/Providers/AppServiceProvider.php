<?php

namespace App\Providers;

use App\Services\ApExtractor;
use App\Services\ClaudeExtractor;
use App\Services\MockExtractor;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ApExtractor::class, fn () => config('pmp.extractor') === 'claude'
            ? new ClaudeExtractor()
            : new MockExtractor());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
