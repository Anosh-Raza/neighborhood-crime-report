<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\Geocoding\ChainGeocoder;
use App\Services\Geocoding\GeocoderInterface;
use App\Services\Geocoding\NominatimGeocoder;
use App\Services\Geocoding\OpenMeteoGeocoder;
use App\Services\Geocoding\PhotonGeocoder;
use App\Services\News\GdeltNewsProvider;
use App\Services\News\NewsSearchService;
use App\Services\News\RssNewsProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeocoderInterface::class, function ($app) {
            // Photon + Open-Meteo first (faster); Nominatim as backup.
            return new ChainGeocoder([
                $app->make(PhotonGeocoder::class),
                $app->make(OpenMeteoGeocoder::class),
                $app->make(NominatimGeocoder::class),
            ]);
        });

        $this->app->singleton(NewsSearchService::class, function ($app) {
            return new NewsSearchService([
                $app->make(GdeltNewsProvider::class),
                $app->make(RssNewsProvider::class),
            ]);
        });
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        config([
            'app.cron_secret' => env('CRON_SECRET'),
        ]);

        // Windows/local PHP often lacks a CA bundle; skip verify only in local.
        if ($this->app->environment('local')) {
            Http::globalOptions(['verify' => false]);
        }
    }
}
