<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string sendSms(string $phone, string $text, array $options = [])               Отправка SMS-сообщения
 * @method static string status(array $ids)                                                      Получение статуса отправленных сообщений
 * @method static string statusQueue(string $queueName, int $limit = 5)                          Получение очереди статусов
 * @method static string balance()                                                               Получение баланса
 * @method static string senders()                                                               Получение списка отправителей
 *
 * @see \App\Services\IqSmsService
 */
class IqSmsFacade extends Facade
{
    /**
     * Получение имени сервиса
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'iqsms';
    }
}
