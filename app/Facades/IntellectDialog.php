<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class IntellectDialog extends Facade
{
    /**
     * Получение имени сервиса
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'intellect-dialog';
    }
}
