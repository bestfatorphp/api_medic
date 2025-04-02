<?php

namespace App\Providers;

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
            return new UniSenderService(
                config('unisender.api_key'),
                config('unisender.api_url'),
                config('unisender.retry_count'),
                config('unisender.retry_delay'),
                config('unisender.timeout'),
                config('unisender.default_sender_name'),
                config('unisender.default_sender_email'),
                config('unisender.default_sender_phone')
            );
        });

        $this->app->singleton('intellect-dialog', function () {
            return new IntellectDialogService(
                config('intellect-dialog.api_key_v1'),
                config('intellect-dialog.api_url_v1'),
                config('intellect-dialog.api_key_v2'),
                config('intellect-dialog.api_url_v2'),
            );
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
