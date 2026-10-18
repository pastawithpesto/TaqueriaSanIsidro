<?php

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Number::useLocale('es_MX');
        Number::useCurrency('MXN');

        // Detrás de un proxy o túnel HTTPS (TRUSTED_PROXIES=*). Se lee de config para que funcione con config:cache.
        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies);

            // El Host llega reescrito: las URLs salen de APP_URL.
            URL::forceRootUrl(config('app.url'));

            if (str_starts_with(config('app.url'), 'https://')) {
                URL::forceScheme('https');
            }
        }
    }
}
