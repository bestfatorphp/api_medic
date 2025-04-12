<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Сервис для работы с API UniSender
 * Документация API: https://www.unisender.com/ru/support/category/api/
 *
 * Предоставляет полный функционал для:
 * - Управления списками рассылки и контактами
 * - Создания и отправки email/SMS сообщений
 * - Работы с шаблонами писем
 * - Получения статистики и отчетов
 * - Управления заметками о контактах
 *
 * Иеется проблема, письма пока не отправить!!! Для отправки писем:
 * - Домен прошёл аутентификацию в аккаунте
 * - Email отправителя должен быть подтверждён в аккаунте и соответствовал домену, который вы указали при аутентификации
 */
class UniSenderService
{
    /**
     * @var string Ключ API для аутентификации
     */
    protected string $apiKey;

    /**
     * @var string Базовый URL API UniSender
     */
    protected string $apiUrl;

    /**
     * @var int Количество попыток повтора при ошибках сети
     */
    protected int $retryCount;

    /**
     * @var int Задержка между попытками (в миллисекундах)
     */
    protected int $retryDelay;

    /**
     * @var int Таймаут запроса (в секундах)
     */
    protected int $timeout;

    /**
     * @var string|null Имя отправителя по умолчанию для email
     */
    protected ?string $defaultSenderName;

    /**
     * @var string|null Email отправителя по умолчанию
     */
    protected ?string $defaultSenderEmail;

    /**
     * @var string|null Номер отправителя по умолчанию для SMS
     */
    protected ?string $defaultSenderPhone;


    public function __construct() {
        $this->apiKey = config('unisender.api_key');
        $this->apiUrl = config('unisender.api_url');
        $this->retryCount = config('unisender.retry_count');
        $this->retryDelay = config('unisender.retry_delay');
        $this->timeout = config('unisender.timeout');
        $this->defaultSenderName = config('unisender.default_sender_name');
        $this->defaultSenderEmail = config('unisender.default_sender_email');
        $this->defaultSenderPhone = config('unisender.default_sender_phone');
    }


    /* ========== Основной метод запроса ============ */

    /**
     * Выполняет запрос к API UniSender
     *
     * @param string $method Название метода API
     * @param array $params Параметры запроса
     * @return array
     * @throws \Exception
     */
    protected function callApi(string $method, array $params = []): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->retry($this->retryCount, $this->retryDelay)
                ->asForm()
                ->post($this->apiUrl . $method, array_merge([
                    'format' => 'json',
                    'api_key' => $this->apiKey,
                ], $params));

