<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool auth(bool $isLogout = false)                                                                                 Аутентификация/выход(сброс сессии)
 *
 * Методы для работы со подписчиками:
 * @method static array memberExists(string $identifier, ?string $addrType = null)                                                  Проверить существование подписчика в системе
 * @method static array memberFind(string $identifier)                                                                              Найти всех подписчиков с указанным идентификатором (по всем типам)
 * @method static array memberGet(string $identifier, ?string $addrType = null, array $options = [])                                Получить данные подписчика
 * @method static array memberHeadList(string $identifier, ?string $addrType = null)                                                Получить список всех идентификаторов и сопутствующую информацию для указанного пользователя
 * @method static array memberWhere(string $identifier, ?string $addrType = null, $groups = null, ?string $trackInfo = null)        Получить список групп-фильтров, в которых состоит подписчик
 * @method static array memberList(array $options = [])                                                                             Получить список подписчиков с возможностью фильтрации, сортировки и форматирования вывода
 * @method static array memberListCount(array $options = [])                                                                        Получить количество подписчиков с возможностью фильтрации и детализацией по статусам
 *
 * Методы для работы с группами:
 * @method static array groupList(array $options = [])                                                                              Получить список групп адресов с возможностью фильтрации и сортировки
 * @method static array groupGet($id, bool $withFilter = false)                                                                     Получить информацию о группе или списке групп
 *
 * Методы для работы с рассылками (выпусками) в архиве (завершены):
 * @method static array issueList(array $options = [])                                                                              Получить список выпусков в архиве с возможностью фильтрации
 * @method static array issueGet(string $id, array $options = [])                                                                   Получить информацию о конкретном выпуске из архива
 *
 * Методы для работы со статистикой:
 * @method static array statUni(array $options)                                                                                     Универсальная статистика по переходам, открытиям, тиражам и результатам доставки
 *
 * @see \App\Services\SendSayService
 */
class SendSay extends Facade
{
    /**
     * Получение имени сервиса
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'send-say';
    }
}
