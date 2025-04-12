<?php

namespace App\Providers;

use App\Services\IqSmsService;
use Illuminate\Support\ServiceProvider;
use App\Services\IntellectDialogService;
use App\Services\UniSenderService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('uni-sender', function () {
            return new UniSenderService();
        });

        $this->app->singleton('intellect-dialog', function () {
            return new IntellectDialogService();
        });

        $this->app->singleton('iqsms', function () {
            return new IqSmsService();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
