<?php

namespace App\Providers;

use App\Services\UniSenderService;
use Illuminate\Support\ServiceProvider;

class UniSenderServiceProvider extends ServiceProvider
{
    /**
     * Регистрация сервиса в контейнере
     */
    public function register()
    {
        $this->app->singleton(UniSenderService::class, function ($app) {
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

        //регистрируем алиас для короткого доступа
        $this->app->alias(UniSenderService::class, 'unisender');
    }

    /**
     * Загрузка сервиса
     */
    public function boot()
    {
        //
    }
}
