<?php

namespace Yarm\Tei;

use Illuminate\Support\ServiceProvider;

class TeiServiceProvider extends ServiceProvider
{
    public function boot()
    {
        //$this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        //$this->loadViewsFrom(__DIR__.'/views','tei');
        //$this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->mergeConfigFrom(__DIR__ . '/config/tei.php','tei');
        $this->publishes([
            //__DIR__ . '/config/tei.php' => config_path('tei.php'),
            //__DIR__.'/views' => resource_path('views/vendor/tei'),
            // Assets
            //__DIR__.'/js' => resource_path('js/vendor'),
        ],'tei');

        //after every update
        //run php artisan vendor:publish --provider="Yarm\Tei\TeiServiceProvider" --tag="tei" --force
    }

    public function register()
    {

    }


}