            return $response->throw()->json();
        } catch (RequestException $e) {
            throw new \Exception(
                "Ошибка {$method}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Получает результат выполнения асинхронной задачи.
     * Метод универсальный и подходит для методов, где используется подготовка данных.
     * В параметре возвращается название метода, по которому выполняется подготовка задания.
     *
     * @param string $taskUuid Уникальный идентификатор задачи (task_uuid).
     * @return array [
     *     'result' => [
     *         'status' => string,         // Статус рассылки
     *         'creation_time' => string,  // Дата создания (ГГГГ-ММ-ДД чч:мм:сс)
     *         'start_time' => string      // Время начала рассылки
     *     ]
     * ]
     *
     * @throws \Exception
     */
    public function getTaskResult(string $taskUuid): array
    {
        return $this->callApi('async/getTaskResult', [
            'task_uuid' => $taskUuid,
        ]);
    }


    /* ========== Методы: получение статистики ============== */

    /**
     * Получает общую статистику по email-рассылки
     *
     * @param int $campaignId Идентификатор рассылки (обязательный)
     *
     * @return array [
     *     'result' => [
     *         'total' => int,          // Всего адресов в рассылке
     *         'sent' => int,           // Отправлено писем
     *         'delivered' => int,      // Доставлено писем
     *         'read_unique' => int,    // Уникальных прочтений
     *         'read_all' => int,       // Всего прочтений
     *         'clicked_unique' => int, // Уникальных кликов
     *         'clicked_all' => int,    // Всего кликов
     *         'unsubscribed' => int,   // Отписок
     *         'spam' => int            // Жалоб на спам
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getCampaignCommonStats(int $campaignId): array
    {
        $validator = Validator::make(
            ['campaign_id' => $campaignId],
            [
                'campaign_id' => ['required', 'integer', 'min:1'],
            ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        return $this->callApi('getCampaignCommonStats', [
            'campaign_id' => $campaignId,
        ]);
    }


    /**
     * Запрашивает статистику доставки email-рассылки (асинхронный метод)
     * Для получения результата данного метода, запрашивать getTaskResult
     *
     * @param int $campaignId           Идентификатор рассылки (обязательный)
     * @param array $options            Дополнительные параметры:
     *     - 'notify_url' => string         URL для callback-уведомления
     *     - 'changed_since' => string      Дата в формате 'ГГГГ-ММ-ДД чч:мм:сс' (UTC)
     *     - 'field_ids' => array           Массив ID дополнительных полей
     *
     * @return array [
     *     'result' => [
     *         'task_uuid' => string,  // UUID задачи
     *         'status' => string      // Статус задачи (new/processing/completed)
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getCampaignDeliveryStats(int $campaignId, array $options = []): array
    {
        $validator = Validator::make(
            ['campaign_id' => $campaignId] + $options,
            [
                'campaign_id' => ['required', 'integer', 'min:1'],
                'notify_url' => ['nullable', 'url'],
                'changed_since' => ['nullable', 'date_format:Y-m-d H:i:s'],
                'field_ids' => ['nullable', 'array'],
                'field_ids.*' => ['nullable', 'integer', 'min:1'],
            ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = ['campaign_id' => $campaignId];

        if (isset($options['notify_url'])) {
            $params['notify_url'] = $options['notify_url'];
        }

        if (isset($options['changed_since'])) {
            $params['changed_since'] = $options['changed_since'];
        }

        if (isset($options['field_ids']) && is_array($options['field_ids'])) {
            foreach ($options['field_ids'] as $fieldId) {
                if (!is_numeric($fieldId) || $fieldId <= 0) {
                    throw new \InvalidArgumentException('field_ids дополнительных полей должны быть положительными числами');
                }
                $params['field_ids[]'] = $fieldId;
            }
        }

        //асинхронный вызов
        $response = $this->callApi('async/getCampaignDeliveryStats', $params);

        if (!isset($response['result']['task_uuid'])) {
            throw new \Exception('Не удалось создать задачу для получения статистики');
        }

        return $response;
    }


    /**
     * Получает текущий статус email-рассылки
     *
     * @param int $campaignId Идентификатор рассылки (обязательный)
     *
     * @return array [
     *     'result' => [
     *         'status' => string,         // Статус рассылки
     *         'creation_time' => string,  // Дата создания (ГГГГ-ММ-ДД чч:мм:сс)
     *         'start_time' => string      // Время начала рассылки
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getCampaignStatus(int $campaignId): array
    {
        $validator = Validator::make(
            ['campaign_id' => $campaignId],
            [
                'campaign_id' => ['required', 'integer', 'min:1'],
            ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        return $this->callApi('getCampaignStatus', [
            'campaign_id' => $campaignId
        ]);
    }


    /**
     * Получает список сообщений за указанный период
     *
     * @param string $dateFrom      Начальная дата периода в формате 'Y-m-d H:i' (UTC, обязательно)
     * @param string $dateTo        Конечная дата периода в формате 'Y-m-d H:i' (UTC, обязательно)
     * @param array $options        Дополнительные параметры:
     *     - 'format' => string         Формат вывода (json|html), по умолчанию 'json'
     *     - 'limit' => int             Лимит записей (1-100), по умолчанию 50
     *     - 'offset' => int            Смещение выборки (0+), по умолчанию 0
     *
     * @return array [
     *     'result' => array[
     *         [
     *             'id' => int,                         // ID сообщения
     *             'sub_user_login' => string,          // Логин подпользователя
     *             'list_id' => int,                    // ID списка
     *             'segment_id' => int|null,            // ID сегмента
     *             'created' => string,                 // Дата создания
     *             'updated' => string,                 // Дата обновления
     *             'service_type' => string,            // Тип сообщения (email/sms)
     *             'active_version_id' => int|null,     // ID активной версии
     *             'lang_code' => string,               // Код языка
     *             'sender_email' => string,            // Email отправителя
     *             'sender_name' => string,             // Имя отправителя
     *             'subject' => string,                 // Тема сообщения
     *             'body' => string,                    // Тело сообщения
     *             'message_format' => string           // Формат сообщения
     *         ]
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getMessages(string $dateFrom, string $dateTo, array $options = []): array
    {
        $validator = Validator::make(
            [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ] + $options,
            [
                'date_from' => ['required', 'date_format:Y-m-d H:i'],
                'date_to' => ['required', 'date_format:Y-m-d H:i'],
                'format' => ['nullable', 'in:json,html'],
                'limit' => ['nullable', 'integer', 'between:1,100'],
                'offset' => ['nullable', 'integer', 'min:0'],
            ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        if (isset($options['format'])) {
            $params['format'] = strtolower($options['format']);
        }

        if (isset($options['limit'])) {
            $params['limit'] = (int)$options['limit'];
        }

        if (isset($options['offset'])) {
            $params['offset'] = (int)$options['offset'];
        }

        return $this->callApi('getMessages', $params);
    }


    /**
     * Получает статистику посещенных ссылок по email-рассылки
     *
     * @param int $campaignId   Идентификатор рассылки (обязательный)
     * @param bool $group       Группировать результаты по ссылкам (по умолчанию true)
     *
     * @return array [
     *     'result' => [
     *         'fields' => string[],  // Названия полей в данных
     *         'data' => array[       // Массив с данными о переходах
     *             [email, url, request_time, ip, count?]
     *         ]
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getVisitedLinks(int $campaignId, bool $group = true): array
    {
        $validator = Validator::make(
            ['campaign_id' => $campaignId, 'group' => $group],
            [
                'campaign_id' => ['required', 'integer', 'min:1'],
                'group' => ['nullable', 'boolean'],
            ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        return $this->callApi('getVisitedLinks', [
            'campaign_id' => $campaignId,
            'group' => $group ? 1 : 0,
        ]);
    }


    /**
     * Получает список сообщений за указанный период
     *
     * @param string $dateFrom Начальная дата в формате 'ГГГГ-ММ-ДД чч:мм' (UTC, обязательно)
     * @param string $dateTo Конечная дата в формате 'ГГГГ-ММ-ДД чч:мм' (UTC, обязательно)
     * @param array $options Дополнительные параметры:
     *     - 'format' => string         Формат вывода (json|html), по умолчанию 'json'
     *     - 'limit' => int             Лимит записей (1-100), по умолчанию 50
     *     - 'offset' => int            Смещение выборки (0+), по умолчанию 0
     *
     * @return array [
     *     'result' => array[
     *         [
     *             'id' => int,                // ID сообщения
     *             'login' => string,          // Логин пользователя
     *             'sub_user_login' => string, // Логин подпользователя
     *             'list_id' => int,           // ID списка
     *             'segment_id' => int|null,   // ID сегмента
     *             'lang_code' => string,      // Код языка
     *             'created' => string,        // Дата создания
     *             'updated' => string,        // Дата обновления
     *             'service_type' => string,   // Тип сообщения (email/sms)
     *             'message_format' => string, // Формат сообщения
     *             'sender_email' => string,   // Email отправителя
     *             'sender_name' => string,    // Имя отправителя
     *             'subject' => string         // Тема сообщения
     *         ]
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function listMessages(string $dateFrom, string $dateTo, array $options = []): array
    {
        $validator = Validator::make(
            [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ] + $options,
            [
                'date_from' => ['required', 'date_format:Y-m-d H:i'],
                'date_to' => ['required', 'date_format:Y-m-d H:i'],
                'format' => ['nullable', 'in:json,html'],
                'limit' => ['nullable', 'integer', 'between:1,100'],
                'offset' => ['nullable', 'integer', 'min:0'],
            ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        if (isset($options['format'])) {
            $params['format'] = strtolower($options['format']);
        }

        if (isset($options['limit'])) {
            $params['limit'] = (int)$options['limit'];
        }

        if (isset($options['offset'])) {
            $params['offset'] = (int)$options['offset'];
        }

        return $this->callApi('listMessages', $params);
    }


    /**
     * Получает список рассылок с возможностью фильтрации по дате и пагинацией
     *
     * @param array $options Параметры запроса:
     *     - 'from' => string       Начальная дата в формате 'ГГГГ-ММ-ДД чч:мм:сс' (UTC)
     *     - 'to' => string         Конечная дата в формате 'ГГГГ-ММ-ДД чч:мм:сс' (UTC)
     *     - 'limit' => int         Лимит записей (1-10000), по умолчанию 1000
     *     - 'offset' => int        Смещение выборки (0+), по умолчанию 0
     *
     * @return array [
     *     'result' => array[
     *         [
     *             'id' => int,             // ID рассылки
     *             'start_time' => string,  // Время старта
     *             'status' => string,      // Статус рассылки
     *             'message_id' => int,     // ID сообщения
     *             'list_id' => int,        // ID списка
     *             'subject' => string,     // Тема письма
     *             'sender_name' => string, // Имя отправителя
     *             'sender_email' => string,// Email отправителя
     *             'stats_url' => string    // URL статистики
     *         ]
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getCampaigns(array $options = []): array
    {
        $validator = Validator::make(
            $options,
            [
                'from' => ['nullable', 'date_format:Y-m-d H:i:s'],
                'to' => ['nullable', 'date_format:Y-m-d H:i:s'],
                'limit' => ['nullable', 'integer', 'between:1,10000'],
                'offset' => ['nullable', 'integer', 'min:0'],
            ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [];

        if (isset($options['from'])) {
            $params['from'] = $options['from'];
        }

        if (isset($options['to'])) {
            $params['to'] = $options['to'];
        }

        if (isset($options['limit'])) {
            $params['limit'] = (int)$options['limit'];
        }

        if (isset($options['offset'])) {
            $params['offset'] = (int)$options['offset'];
        }

        return $this->callApi('getCampaigns', $params);
    }


    /**
     * Получает информацию о сообщении по его ID
     *
     * @param int|array $messageId ID сообщения (или массив ID)
     * @return array [
     *     'result' => array[
     *         [
     *             'id' => string|int,              // ID сообщения
     *             'sub_user_login' => string|null, // Логин подпользователя
     *             'list_id' => string|int,         // ID списка
     *             'created' => string,             // Дата создания
     *             'last_update' => string,         // Дата последнего обновления
     *             'service_type' => string,        // Тип сообщения (email/sms)
     *             'lang_code' => string,           // Код языка
     *             'active_version_id' => int|null, // ID активной версии
     *             'message_format' => string,      // Формат сообщения
     *             'wrap_type' => string,           // Тип обертки
     *             'images_behavior' => string,     // Поведение изображений
     *             'sender_email' => string,        // Email отправителя
     *             'sender_name' => string,         // Имя отправителя
     *             'subject' => string,             // Тема сообщения
     *             'body' => string,                // HTML-содержимое
     *             'text_body' => string            // Текстовая версия
     *         ]
     *     ]
     * ]
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getMessage(int|array $messageId): array
    {
        $ids = is_array($messageId) ? $messageId : [$messageId];

        $validator = Validator::make(
            ['ids' => $ids],
            [
                'ids.*' => ['required', 'integer', 'min:1', 'max:2147483647'], // 31-битное положительное число
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'id' => implode(',', $ids),
        ];

        return $this->callApi('getMessage', $params);
    }


    /* ========== Методы: работа со списками контактов ============== */

    /**
     * Создает новый список контактов
     *
     * @param string $title Название списка (максимум 255 символов)
     * @param array $options {
     * @return array [
     *     'result' => [
     *         'id' => int,    // ID созданного списка
     *         'title' => string
     *     ]
     * ]
     *
     * @var string $before_subscribe_url URL для обработки перед подпиской
     * @var string $after_subscribe_url URL для обработки после подписки
     * }
     * @throws \InvalidArgumentException*
     * @throws \Exception
     */
    public function createList(string $title, array $options = []): array
    {
        $validator = Validator::make(
            ['title' => $title] + $options,
            [
                'title' => ['required', 'string', 'max:255'],
                'before_subscribe_url' => ['nullable', 'url'],
                'after_subscribe_url' => ['nullable', 'url'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = ['title' => $title];

        if (!empty($options['before_subscribe_url'])) {
            $params['before_subscribe_url'] = $options['before_subscribe_url'];
        }

        if (!empty($options['after_subscribe_url'])) {
            $params['after_subscribe_url'] = $options['after_subscribe_url'];
        }

        return $this->callApi('createList', $params);
    }


    /**
     * Удаляет список рассылки
     *
     * @param int $listId ID списка для удаления
     *
     * @return array [
     *     'result' => [
     *         'id' => int,      // ID удаленного списка
     *         'deleted' => int  // 1 при успешном удалении
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function deleteList(int $listId): array
    {
        return $this->callApi('deleteList', [
            'list_id' => $listId
        ]);
    }

    /**
     * Исключает контакт из списков рассылки
     *
     * @param string $contactType Тип контакта ('email' или 'phone')
     * @param string $contact Email или телефон для исключения
     * @param string|array|null $listIds ID списков (если не указаны, исключаем из всех списков)
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function exclude(string $contactType, string $contact, string|array|null $listIds = null): array
    {
        $validator = Validator::make(
            [
                'contact_type' => $contactType,
                'contact' => $contact,
            ] + ['list_ids' => $listIds],
            [
                'contact_type' => ['required', 'in:email,phone'],
                'contact' => [
                    'required',
                    function ($attribute, $value, $fail) use ($contactType) {
                        if ($contactType === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $fail('Некорректный email формат.');
                        }
                    },
                ],
                'list_ids' => ['nullable', 'array'],
                'list_ids.*' => ['nullable', 'integer', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'contact_type' => $contactType,
            'contact' => $contact,
        ];

        if ($listIds !== null) {
            $params['list_ids'] = is_array($listIds) ? implode(',', $listIds) : $listIds;
        }

        return $this->callApi('exclude', $params);
    }

    /**
     * Экспортирует контакты из указанного списка
     *
     * @param int $listId ID списка для экспорта (обязательный)
     * @param array $fieldNames Массив полей для экспорта (обязательный)
     * @param array $options Дополнительные параметры:
     *     - 'offset' => int Смещение (default: 0)
     *     - 'limit' => int Лимит записей (max: 5000, default: 100)
     *     - 'email_status' => string Статус email (active/inactive/unsubscribed/blocked/invalid)
     *     - 'phone_status' => string Статус телефона (аналогично email_status)
     *
     * @return array [
     *     'result' => [
     *         'total' => int,    // Общее количество контактов
     *         'fields' => array, // Названия полей
     *         'data' => array    // Массив контактов
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function exportContacts(int $listId, array $fieldNames, array $options = []): array
    {
        $validator = Validator::make(
            ['list_id' => $listId, 'field_names' => $fieldNames] + $options,
            [
                'list_id' => ['required', 'integer', 'min:1'],
                'field_names' => ['required', 'array', 'min:1'],
                'field_names.*' => ['required', 'string'], // Каждое поле должно быть строкой
                'offset' => ['nullable', 'integer', 'min:0'],
                'limit' => ['nullable', 'integer', 'between:1,5000'],
                'email_status' => ['nullable', Rule::in(['active', 'inactive', 'unsubscribed', 'blocked', 'invalid'])],
                'phone_status' => ['nullable', Rule::in(['active', 'inactive', 'unsubscribed', 'blocked', 'invalid'])],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'list_id' => $listId,
            'field_names' => implode(',', $fieldNames),
            'offset' => $options['offset'] ?? 0,
            'limit' => $options['limit'] ?? 100,
        ];

        if (isset($options['email_status'])) {
            $params['email_status'] = $options['email_status'];
        }

        if (isset($options['phone_status'])) {
            $params['phone_status'] = $options['phone_status'];
        }

        return $this->callApi('exportContacts', $params);
    }

    /**
     * Получает количество контактов в списке с возможностью фильтрации
     *
     * @param int $listId ID списка для поиска
     * @param array $params Параметры фильтрации (минимум один):
     *     - 'tagId' => int    ID тега (из getTags)
     *     - 'type' => string  Тип контактов ('address' или 'phone')
     *     - 'search' => string Поиск по подстроке (только с указанным type)
     *
     * @return array [
     *     'result' => [
     *         'list_id' => int,
     *         'count' => int    // Общее количество
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getContactCount(int $listId, array $params = []): array
    {
        $validator = Validator::make(
            ['list_id' => $listId, 'params' => $params],
            [
                'list_id' => ['required', 'integer', 'min:1'],
                'params' => ['required', 'array', 'min:1'],
                'params.tagId' => ['nullable', 'integer', 'min:1'],
                'params.type' => ['nullable', Rule::in(['address', 'phone'])],
                'params.search' => ['nullable', 'string', 'min:1', function ($attribute, $value, $fail) use ($params) {
                    if (isset($params['type']) && empty($value)) {
                        $fail('search не может быть пустым при указанном type');
                    }
                }],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $apiParams = ['list_id' => $listId];

        foreach ($params as $key => $value) {
            $apiParams["params[{$key}]"] = $value;
        }

        return $this->callApi('getContactCount', $apiParams);
    }


    /**
     * Получает список всех рассылок аккаунта
     *
     * @return array [
     *     'result' => [
     *         [
     *             'id' => int,
     *             'title' => string,
     *             'description' => string,
     *             'created' => \Carbon\Carbon,
     *             'members_count' => int
     *         ],
     *         ...
     *     ]
     * ]
     *
     * @throws \Exception
     */
    public function getLists(): array
    {
        return $this->callApi('getLists');
    }


    /**
     * Получает общее количество контактов в аккаунте
     *
     * @param string $login Логин пользователя в системе (обязательный)
     *
     * @return array [
     *     'result' => [
     *         'total' => int    // Общее количество контактов
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getTotalContactsCount(string $login): array
    {
        $validator = Validator::make(
            ['login' => $login],
            [
                'login' => ['required', 'string', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        return $this->callApi('getTotalContactsCount', [
            'login' => $login,
        ]);
    }


    /**
     * Импортирует или обновляет контакты с комплексной валидацией
     *
     * @param array $fieldNames Массив названий полей (min 1, max 50 полей)
     * @param array $data Массив данных контактов (max 10000 записей)
     * @param array $options {
     *     @var bool $overwriteTags Перезапись тегов (default: false)
     *     @var bool $overwriteLists Перезапись списков (default: false)
     * }
     *
     * @return array
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function importContacts(array $fieldNames, array $data, array $options = []): array
    {
        $validator = Validator::make(
            [
                'field_names' => $fieldNames,
                'data' => $data,
            ],
            [
                'field_names' => ['required', 'array', 'min:1', 'max:50'],
                'data' => ['required', 'array', 'min:1', 'max:10000'],
                'data.*' => ['required', 'array', 'size:' . count($fieldNames)],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $hasEmail = in_array('email', $fieldNames);
        $hasPhone = in_array('phone', $fieldNames);

        if (!$hasEmail && !$hasPhone) {
            throw new \InvalidArgumentException('Необходимо указать поле email или phone');
        }

        $expectedFieldCount = count($fieldNames);
        foreach ($data as $i => $row) {
            if (count($row) !== $expectedFieldCount) {
                throw new \InvalidArgumentException(
                    sprintf('Строка %d содержит %d полей вместо %d',
                        $i + 1, count($row), $expectedFieldCount)
                );
            }
        }

        foreach ($fieldNames as $field) {
            $this->validateFieldImportContacts($field);
        }

        foreach ($data as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $fieldName = $fieldNames[$colIndex];
                $this->validateFieldValueImportContacts($fieldName, $value, $rowIndex, $colIndex);
            }
        }

        $params = [
            'field_names' => $fieldNames,
            'data' => $data,
            'overwrite_tags' => $options['overwrite_tags'] ? 1 : 0,
            'overwrite_lists' => $options['overwrite_lists'] ? 1 : 0
        ];

        return $this->callApi('importContacts', $params);
    }


    /**
     * Подписывает контакт на указанные списки рассылки, а также позволяет добавить/поменять значения дополнительных полей и меток
     *
     * @param array $fields             Ассоциативный массив полей контакта:
     *                                      - Обязательные: 'email' или 'phone'
     *                                      - Дополнительные: 'name' и другие кастомные поля
     * @param string|array $listIds     ID списков (массив или строка через запятую)
     * @param array $options {
     *     @var string $tags            Теги через запятую (макс. 10)
     *     @var int $double_optin       Режим подтверждения (0, 3 или 4)
     *     @var int $overwrite          Режим перезаписи (0, 1 или 2)
     * }
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function subscribe(array $fields, string|array $listIds, array $options = []): array
    {
        $validator = Validator::make(
            [
                'fields' => $fields,
                'list_ids' => is_array($listIds) ? implode(',', $listIds) : $listIds,
                'tags' => $options['tags'] ?? null,
                'double_optin' => $options['double_optin'] ?? null,
                'overwrite' => $options['overwrite'] ?? null,
            ],
            [
                'fields' => ['required', 'array'],
                'fields.email' => ['nullable', 'email'],
                'fields.phone' => ['nullable', 'regex:/^\+?\d{10,15}$/'],
                'list_ids' => ['required', 'string', 'regex:/^\d+(,\d+)*$/'],
                'tags' => ['nullable', 'string', 'max:255'],
                'double_optin' => ['nullable', Rule::in([0, 3, 4])],
                'overwrite' => ['nullable', Rule::in([0, 1, 2])],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        if (!isset($fields['email']) && !isset($fields['phone'])) {
            throw new \InvalidArgumentException('Необходимо указать email или phone');
        }

        if (isset($options['tags'])) {
            $tagsCount = count(explode(',', $options['tags']));
            if ($tagsCount > 10) {
                throw new \InvalidArgumentException('Максимально допустимое количество меток - 10');
            }
        }

        $params = [
            'fields' => $fields,
            'list_ids' => is_array($listIds) ? implode(',', $listIds) : $listIds,
        ];

        $optionalParams = ['tags', 'double_optin', 'overwrite'];
        foreach ($optionalParams as $param) {
            if (isset($options[$param])) {
                $params[$param] = $options[$param];
            }
        }

        return $this->callApi('subscribe', $params);
    }


    /**
     * Отписывает контакт от указанных списков рассылки
     *
     * @param string $contactType           Тип контакта ('email' или 'phone')
     * @param string $contact               Email или телефон для отписки
     * @param string|array|null $listIds    ID списков (необязательно)
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function unsubscribe(string $contactType, string $contact, string|array|null $listIds = null): array
    {
        $validator = Validator::make(
            [
                'contact_type' => $contactType,
                'contact' => $contact,
                'list_ids' => $listIds,
            ],
            [
                'contact_type' => ['required', Rule::in(['email', 'phone'])],
                'contact' => [
                    'required',
                    function ($attribute, $value, $fail) use ($contactType) {
                        if ($contactType === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $fail('Некорректный формат email');
                        }
                    },
                ],
                'list_ids' => ['nullable', 'array'],
                'list_ids.*' => ['nullable', 'integer', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'contact_type' => $contactType,
            'contact' => $contact,
        ];

        if ($listIds !== null) {
            $params['list_ids'] = is_array($listIds) ? implode(',', $listIds) : $listIds;
        }

        return $this->callApi('unsubscribe', $params);
    }


    /**
     * Обновляет параметры существующего списка рассылки
     *
     * @param int $listId                       ID списка для обновления
     * @param array $options {
     *     @var string $title                   Новое название списка
     *     @var string $before_subscribe_url    Новый URL перед подпиской
     *     @var string $after_subscribe_url     Новый URL после подписки
     * }
     *
     * @return array [
     *     'result' => [
     *         'id' => int,      // ID обновленного списка
     *         'updated' => int  // 1 при успешном обновлении
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function updateList(int $listId, array $options = []): array
    {
        $validator = Validator::make(
            ['list_id' => $listId] + $options,
            [
                'list_id' => ['required', 'integer', 'min:1'],
                'title' => ['nullable', 'string', 'max:255'],
                'before_subscribe_url' => ['nullable', 'url'],
                'after_subscribe_url' => ['nullable', 'url'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = ['list_id' => $listId];

        foreach ($options as $key => $value) {
            $params[$key] = $value;
        }

        return $this->callApi('updateList', $params);
    }


    /**
     * Проверяет наличие email в указанных списках
     *
     * @param string $email Email для проверки
     * @param array|string $listIds ID списков (массив или строка через запятую)
     * @param string $condition Условие проверки ('or' или 'and')
     *
     * @return array [
     *     'result' => [
     *         'result' => bool     // Итоговый результат по условию
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function isContactInLists(string $email, array|string $listIds, string $condition = 'or'): array
    {
        $validator = Validator::make(
            [
                'email' => $email,
                'list_ids' => $listIds,
                'condition' => $condition,
            ],
            [
                'email' => ['required', 'email'],
                'list_ids' => ['required', 'array'],
                'list_ids.*' => ['integer', 'min:1'],
                'condition' => ['required', Rule::in(['or', 'and'])],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'email' => $email,
            'list_ids' => is_array($listIds) ? implode(',', $listIds) : $listIds,
            'condition' => $condition,
        ];

        return $this->callApi('isContactInLists', $params);
    }


    /**
     * Получает информацию о контакте по email
     *
     * @param string $email             Email адрес контакта (обязательный)
     * @param array $options {
     *     @var bool $include_lists     Включить информацию о списках (default: true)
     *     @var bool $include_fields    Включить дополнительные поля (default: true)
     *     @var bool $include_details   Включить детальную информацию (default: false)
     * }
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getContact(string $email, array $options = []): array
    {
        $validator = Validator::make(
            ['email' => $email] + $options,
            [
                'email' => ['required', 'email'],
                'include_lists' => ['nullable', 'boolean'],
                'include_fields' => ['nullable', 'boolean'],
                'include_details' => ['nullable', 'boolean'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'email' => $email,
            'include_lists' => $options['include_lists'] ? 1 : 0,
            'include_fields' => $options['include_fields'] ? 1 : 0,
            'include_details' => $options['include_details'] ? 1 : 0,
        ];

        return $this->callApi('getContact', $params);
    }


    /* ========== Методы: создание и отправка сообщений ============= */

    /**
     * Отменяет запланированную или запущенную рассылку
     *
     * @param int $campaignId ID рассылки для отмены
     *
     * @return array [
     *     'result' => array
     * ]
     * @throws \Exception
     */
    public function cancelCampaign(int $campaignId): array
    {
        return $this->callApi('cancelCampaign', ['campaign_id' => $campaignId]);
    }


    /**
     * Проверяет статус отправки email-сообщений
     *
     * @param int|array $emailIds   ID сообщений (массив или строка с ID через запятую)
     *                              Максимум 500 ID за один запрос
     *
     * @return array [
     *     'statuses' => array[
     *         [
     *             'id' => int,       // ID сообщения
     *             'status' => string  // Статус доставки
     *         ]
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function checkEmail(int|array $emailIds): array
    {
        $ids = is_array($emailIds)
            ? $emailIds
            : explode(',', str_replace(' ', '', $emailIds));
        $ids = array_map('intval', $ids);

        $validator = Validator::make(
            ['email_ids' => $ids],
            [
                'email_ids' => ['required', 'array', 'max:500'],
                'email_ids.*' => ['required', 'integer', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'email_id' => implode(',', $ids),
        ];

        return $this->callApi('checkEmail', $params);
    }


    /**
     * Проверяет статус доставки SMS-сообщения
     *
     * @param int|string $smsId ID SMS-сообщения, полученное из метода sendSms
     *
     * @return array [
     *     'status' => string  // Статус доставки SMS
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function checkSms(int|string $smsId): array
    {
        $validator = Validator::make(
            ['sms_id' => $smsId],
            [
                'sms_id' => ['required', 'integer', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'sms_id' => $smsId,
        ];

        return $this->callApi('checkSms', $params);
    }


    /**
     * Создает и запускает рассылку
     *
     * @param int $messageId                ID сообщения (из createEmailMessage/createSmsMessage)
     * @param array $options                Дополнительные параметры:
     *     - 'start_time' => string             Дата запуска в формате 'ГГГГ-ММ-ДД чч:мм'
     *     - 'timezone' => string               Часовой пояс ('UTC')
     *     - 'track_read' => bool               Отслеживание прочтения (для email)
     *     - 'track_links' => bool              Отслеживание ссылок (для email)
     *     - 'contacts' => array|string         Контакты для ограничения рассылки
     *     - 'contacts_url' => string           URL файла с контактами
     *     - 'track_ga' => bool                 Интеграция с Google Analytics
     *     - 'payment_limit' => float           Лимит бюджета
     *     - 'payment_currency' => string       Валюта ('USD')
     *     - 'ga_*' => string                   Параметры Google Analytics
     *
     * @return array [
     *     'result' => [
     *         'campaign_id' => int,
     *         'status' => string,
     *         'count' => int
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createCampaign(int $messageId, array $options = []): array
    {
        $validator = Validator::make(
            ['message_id' => $messageId] + $options,
            [
                'message_id' => ['required', 'integer', 'min:1'],
                'start_time' => ['nullable', 'date', 'after_or_equal:now', 'before_or_equal:' . Carbon::now()->addDays(100)->toDateTimeString()],
                'timezone' => ['nullable', 'string'],
                'contacts' => ['nullable', 'array'],
                'contacts.*' => ['integer', 'min:1'],
                'contacts_url' => ['nullable', 'url'],
                'track_read' => ['nullable', 'boolean'],
                'track_links' => ['nullable', 'boolean'],
                'track_ga' => ['nullable', 'boolean'],
                'payment_limit' => ['nullable', 'integer', 'min:1'],
                'payment_currency' => ['nullable', 'string', 'in:USD,EUR,RUB'],
                'ga_medium' => ['nullable', 'string'],
                'ga_source' => ['nullable', 'string'],
                'ga_campaign' => ['nullable', 'string'],
                'ga_content' => ['nullable', 'string'],
                'ga_term' => ['nullable', 'string'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        if (isset($options['contacts']) && isset($options['contacts_url'])) {
            throw new \InvalidArgumentException("Используйте только contacts или contacts_url");
        }

        $params = [
            'message_id' => $messageId,
            'track_read' => $options['track_read'] ?? false ? 1 : 0,
            'track_links' => $options['track_links'] ?? false ? 1 : 0,
        ];

        $optionalParams = [
            'start_time', 'timezone', 'contacts_url',
            'track_ga', 'payment_limit', 'payment_currency'
        ];

        foreach ($optionalParams as $param) {
            if (isset($options[$param])) {
                $params[$param] = $options[$param];
            }
        }

        if (isset($options['contacts'])) {
            $params['contacts'] = is_array($options['contacts'])
                ? implode(',', $options['contacts'])
                : $options['contacts'];
        }

        if (!empty($options['track_ga'])) {
            $gaParams = ['ga_medium', 'ga_source', 'ga_campaign', 'ga_content', 'ga_term'];
            foreach ($gaParams as $gaParam) {
                if (isset($options[$gaParam])) {
                    $params[$gaParam] = $options[$gaParam];
                }
            }
        }

        return $this->callApi('createCampaign', $params);
    }


    /**
     * Создает email-сообщение для рассылки
     *
     * @param array $params Параметры сообщения:
     *     - 'sender_name' => string        Имя отправителя (обязательно)
     *     - 'sender_email' => string       Email отправителя (обязательно)
     *     - 'subject' => string            Тема письма (обязательно, если нет template_id)
     *     - 'body' => string               HTML-тело письма (обязательно, если нет template_id)
     *     - 'list_id' => int               ID списка рассылки (обязательно)
     *     - 'text_body' => string          Текстовая версия письма (необязательно)
     *     - 'generate_text' => bool        Автогенерация текстовой версии (default: false)
     *     - 'tag' => string                Фильтр по тегу (необязательно)
     *     - 'attachments' => array         Вложения ['filename' => file_content] (необязательно)
     *     - 'lang' => string               Язык ('ru', 'en' и др.) (необязательно)
     *     - 'template_id' => int           ID пользовательского шаблона (необязательно)
     *     - 'system_template_id' => int    ID системного шаблона (необязательно)
     *     - 'wrap_type' => string          Выравнивание ('left', 'right', 'center', 'skip') (необязательно)
     *
     * @return array [
     *     'result' => [
     *         'message_id' => int  // ID созданного сообщения
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createEmailMessage(array $params): array
    {
        $requiredWithoutTemplate = ['sender_name', 'sender_email', 'subject', 'body', 'list_id'];

        $validator = Validator::make($params, [
            'sender_name' => ['nullable', 'string'],
            'sender_email' => ['nullable', 'email'],
            'subject' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'list_id' => ['nullable', 'integer', 'min:1'],
            'text_body' => ['nullable', 'string'],
            'generate_text' => ['nullable', 'boolean'],
            'tag' => ['nullable', 'string'],
            'lang' => ['nullable', 'string'],
            'template_id' => ['nullable', 'integer', 'min:1'],
            'system_template_id' => ['nullable', 'integer', 'min:1'],
            'wrap_type' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['nullable', 'string'],
        ]);

        if (!$validator->fails()) {
            if (!isset($params['template_id']) && !isset($params['system_template_id'])) {
                foreach ($requiredWithoutTemplate as $field) {
                    if (empty($params[$field])) {
                        throw new \InvalidArgumentException("Обязательный параметр {$field} отсутствует");
                    }
                }
            }

            if (!empty($params['attachments']) && is_array($params['attachments'])) {
                foreach ($params['attachments'] as $filename => $content) {
                    if (!preg_match('/^[a-z0-9_\-\.]+$/i', $filename)) {
                        throw new \InvalidArgumentException("Имя файла должно содержать только латинские символы");
                    }
                }
            }
        } else {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $apiParams = [];
        $allowedParams = [
            'sender_name', 'sender_email', 'subject', 'body', 'list_id',
            'text_body', 'generate_text', 'tag', 'lang', 'template_id',
            'system_template_id', 'wrap_type'
        ];

        foreach ($allowedParams as $param) {
            if (isset($params[$param])) {
                $apiParams[$param] = $params[$param];
            }
        }

        if (isset($apiParams['generate_text'])) {
            $apiParams['generate_text'] = $apiParams['generate_text'] ? 1 : 0;
        }

        if (!empty($params['attachments']) && is_array($params['attachments'])) {
            foreach ($params['attachments'] as $filename => $content) {
                $apiParams["attachments[{$filename}]"] = $content;
            }
        }

        return $this->callApi('createEmailMessage', $apiParams);
    }


    /**
     * Создает SMS-сообщение для рассылки
     *
     * @param string $sender        Имя отправителя (3-11 латинских букв/цифр)
     * @param string $body          Текст SMS-сообщения
     * @param int $listId           ID списка для рассылки
     * @param string|null $tag      Фильтр по метке (необязательно)
     *
     * @return array [
     *     'result' => [
     *         'message_id' => int  // ID созданного SMS-сообщения
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createSmsMessage(string $sender, string $body, int $listId, ?string $tag = null): array
    {
        $validator = Validator::make(
            [
                'sender' => $sender,
                'body' => $body,
                'list_id' => $listId,
                'tag' => $tag,
            ],
            [
                'sender' => ['required', 'string', 'regex:/^[a-z0-9]{3,11}$/i'],
                'body' => ['required', 'string', 'min:1'],
                'list_id' => ['required', 'integer', 'min:1'],
                'tag' => ['nullable', 'string'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'sender' => $sender,
            'body' => $body,
            'list_id' => $listId,
        ];

        if ($tag !== null) {
            $params['tag'] = $tag;
        }

        return $this->callApi('createSmsMessage', $params);
    }


    /**
     * Удаляет сообщение (email или SMS) из системы
     *
     * @param int $messageId Идентификатор сообщения для удаления
     *
     * @return array [
     *     'result' => array  // Пустой массив при успешном удалении
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function deleteMessage(int $messageId): array
    {
        $validator = Validator::make(
            ['message_id' => $messageId],
            ['message_id' => ['required', 'integer', 'min:1']],
            [
                'message_id.required' => 'messageId обязателен',
                'message_id.integer' => 'messageId должен быть целым числом',
                'message_id.min' => 'messageId должен быть положительным числом',
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = ['message_id' => $messageId];

        return $this->callApi('deleteMessage', $params);
    }


    /**
     * Получает ID актуальной версии письма
     *
     * @param int $messageId ID сообщения (из createEmailMessage/createSmsMessage)
     *
     * @return array [
     *     'result' => [
     *         'message_id' => int,
     *         'actual_version_id' => int
     *     ]
     * ]
     *
     * @throws \Exception
     */
    public function getActualMessageVersion(int $messageId): array
    {
        return $this->callApi('getActualMessageVersion', ['message_id' => $messageId]);
    }


    /**
     * Получает веб-версию письма существующей рассылки
     *
     * @param int $campaignId       Идентификатор рассылки
     * @param string $format        Формат вывода (json|html), по умолчанию 'json'
     *
     * @return array [
     *     'result' => [
     *         'letter_id' => int,          // ID письма
     *         'web_letter_link' => string  // Ссылка на веб-версию письма
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getWebVersion(int $campaignId, string $format = 'json'): array
    {
        $validator = Validator::make(
            ['campaign_id' => $campaignId, 'format' => $format],
            [
                'campaign_id' => ['required', 'integer', 'min:1'],
                'format' => ['required', Rule::in(['json', 'html'])],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'campaign_id' => $campaignId,
            'format' => $format,
        ];

        return $this->callApi('getWebVersion', $params);
    }


    /**
     * Отправляет индивидуальное email-сообщение
     *
     * @param string $email             Адрес получателя в формате "Имя <email@example.com>"
     * @param string $senderName        Имя отправителя
     * @param string $senderEmail       Email отправителя (должен быть предварительно подтвержден)
     * @param string $subject           Тема письма
     * @param string $body              HTML-содержимое письма (только содержимое тега body)
     * @param int $listId               ID списка для управления подпиской
     * @param array $options            Дополнительные параметры:
     *     - 'attachments' => array         Ассоциативный массив вложений ['имя_файла' => содержимое]
     *     - 'lang' => string               Язык для ссылки отписки (ru, en, ua и др.)
     *     - 'track_read' => bool           Отслеживать прочтение (по умолчанию false)
     *     - 'track_links' => bool          Отслеживать переходы по ссылкам (по умолчанию false)
     *     - 'cc' => string                 Email для копии письма
     *     - 'headers' => array             Заголовки письма ['Reply-To' => 'email@example.com', 'Priority' => 'normal']
     *     - 'images_as' => string          Режим обработки изображений ('attachments', 'only_links', 'user_default')
     *     - 'ref_key' => string            Уникальный идентификатор письма
     *     - 'error_checking' => bool       Проверять ошибки перед отправкой (рекомендуется true)
     *     - 'metadata' => array            Метаданные для webhooks ['ключ' => 'значение']
     *
     * @return array [
     *     'result' => array[
     *         [
     *             'index' => int,
     *             'email' => string,
     *             'errors' => array[
     *                 [
     *                     'code' => string,
     *                     'message' => string
     *                 ]
     *             ]
     *         ]
     *     ]
     * ]
     *
     * @throws \Exception
     */
    public function sendEmail(string $email, string $senderName, string $senderEmail, string $subject, string $body, int $listId, array $options = []): array
    {
        $validator = Validator::make(
            [
                'email' => $email,
                'sender_name' => $senderName,
                'sender_email' => $senderEmail,
                'subject' => $subject,
                'body' => $body,
                'list_id' => $listId,
                'attachments' => $options['attachments'] ?? null,
                'metadata' => $options['metadata'] ?? null,
                'headers' => $options['headers'] ?? null,
            ],
            [
                'email' => ['required', 'email'],
                'sender_name' => ['required', 'string'],
                'sender_email' => ['required', 'email'],
                'subject' => ['required', 'string'],
                'body' => ['required', 'string'],
                'list_id' => ['required', 'integer', 'min:1'],
                'attachments' => ['nullable', 'array'],
                'attachments.*' => ['nullable', 'string'],
                'metadata' => ['nullable', 'array'],
                'metadata.*' => ['nullable', 'string'],
                'headers' => ['nullable', 'array'],
                'headers.*' => ['nullable', 'string'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'email' => $email,
            'sender_name' => $senderName,
            'sender_email' => $senderEmail,
            'subject' => $subject,
            'body' => $body,
            'list_id' => $listId,
        ];

        if (isset($options['attachments']) && is_array($options['attachments'])) {
            foreach ($options['attachments'] as $filename => $content) {
                $params["attachments[$filename]"] = $content;
            }
            unset($options['attachments']);
        }

        if (isset($options['metadata']) && is_array($options['metadata'])) {
            foreach ($options['metadata'] as $key => $value) {
                $params["metadata[$key]"] = $value;
            }
            unset($options['metadata']);
        }

        if (isset($options['headers']) && is_array($options['headers'])) {
            $headers = [];
            foreach ($options['headers'] as $name => $value) {
                $headers[] = "$name: $value";
            }
            $params['headers'] = implode("\n", $headers);
            unset($options['headers']);
        }

        $params = array_merge($params, $options);

        return $this->callApi('sendEmail', $params);
    }


    /**
     * Отправляет SMS сообщение одному или нескольким получателям
     *
     * @param string|array $phones Номер(а) получателей в международном формате (можно без "+")
     *                                      Формат: "79991234567" или ["79991234567", "79007654321"]
     * @param string $sender Зарегистрированное альфа-имя отправителя (3-11 символов)
     * @param string $text Текст сообщения (до 1000 символов)
     *
     * @return array [
     *     'result' => array|array[] [
     *         'phone' => string,      // Номер телефона
     *         'sms_id' => string,     // Уникальный ID SMS
     *         'price' => float,       // Стоимость отправки
     *         'currency' => string,   // Валюта (RUB, USD и т.д.)
     *         'status' => string      // Статус отправки
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function sendSms(string|array $phones, string $sender, string $text): array
    {
        $validator = Validator::make(
            ['phones' => $phones, 'sender' => $sender, 'text' => $text],
            [
                'phones' => ['required', 'array', 'max:150'],
                'phones.*' => ['required', 'string', 'regex:/^\+?\d+$/'],
                'sender' => ['required', 'string', 'regex:/^[a-z0-9]{3,11}$/i'],
                'text' => ['required', 'string', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $phoneList = is_array($phones) ? $phones : [$phones];
        $phoneList = array_map(function ($phone) {
            return ltrim($phone, '+');
        }, $phoneList);

        $params = [
            'phone' => implode(',', $phoneList),
            'sender' => $sender,
            'text' => $text,
        ];

        return $this->callApi('sendSms', $params);
    }


    /**
     * Отправляет тестовое (которое уже в системе) email-сообщение на указанные адреса
     *
     * @param int $letterId             ID письма, созданного через createEmailMessage
     * @param string|array $emails      Адрес(а) получателей (можно строку с адресами через запятую или массив)
     *
     * @return array [
     *     'message' => string          Сообщение о результате отправки
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function sendTestEmail(int $letterId, string|array $emails): array
    {
        $validator = Validator::make(
            ['letter_id' => $letterId, 'emails' => $emails],
            [
                'letter_id' => ['required', 'integer', 'min:1'],
                'emails' => ['required', 'array'],
                'emails.*' => ['required', 'email'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $emailList = is_array($emails) ? $emails : explode(',', str_replace(' ', '', $emails));
        $emailList = array_map('trim', $emailList);

        $params = [
            'id' => $letterId,
            'email' => implode(',', $emailList),
        ];

        return $this->callApi('sendTestEmail', $params);
    }


    /**
     * Обновляет существующее email-сообщение
     *
     * @param int $messageId            Идентификатор сообщения для редактирования
     * @param array $params             Параметры сообщения:
     *     - 'sender_name' => string        Имя отправителя
     *     - 'sender_email' => string       Email отправителя (должен быть подтвержден)
     *     - 'subject' => string            Тема письма
     *     - 'body' => string               HTML-содержимое письма
     *     - 'list_id' => int               ID списка для рассылки
     *     - 'text_body' => string          Текстовый вариант письма
     *     - 'lang' => string               Язык для ссылки отписки (ru, en, ua и др.)
     *     - 'categories' => string         Категории письма через запятую
     *
     * @return array [
     *     'result' => [
     *         'message_id' => int  // ID обновленного сообщения
     *     ],
     *     'warnings' => array[     // Массив предупреждений (если есть)
     *         ['warning' => string]
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function updateEmailMessage(int $messageId, array $params = []): array
    {
        $validator = Validator::make(
            ['message_id' => $messageId] + $params,
            [
                'message_id' => ['required', 'integer', 'min:1'],
                'sender_name' => ['nullable', 'string', 'min:1'],
                'sender_email' => ['nullable', 'email'],
                'subject' => ['nullable', 'string', 'min:1'],
                'body' => ['nullable', 'string'],
                'list_id' => ['nullable', 'integer', 'min:1'],
                'text_body' => ['nullable', 'string'],
                'lang' => ['nullable', 'string'],
                'categories' => ['nullable', 'array'],
                'categories.*' => ['nullable', 'string'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $requestParams = ['id' => $messageId];

        $optionalParams = ['sender_name', 'sender_email', 'subject', 'body', 'list_id', 'text_body', 'lang', 'categories'];
        foreach ($optionalParams as $param) {
            if (isset($params[$param])) {
                $requestParams[$param] = $params[$param];
            }
        }

        return $this->callApi('updateEmailMessage', $requestParams);
    }


    /**
     * Обновляет письмо двойного подтверждения подписки на рассылку или подтверждение пароля
     *
     * @param string $senderName        Имя отправителя (не должно совпадать с email)
     * @param string $senderEmail       Email отправителя (должен быть подтвержден)
     * @param string $subject           Тема письма (может содержать поля подстановки)
     * @param string $body              HTML-содержимое письма (должно содержать {{ConfirmUrl}})
     * @param int $listId               ID списка для подписки
     *
     * @return array Пустой массив (успешный ответ API)
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function updateOptInEmail(string $senderName, string $senderEmail, string $subject, string $body, int $listId): array
    {
        $validator = Validator::make(
            [
                'sender_name' => $senderName,
                'sender_email' => $senderEmail,
                'subject' => $subject,
                'body' => $body,
                'list_id' => $listId,
            ],
            [
                'sender_name' => ['required', 'string', 'min:1'],
                'sender_email' => ['required', 'email'],
                'subject' => ['required', 'string'],
                'body' => ['required', 'string', function ($attribute, $value, $fail) {
                    if (!str_contains($value, '{{ConfirmUrl}}')) {
                        $fail('body должно содержать {{ConfirmUrl}}');
                    }
                }],
                'list_id' => ['required', 'integer', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->all());
        }

        $params = [
            'sender_name' => $senderName,
            'sender_email' => $senderEmail,
            'subject' => $subject,
            'body' => $body,
            'list_id' => $listId,
        ];

        return $this->callApi('updateOptInEmail', $params);
    }


    /**
     * Получает список доменов отправителей для указанного пользователя
     *
     * @param string $username Логин пользователя в системе (должен соответствовать API-ключу)
     * @param array $options Дополнительные параметры:
     *     - 'format' => string         Формат вывода (json|html), по умолчанию 'json'
     *     - 'domain' => string         Фильтр по названию домена
     *     - 'limit' => int             Количество записей в ответе (1-100), по умолчанию 50
     *     - 'offset' => int            Позиция начала выборки (0+), по умолчанию 0
     *
     * @return array [
     *     'result' => [
     *         'status' => string,  // Статус выполнения ('success'|'error')
     *         'domains' => array[
     *             [
     *                 'Domain' => string,  // Название домена
     *                 'Status' => string,  // Статус домена ('active'|'pending' и др.)
     *                 'key' => string     // Ключ домена
     *             ]
     *         ]
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getSenderDomainList(string $username, array $options = []): array
    {
        $validator = Validator::make(
            ['username' => $username] + $options,
            [
                'username' => ['required', 'string'],
                'format' => ['nullable', Rule::in(['json', 'html'])],
                'domain' => ['nullable', 'string'],
                'limit' => ['nullable', 'integer', 'between:1,100'],
                'offset' => ['nullable', 'integer', 'min:0'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = [
            'username' => $username,
            'format' => $options['format'] ?? 'json',
        ];

        if (isset($options['domain'])) {
            $params['domain'] = $options['domain'];
        }

        if (isset($options['limit'])) {
            $params['limit'] = (int)$options['limit'];
        }

        if (isset($options['offset'])) {
            $params['offset'] = (int)$options['offset'];
        }

        return $this->callApi('getSenderDomainList', $params);
    }


    /* ========== Методы: работа с шаблонами ============= */

    /**
     * Создает новый шаблон email-сообщения
     *
     * @param string $title Название шаблона (обязательно)
     * @param string $subject Тема письма (обязательно)
     * @param string $body HTML-содержимое шаблона (обязательно)
     * @param array $options Дополнительные параметры:
     *     - 'description' => string        Описание шаблона
     *     - 'text_body' => string          Текстовый вариант шаблона
     *     - 'lang' => string               Язык для ссылки отписки (ru, en, ua и др.)
     *
     * @return array [
     *     'result' => [
     *         'template_id' => int  // ID созданного шаблона
     *     ],
     *     'warnings' => array[     // Массив предупреждений (если есть)
     *         ['warning' => string]
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createEmailTemplate(string $title, string $subject, string $body, array $options = []): array
    {
        $validator = Validator::make(
            ['title' => $title, 'subject' => $subject, 'body' => $body] + $options,
            [
                'title' => ['required', 'string', 'min:1'],
                'subject' => ['required', 'string', 'min:1'],
                'body' => ['required', 'string', 'min:1'],
                'description' => ['nullable', 'string'],
                'text_body' => ['nullable', 'string'],
                'lang' => ['nullable', Rule::in(['ru', 'en', 'ua', 'it', 'da', 'de', 'es', 'fr', 'nl', 'pl', 'pt', 'tr'])],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = [
            'title' => $title,
            'subject' => $subject,
            'body' => $body,
        ];

        $optionalParams = ['description', 'text_body', 'lang'];
        foreach ($optionalParams as $param) {
            if (isset($options[$param])) {
                $params[$param] = $options[$param];
            }
        }

        return $this->callApi('createEmailTemplate', $params);
    }


    /**
     * Удаляет шаблон письма
     *
     * @param int $templateId Идентификатор шаблона для удаления
     *
     * @return array [
     *     'result' => array  // Пустой массив при успешном удалении
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function deleteTemplate(int $templateId): array
    {
        $validator = Validator::make(
            ['template_id' => $templateId],
            ['template_id' => ['required', 'integer', 'min:1']]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = ['template_id' => $templateId];

        return $this->callApi('deleteTemplate', $params);
    }


    /**
     * Получает информацию о шаблоне письма
     *
     * @param int|null $templateId ID пользовательского шаблона (обязателен, если не указан systemTemplateId)
     * @param int|null $systemTemplateId ID системного шаблона (обязателен, если не указан templateId)
     * @param string $format Формат вывода (json|html), по умолчанию 'json'
     *
     * @return array [
     *     'result' => [
     *         'id' => string|int,                  // ID шаблона
     *         'sub_user_login' => string,          // Логин подпользователя
     *         'title' => string,                   // Название шаблона
     *         'description' => string,             // Описание шаблона
     *         'lang_code' => string,               // Код языка
     *         'subject' => string,                 // Тема письма
     *         'attachments' => string,             // Вложения
     *         'screenshot_url' => string,          // URL превью шаблона
     *         'fullsize_screenshot_url' => string, // URL полноразмерного превью
     *         'created' => string,                 // Дата создания
     *         'updated' => string,                 // Дата обновления
     *         'message_format' => string,          // Формат сообщения
     *         'type' => string,                    // Тип шаблона (user/system)
     *         'body' => string,                    // HTML-содержимое
     *         'raw_body' => string                 // Исходное содержимое
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getTemplate(?int $templateId = null, ?int $systemTemplateId = null, string $format = 'json'): array
    {
        $validator = Validator::make(
            ['template_id' => $templateId, 'system_template_id' => $systemTemplateId, 'format' => $format],
            [
                'template_id' => ['nullable', 'integer', 'min:1'],
                'system_template_id' => ['nullable', 'integer', 'min:1'],
                'format' => ['required', Rule::in(['json', 'html'])],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        if ($templateId === null && $systemTemplateId === null) {
            throw new \InvalidArgumentException('Необходимо указать либо templateId, либо systemTemplateId');
        }

        $params = ['format' => $format];

        if ($templateId !== null) {
            $params['template_id'] = $templateId;
        } else {
            $params['system_template_id'] = $systemTemplateId;
        }

        return $this->callApi('getTemplate', $params);
    }


    /**
     * Получает список шаблонов писем с возможностью фильтрации
     *
     * @param array $options Параметры запроса:
     *     - 'type' => string           Тип шаблонов (system|user), по умолчанию 'user'
     *     - 'date_from' => string      Дата начала периода в формате 'ГГГГ-ММ-ДД чч:мм' (UTC)
     *     - 'date_to' => string        Дата окончания периода в формате 'ГГГГ-ММ-ДД чч:мм' (UTC)
     *     - 'format' => string         Формат вывода (json|html), по умолчанию 'json'
     *     - 'limit' => int             Количество записей (1-100), по умолчанию 50
     *     - 'offset' => int            Смещение выборки (0+), по умолчанию 0
     *
     * @return array [
     *     'result' => array[
     *         [
     *             'id' => string|int,          // ID шаблона
     *             'title' => string,           // Название шаблона
     *             'description' => string,     // Описание шаблона
     *             'lang_code' => string,       // Код языка
     *             'subject' => string,         // Тема письма
     *             'screenshot_url' => string,  // URL превью шаблона
     *             'created' => string,         // Дата создания
     *             'updated' => string,         // Дата обновления
     *             'message_format' => string,  // Формат сообщения
     *             'type' => string,            // Тип шаблона
     *             'body' => string,            // HTML-содержимое
     *         ],
     *          ...
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     * @throws \Exception
     */
    public function getTemplates(array $options = []): array
    {
        $validator = Validator::make(
            $options,
            [
                'type' => ['nullable', Rule::in(['system', 'user'])],
                'date_from' => ['nullable', 'date_format:Y-m-d H:i'],
                'date_to' => ['nullable', 'date_format:Y-m-d H:i'],
                'format' => ['nullable', Rule::in(['json', 'html'])],
                'limit' => ['nullable', 'integer', 'between:1,100'],
                'offset' => ['nullable', 'integer', 'min:0'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = [];

        if (isset($options['type'])) {
            $params['type'] = $options['type'];
        }

        $dateFields = ['date_from', 'date_to'];
        foreach ($dateFields as $field) {
            if (isset($options[$field])) {
                $params[$field] = $options[$field];
            }
        }

        if (isset($options['format'])) {
            $params['format'] = $options['format'];
        }

        if (isset($options['limit'])) {
            $params['limit'] = (int)$options['limit'];
        }

        if (isset($options['offset'])) {
            $params['offset'] = (int)$options['offset'];
        }

        return $this->callApi('getTemplates', $params);
    }


    /**
     * Получает список шаблонов с возможностью фильтрации и пагинации
     *
     * @param array $options        Параметры запроса:
     *     - 'type' => string           Тип шаблонов (system|user), по умолчанию 'user'
     *     - 'date_from' => string      Начальная дата создания в формате 'Y-m-d H:i' (UTC)
     *     - 'date_to' => string        Конечная дата создания в формате 'Y-m-d H:i' (UTC)
     *     - 'format' => string         Формат вывода (json|html), по умолчанию 'json'
     *     - 'limit' => int             Лимит записей (1-100), по умолчанию 50
     *     - 'offset' => int            Смещение выборки (0+), по умолчанию 0
     *
     * @return array [
     *     'result' => array[
     *         [
     *             'id' => int,                   // ID шаблона
     *             'title' => string,             // Название шаблона
     *             'description' => string,       // Описание шаблона
     *             'lang_code' => string,         // Код языка (ru, en и др.)
     *             'subject' => string,           // Тема письма
     *             'screenshot_url' => string,    // URL превью шаблона
     *             'created' => string,           // Дата создания
     *             'updated' => string,           // Дата обновления
     *             'message_format' => string,    // Формат сообщения
     *             'type' => string,              // Тип шаблона (user/system)
     *             'fullsize_screenshot_url' => string // URL полноразмерного превью
     *         ],
     *          ...
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function listTemplates(array $options = []): array
    {
        $validator = Validator::make(
            $options,
            [
                'type' => ['nullable', 'string', Rule::in(['system', 'user'])],
                'date_from' => ['nullable', 'date_format:Y-m-d H:i'],
                'date_to' => ['nullable', 'date_format:Y-m-d H:i'],
                'format' => ['nullable', 'string', Rule::in(['json', 'html'])],
                'limit' => ['nullable', 'integer', 'between:1,100'],
                'offset' => ['nullable', 'integer', 'min:0'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = [];

        if (isset($options['type'])) {
            $params['type'] = strtolower($options['type']);
        }

        foreach (['date_from', 'date_to'] as $dateField) {
            if (isset($options[$dateField])) {
                $params[$dateField] = $options[$dateField];
            }
        }

        if (isset($options['format'])) {
            $params['format'] = strtolower($options['format']);
        }

        if (isset($options['limit'])) {
            $params['limit'] = (int)$options['limit'];
        }

        if (isset($options['offset'])) {
            $params['offset'] = (int)$options['offset'];
        }

        return $this->callApi('listTemplates', $params);
    }


    /**
     * Обновляет существующий шаблон email-сообщения
     *
     * @param int $templateId Идентификатор шаблона (обязательно)
     * @param array $params Параметры для обновления:
     *     - 'title' => string              Новое название шаблона
     *     - 'subject' => string            Новая тема письма
     *     - 'body' => string               Новое HTML-содержимое
     *     - 'description' => string        Новое описание шаблона
     *     - 'text_body' => string          Новый текстовый вариант
     *     - 'lang' => string               Язык для ссылки отписки (ru, en, ua и др.)
     *
     * @return array [
     *     'result' => array,       // Пустой массив при успешном обновлении
     *     'warnings' => array[     // Массив предупреждений (если есть)
     *         ['warning' => string]
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function updateEmailTemplate(int $templateId, array $params = []): array
    {
        $validator = Validator::make(
            ['template_id' => $templateId] + $params,
            [
                'template_id' => ['required', 'integer', 'min:1'],
                'title' => ['nullable', 'string', 'min:1'],
                'subject' => ['nullable', 'string', 'min:1'],
                'body' => ['nullable', 'string', 'min:1'],
                'description' => ['nullable', 'string'],
                'text_body' => ['nullable', 'string'],
                'lang' => ['nullable', Rule::in(['ru', 'en', 'ua', 'it', 'da', 'de', 'es', 'fr', 'nl', 'pl', 'pt', 'tr'])],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        if (empty($params)) {
            throw new \InvalidArgumentException('Не указаны параметры для обновления');
        }

        $requestParams = ['template_id' => $templateId];

        $allowedParams = ['title', 'subject', 'body', 'description', 'text_body', 'lang'];
        foreach ($allowedParams as $param) {
            if (isset($params[$param])) {
                $requestParams[$param] = $params[$param];
            }
        }

        return $this->callApi('updateEmailTemplate', $requestParams);
    }


    /* ========== Методы: работа с заметками ============= */

    /**
     * Создает заметку для подписчика
     *
     * @param int $subscriberId ID контакта (обязательно)
     * @param string $content Текст заметки (макс. 255 символов, обязательно)
     *
     * @return array [
     *     'result' => [
     *         'id' => int  // ID созданной заметки
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createSubscriberNote(int $subscriberId, string $content): array
    {
        $validator = Validator::make(
            ['subscriber_id' => $subscriberId, 'content' => $content],
            [
                'subscriber_id' => ['required', 'integer', 'min:1'],
                'content' => ['required', 'string', 'min:1', 'max:255'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = [
            'subscriber_id' => $subscriberId,
            'content' => trim($content),
        ];

        return $this->callApi('createSubscriberNote', $params);
    }


    /**
     * Обновляет существующую заметку подписчика
     *
     * @param int $noteId ID заметки для обновления (обязательно)
     * @param string $content Новый текст заметки (макс. 255 символов, обязательно)
     * @param string $format Формат ответа (json|html), по умолчанию 'json'
     *
     * @return array [
     *     'result' => [
     *         'id' => int  // ID обновленной заметки
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function updateSubscriberNote(int $noteId, string $content, string $format = 'json'): array
    {
        $validator = Validator::make(
            ['note_id' => $noteId, 'content' => $content, 'format' => $format],
            [
                'note_id' => ['required', 'integer', 'min:1'],
                'content' => ['required', 'string', 'min:1', 'max:255'],
                'format' => ['required', Rule::in(['json', 'html'])],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = [
            'id' => $noteId,
            'content' => trim($content),
            'format' => $format,
        ];

        return $this->callApi('updateSubscriberNote', $params);
    }


    /**
     * Удаляет заметку подписчика
     *
     * @param int $noteId ID заметки для удаления (обязательно)
     * @param string $format Формат ответа (json|html), по умолчанию 'json'
     *
     * @return array [
     *     'result' => array  // Пустой массив при успешном удалении
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function deleteSubscriberNote(int $noteId, string $format = 'json'): array
    {
        $validator = Validator::make(
            ['note_id' => $noteId, 'format' => $format],
            [
                'note_id' => ['required', 'integer', 'min:1'],
                'format' => ['required', Rule::in(['json', 'html'])],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = [
            'id' => $noteId,
            'format' => $format,
        ];

        return $this->callApi('deleteSubscriberNote', $params);
    }

    /**
     * Получает информацию о конкретной заметке подписчика
     *
     * @param int $noteId       ID заметки (обязательно)
     * @param string $format    Формат вывода (json|html), по умолчанию 'json'
     *
     * @return array [
     *     'result' => [
     *         'id' => int,                 // ID заметки
     *         'user_id' => int,            // ID пользователя
     *         'subscriber_id' => int,      // ID подписчика
     *         'content' => string,         // Текст заметки
     *         'origin' => string,          // Источник создания (api/web)
     *         'is_pinned' => bool,         // Закреплена ли заметка
     *         'pinned_at' => string|null,  // Дата закрепления
     *         'created_at' => string,      // Дата создания
     *         'updated_at' => string       // Дата обновления
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getSubscriberNote(int $noteId, string $format = 'json'): array
    {
        $validator = Validator::make(
            ['note_id' => $noteId, 'format' => $format],
            [
                'note_id' => ['required', 'integer', 'min:1'],
                'format' => ['required', Rule::in(['json', 'html'])],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = [
            'id' => $noteId,
            'format' => $format,
        ];

        return $this->callApi('getSubscriberNote', $params);
    }


    /**
     * Получает список заметок подписчика с возможностью сортировки и фильтрации
     *
     * @param int $subscriberId ID контакта (обязательно)
     * @param array $options Дополнительные параметры:
     *     - 'limit' => int         Лимит записей (по умолчанию 100)
     *     - 'offset' => int        Смещение выборки (по умолчанию 0)
     *     - 'order_type' => string Порядок сортировки (ASC|DESC, по умолчанию DESC)
     *     - 'order_by' => string   Поле сортировки (created_at|updated_at|pinned_at, по умолчанию created_at)
     *     - 'is_pinned' => int     Фильтр по закрепленным заметкам (0|1, необязательно)
     *     - 'format' => string     Формат вывода (json|html, по умолчанию json)
     *
     * @return array [
     *     'result' => array[
     *         [
     *             'id' => int,            // ID заметки
     *             'user_id' => int,       // ID пользователя
     *             'subscriber_id' => int, // ID подписчика
     *             'content' => string,    // Текст заметки
     *             'origin' => string,     // Источник создания (api/web)
     *             'is_pinned' => bool,    // Закреплена ли заметка
     *             'pinned_at' => string,  // Дата закрепления
     *             'created_at' => string, // Дата создания
     *             'updated_at' => string  // Дата обновления
     *         ]
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getSubscriberNotes(int $subscriberId, array $options = []): array
    {
        $validator = Validator::make(
            ['subscriber_id' => $subscriberId] + $options,
            [
                'subscriber_id' => ['required', 'integer', 'min:1'],
                'limit' => ['nullable', 'integer', 'min:1'],
                'offset' => ['nullable', 'integer', 'min:0'],
                'order_type' => ['nullable', 'string', Rule::in(['ASC', 'DESC'])],
                'order_by' => ['nullable', 'string', Rule::in(['created_at', 'updated_at', 'pinned_at'])],
                'is_pinned' => ['nullable', 'integer', Rule::in([0, 1])],
                'format' => ['nullable', 'string', Rule::in(['json', 'html'])],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = ['subscriber_id' => $subscriberId];

        if (isset($options['limit'])) {
            $params['limit'] = (int)$options['limit'];
        }

        if (isset($options['offset'])) {
            $params['offset'] = (int)$options['offset'];
        }

        if (isset($options['order_type'])) {
            $params['order_type'] = strtoupper($options['order_type']);
        }

        if (isset($options['order_by'])) {
            $params['order_by'] = strtolower($options['order_by']);
        }

        if (isset($options['is_pinned'])) {
            $params['is_pinned'] = (int)$options['is_pinned'];
        }

        if (isset($options['format'])) {
            $params['format'] = strtolower($options['format']);
        }

        return $this->callApi('getSubscriberNotes', $params);
    }


    /* ========== Методы: работа с дополнительными полями и метками ============= */

    /**
     * Создает новое пользовательское поле
     *
     * @param string $name              Имя поля (латинские буквы, цифры и _, начинается с буквы)
     * @param string $type              Тип поля (string/text/number/date/bool)
     * @param string|null $publicName   Отображаемое название поля (необязательно)
     *
     * @return array [
     *     'result' => [
     *         'id' => int,      // ID созданного поля
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createField(string $name, string $type, ?string $publicName = null): array
    {
        $validator = Validator::make(
            ['name' => $name, 'type' => $type, 'public_name' => $publicName],
            [
                'name' => [
                    'required',
                    'string',
                    'regex:/^[a-z][a-z0-9_]*$/i',
                    function ($attribute, $value, $fail) {
                        $reservedNames = ['tags', 'email', 'phone', 'email_status', 'phone_status'];
                        if (in_array(strtolower($value), array_map('strtolower', $reservedNames))) {
                            $fail("Имя поля не может совпадать с системными: " . implode(', ', $reservedNames));
                        }
                    },
                ],
                'type' => ['required', Rule::in(['string', 'text', 'number', 'date', 'bool'])],
                'public_name' => ['nullable', 'string'],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = [
            'name' => $name,
            'type' => $type,
        ];

        if ($publicName !== null) {
            $params['public_name'] = $publicName;
        }

        return $this->callApi('createField', $params);
    }


    /**
     * Удаляет пользовательское поле
     *
     * @param int $fieldId ID поля для удаления (полученный из createField)
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function deleteField(int $fieldId): array
    {
        return $this->callApi('deleteField', ['id' => $fieldId]);
    }


    /**
     * Удаляет тег
     *
     * @param int $tagId
     * @return array
     * @throws \Exception
     */
    public function deleteTag(int $tagId): array
    {
        return $this->callApi('deleteTag', ['id' => $tagId]);
    }


    /**
     * Получает список пользовательских полей
     *
     * @return array [
     *     'result' => [
     *         'id' => int,
     *         'name' => string,
     *         'type' => string
     *     ]
     * ]
     * @throws \Exception
     */
    public function getFields(): array
    {
        return $this->callApi('getFields');
    }


    /**
     * Получает список тегов
     *
     * @return array [
     *     'result' => [
     *         ['id' => 123, "name" => "важные клиенты"],
     *         ['id' => 456, "name" => "очень важные клиенты"],
     *          ...
     *     ]
     * ]
     * @throws \Exception
     */
    public function getTags(): array
    {
        return $this->callApi('getTags');
    }


    /**
     * Обновляет пользовательское поле
     *
     * @param int $id                   ID изменяемого поля
     * @param string $name              Новое имя поля (латинские буквы, цифры и _, начинается с буквы)
     * @param string|null $publicName   Отображаемое название поля (необязательно)
     *
     * @return array ['id' => int] // ID обновленного поля
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function updateField(int $id, string $name, ?string $publicName = null): array
    {
        $validator = Validator::make(
            ['id' => $id, 'name' => $name, 'public_name' => $publicName],
            [
                'id' => ['required', 'integer', 'min:1'],
                'name' => [
                    'required',
                    'string',
                    'regex:/^[a-z][a-z0-9_]*$/i',
                    function ($attribute, $value, $fail) {
                        $reservedNames = ['tags', 'email', 'phone', 'email_status', 'phone_status'];
                        if (in_array(strtolower($value), array_map('strtolower', $reservedNames))) {
                            $fail("Имя поля не может совпадать с системными: " . implode(', ', $reservedNames));
                        }
                    },
                ],
                'public_name' => [
                    'nullable',
                    'string',
                    'regex:/^[a-z][a-z0-9_-]*$/i',
                ],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $params = [
            'id' => $id,
            'name' => $name,
        ];

        if ($publicName !== null) {
            $params['public_name'] = $publicName;
        }

        return $this->callApi('updateField', $params);
    }


    /**
     * Получает значения дополнительных полей контакта
     *
     * @param string $email Email адрес контакта
     * @param array|string $fieldIds Массив или строка ID полей через запятую
     *
     * @return array [
     *     'result' => [
     *         'fieldValues' => [
     *              "1" => "Field 1 value",
     *              "2" => "Field 2 value",
     *          ]
     *     ]
     * ]
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getContactFieldValues(string $email, array|string $fieldIds): array
    {
        $validator = Validator::make(
            ['email' => $email, 'field_ids' => $fieldIds],
            [
                'email' => ['required', 'email'],
                'field_ids' => [
                    'required',
                    function ($attribute, $value, $fail) {
                        $fieldIdsStr = is_array($value) ? implode(',', $value) : $value;
                        if (empty(trim($fieldIdsStr))) {
                            $fail("Не указаны ID полей");
                        }
                        if (!preg_match('/^\d+(,\d+)*$/', $fieldIdsStr)) {
                            $fail("ID полей должны быть числами, разделенными запятыми");
                        }
                    },
                ],
            ]
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(implode('; ', $validator->errors()->all()));
        }

        $fieldIdsStr = is_array($fieldIds) ? implode(',', $fieldIds) : $fieldIds;

        $params = [
            'email' => $email,
            'field_ids' => $fieldIdsStr,
        ];

        try {
            return $this->callApi('getContactFieldValues', $params);
        } catch (RequestException $e) {
            throw new \Exception(
                "Ошибка получения значений полей: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }



    /* ========== Вспомогательные методы ============ */

    /**
     * Проверяет соединение с API
     *
     * @return bool
     * @throws \Exception
     */
    public function checkApiConnection(): bool
    {
        try {
            $this->getLists();
            return true;
        } catch (RequestException $e) {
            return false;
        }
    }

    /**
     * Валидация названия поля importContacts
     */
    private function validateFieldImportContacts(string $field): void
    {
        $systemFields = [
            'email', 'phone', 'delete', 'tags',
            'email_status', 'email_list_ids', 'email_subscribe_times',
            'email_unsubscribed_list_ids', 'email_excluded_list_ids',
            'phone_status', 'phone_list_ids', 'phone_subscribe_times',
            'phone_unsubscribed_list_ids', 'phone_excluded_list_ids'
        ];

        // Пользовательские поля должны начинаться с буквы
        if (!in_array($field, $systemFields) && !preg_match('/^[a-z][a-z0-9_]*$/i', $field)) {
            throw new \InvalidArgumentException(
                "Недопустимое название поля: '{$field}'. Должно начинаться с буквы"
            );
        }
    }

    /**
     * Валидация значения поля importContacts
     */
    private function validateFieldValueImportContacts(string $field, $value, int $rowIndex, int $colIndex): void
    {
        if ($value === null || $value === '') return;

        switch ($field) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException(
                        "Неверный email в строке {$rowIndex}, колонка {$colIndex}"
                    );
                }
                break;

            case 'phone':
                if (!preg_match('/^\+?\d{10,15}$/', $value)) {
                    throw new \InvalidArgumentException(
                        "Телефон должен быть в международном формате (строка {$rowIndex})"
                    );
                }
                break;

            case 'email_status':
            case 'phone_status':
                $validStatuses = ['new', 'active', 'inactive', 'unsubscribed'];
                if (!in_array($value, $validStatuses)) {
                    throw new \InvalidArgumentException(
                        "Недопустимый статус '{$value}' в строке {$rowIndex}"
                    );
                }
                break;

            case 'tags':
                $tags = explode(',', $value);
                if (count($tags) > 10) {
                    throw new \InvalidArgumentException(
                        "Максимум 10 тегов (строка {$rowIndex})"
                    );
                }
                break;

            case 'delete':
                if (!in_array($value, [0, 1])) {
                    throw new \InvalidArgumentException(
                        "Поле delete должно быть 0 или 1 (строка {$rowIndex})"
                    );
                }
                break;

            case 'email_list_ids':
            case 'phone_list_ids':
            case 'email_unsubscribed_list_ids':
            case 'phone_unsubscribed_list_ids':
            case 'email_excluded_list_ids':
            case 'phone_excluded_list_ids':
                if (!preg_match('/^\d+(,\d+)*$/', $value)) {
                    throw new \InvalidArgumentException(
                        "Некорректные ID списков в строке {$rowIndex}"
                    );
                }
                break;

            case 'email_subscribe_times':
            case 'phone_subscribe_times':
                $dates = explode(',', $value);
                foreach ($dates as $date) {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $date)) {
                        throw new \InvalidArgumentException(
                            "Некорректный формат даты '{$date}' в строке {$rowIndex}"
                        );
                    }
                }
                break;
        }
    }
}
