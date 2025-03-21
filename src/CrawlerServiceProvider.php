<?php
namespace Kho8k\Crawler;

use Filament\Pages\Page;
use Illuminate\Support\ServiceProvider;

class CrawlerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'kho8k-crawler');

        \Filament\Facades\Filament::registerPages([
            \Kho8k\Crawler\Filament\Pages\FetchMovies::class, 
        ]);

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/kho8k-crawler'),
        ], 'kho8k-crawler-views');
    }
}
