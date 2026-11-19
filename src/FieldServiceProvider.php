<?php

namespace Semsen1\ThreeDPreviewField;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;

class FieldServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->app->booted(function () {
            $this->routes();
        });

        Nova::serving(function (ServingNova $event) {
            Nova::script('three-d-preview-field', __DIR__.'/../dist/js/field.js');
            Nova::style('three-d-preview-field', __DIR__.'/../dist/css/field.css');

            $lang = __DIR__.'/../resources/lang/'.app()->getLocale().'.json';
            if (is_readable($lang)) {
                Nova::translations($lang);
            }
        });
    }

    protected function routes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(config('nova.api_middleware', ['nova']))
            ->prefix('nova-vendor/semsen1/three-d-preview-field')
            ->group(__DIR__.'/../routes/api.php');
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
