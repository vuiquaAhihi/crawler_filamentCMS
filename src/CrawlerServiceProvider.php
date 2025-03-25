<?php
namespace Kho8k\Crawler;

use Filament\Pages\Page;
use Illuminate\Support\ServiceProvider;

class CrawlerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'filament8k');

        \Filament\Facades\Filament::registerPages([
            \Kho8k\Crawler\Filament\Pages\NguonCCrawler::class, 
        ]);

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/filament8k'),
        ], 'crawler-views');
    }
}
