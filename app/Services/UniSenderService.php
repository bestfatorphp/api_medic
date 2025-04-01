<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

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

    /**
     * @param string $apiKey API ключ UniSender
     * @param string $apiUrl URL API
     * @param int $retryCount Количество попыток повтора
     * @param int $retryDelay Задержка между попытками в мс
     * @param int $timeout Таймаут запроса в секундах
     * @param string|null $defaultSenderName Имя отправителя по умолчанию
     * @param string|null $defaultSenderEmail Email отправителя по умолчанию
     * @param string|null $defaultSenderPhone Телефон отправителя по умолчанию
     */
    public function __construct(
        string $apiKey,
        string $apiUrl,
        int $retryCount,
        int $retryDelay,
        int $timeout,
        ?string $defaultSenderName = null,
        ?string $defaultSenderEmail = null,
        ?string $defaultSenderPhone = null
    ) {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->retryCount = $retryCount;
        $this->retryDelay = $retryDelay;
        $this->timeout = $timeout;
        $this->defaultSenderName = $defaultSenderName;
        $this->defaultSenderEmail = $defaultSenderEmail;
        $this->defaultSenderPhone = $defaultSenderPhone;
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
        if ($campaignId <= 0) {
            throw new \InvalidArgumentException('campaignId должен быть положительным числом');
        }

        $params = [
            'campaign_id' => $campaignId,
        ];

        return $this->callApi('getCampaignCommonStats', $params);
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
        if ($campaignId <= 0) {
            throw new \InvalidArgumentException('campaignId должен быть положительным числом');
        }

        $params = ['campaign_id' => $campaignId];

        if (isset($options['notify_url'])) {
            if (!filter_var($options['notify_url'], FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('Некорректный notify_url для уведомления');
            }
            $params['notify_url'] = $options['notify_url'];
        }

        if (isset($options['changed_since'])) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $options['changed_since'])) {
                throw new \InvalidArgumentException("changed_since должна быть в формате 'ГГГГ-ММ-ДД чч:мм:сс'");
            }
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
        if ($campaignId <= 0) {
            throw new \InvalidArgumentException('campaignId должен быть положительным числом');
        }

        $params = [
            'campaign_id' => $campaignId
        ];

        return $this->callApi('getCampaignStatus', $params);
    }


    /**
     * Получает список сообщений за указанный период
     *
     * @param string $dateFrom      Начальная дата периода в формате 'YYYY-MM-DD HH:MM' (UTC, обязательно)
     * @param string $dateTo        Конечная дата периода в формате 'YYYY-MM-DD HH:MM' (UTC, обязательно)
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
        if (empty($dateFrom) || empty($dateTo)) {
            throw new \InvalidArgumentException('Даты начала и конца периода обязательны');
        }

        $datePattern = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/';
        if (!preg_match($datePattern, $dateFrom) || !preg_match($datePattern, $dateTo)) {
            throw new \InvalidArgumentException("Даты должны быть в формате 'YYYY-MM-DD HH:MM'");
        }

        $params = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        if (isset($options['format'])) {
            $format = strtolower($options['format']);
            if (!in_array($format, ['json', 'html'])) {
                throw new \InvalidArgumentException("format должен быть 'json' или 'html'");
            }
            $params['format'] = $format;
        }

        if (isset($options['limit'])) {
            $limit = (int)$options['limit'];
            if ($limit < 1 || $limit > 100) {
                throw new \InvalidArgumentException('limit должен быть от 1 до 100');
            }
            $params['limit'] = $limit;
        }

        if (isset($options['offset'])) {
            $offset = (int)$options['offset'];
            if ($offset < 0) {
                throw new \InvalidArgumentException('offset не может быть отрицательным');
            }
            $params['offset'] = $offset;
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
        if ($campaignId <= 0) {
            throw new \InvalidArgumentException('campaignId должен быть положительным числом');
        }

        $params = [
            'campaign_id' => $campaignId,
            'group' => $group ? 1 : 0,
        ];

        return $this->callApi('getVisitedLinks', $params);
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
        if (empty($dateFrom) || empty($dateTo)) {
            throw new \InvalidArgumentException('Даты начала и конца периода обязательны');
        }

        $datePattern = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/';
        if (!preg_match($datePattern, $dateFrom) || !preg_match($datePattern, $dateTo)) {
            throw new \InvalidArgumentException("Даты должны быть в формате 'ГГГГ-ММ-ДД чч:мм'");
        }

        $params = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        if (isset($options['format'])) {
            $format = strtolower($options['format']);
            if (!in_array($format, ['json', 'html'])) {
                throw new \InvalidArgumentException("format должен быть 'json' или 'html'");
            }
            $params['format'] = $format;
        }

        if (isset($options['limit'])) {
            $limit = (int)$options['limit'];
            if ($limit < 1 || $limit > 100) {
                throw new \InvalidArgumentException('limit должен быть от 1 до 100');
            }
            $params['limit'] = $limit;
        }

        if (isset($options['offset'])) {
            $offset = (int)$options['offset'];
            if ($offset < 0) {
                throw new \InvalidArgumentException('offset не может быть отрицательным');
            }
            $params['offset'] = $offset;
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
        $params = [];

        foreach (['from', 'to'] as $dateField) {
            if (isset($options[$dateField])) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $options[$dateField])) {
                    throw new \InvalidArgumentException(
                        "Дата $dateField должна быть в формате 'ГГГГ-ММ-ДД чч:мм:сс'"
                    );
                }
                $params[$dateField] = $options[$dateField];
            }
        }

        if (isset($options['limit'])) {
            $limit = (int)$options['limit'];
            if ($limit < 1 || $limit > 10000) {
                throw new \InvalidArgumentException('Лимит должен быть в диапазоне 1-10000');
            }
            $params['limit'] = $limit;
        }

        if (isset($options['offset'])) {
            $offset = (int)$options['offset'];
            if ($offset < 0) {
                throw new \InvalidArgumentException('Смещение не может быть отрицательным');
            }
            $params['offset'] = $offset;
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
        if (empty($messageId)) {
            throw new \InvalidArgumentException('messageId не может быть пустым');
        }

        //нормализация ID в массив
        $ids = is_array($messageId) ? $messageId : [$messageId];

        foreach ($ids as $id) {
            if (!is_numeric($id) || $id <= 0 || $id > 2147483647) { // 2^31-1
                throw new \InvalidArgumentException('messageId должно быть 31-битным положительным числом');
            }
        }

        $params = [
            'id' => implode(',', $ids)
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
        $params = [
            'title' => $title
        ];

        foreach (['before_subscribe_url', 'after_subscribe_url'] as $urlParam) {
            if (!empty($options[$urlParam])) {
                if (!filter_var($options[$urlParam], FILTER_VALIDATE_URL)) {
                    throw new \InvalidArgumentException("Некорректный URL для параметра {$urlParam}");
                }
                $params[$urlParam] = $options[$urlParam];
            }
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
        if ($listId <= 0) {
            throw new \InvalidArgumentException('ID списка должен быть положительным числом');
        }

        $params = [
            'list_id' => $listId
        ];

        return $this->callApi('deleteList', $params);
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
        $errors = [];

        if (!in_array($contactType, ['email', 'phone'])) {
            $errors[] = "contact_type должен быть 'email' или 'phone'";
        }

        if ($contactType === 'email' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Некорректный email формат";
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('. ', $errors));
        }

        $params = [
            'contact_type' => $contactType,
            'contact' => $contact
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
        if ($listId <= 0) {
            throw new \InvalidArgumentException('ID списка должен быть положительным числом');
        }

        if (empty($fieldNames)) {
            throw new \InvalidArgumentException('Не указаны поля для экспорта');
        }

        $allowedStatuses = ['active', 'inactive', 'unsubscribed', 'blocked', 'invalid'];
        $errors = [];

        if (isset($options['limit']) && ($options['limit'] < 1 || $options['limit'] > 5000)) {
            $errors[] = 'Лимит должен быть от 1 до 5000';
        }

        if (isset($options['offset']) && $options['offset'] < 0) {
            $errors[] = 'Смещение не может быть отрицательным';
        }

        if (isset($options['email_status']) && !in_array($options['email_status'], $allowedStatuses)) {
            $errors[] = 'Недопустимый статус email';
        }

        if (isset($options['phone_status']) && !in_array($options['phone_status'], $allowedStatuses)) {
            $errors[] = 'Недопустимый статус телефона';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('. ', $errors));
        }

        $params = [
            'list_id' => $listId,
            'field_names' => implode(',', $fieldNames),
            'offset' => $options['offset'] ?? 0,
            'limit' => $options['limit'] ?? 100
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
        if ($listId <= 0) {
            throw new \InvalidArgumentException('listId должен быть положительным числом');
        }

        if (empty($params)) {
            throw new \InvalidArgumentException('Необходимо указать хотя бы один params фильтрации');
        }

        $errors = [];

        if (isset($params['tagId']) && !is_numeric($params['tagId'])) {
            $errors[] = 'tagId должен быть числом';
        }

        if (isset($params['type'])) {
            if (!in_array($params['type'], ['address', 'phone'])) {
                $errors[] = "type должен быть 'address' или 'phone'";
            } elseif (isset($params['search']) && empty($params['search'])) {
                $errors[] = 'search не может быть пустым при указанном type';
            }
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('. ', $errors));
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
        if (empty(trim($login))) {
            throw new \InvalidArgumentException('Логин не может быть пустым');
        }

        $params = [
            'login' => $login
        ];

        return $this->callApi('getTotalContactsCount', $params);
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
        if (count($fieldNames) === 0 || count($fieldNames) > 50) {
            throw new \InvalidArgumentException('Количество полей должно быть от 1 до 50');
        }

        if (count($data) === 0 || count($data) > 10000) {
            throw new \InvalidArgumentException('Количество записей должно быть от 1 до 10000');
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
            'field_names' => json_encode($fieldNames),
            'data' => json_encode($data),
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
        if (!isset($fields['email']) && !isset($fields['phone'])) {
            throw new \InvalidArgumentException('Необходимо указать email или phone');
        }

        $errors = [];

        if (isset($options['tags'])) {
            $tagsCount = count(explode(',', $options['tags']));
            if ($tagsCount > 10) {
                $errors[] = 'Максимально допустимое количество меток - 10';
            }
        }

        if (isset($options['double_optin']) && !in_array($options['double_optin'], [0, 3, 4])) {
            $errors[] = 'Параметр double_optin может принимать значения 0, 3 или 4';
        }

        if (isset($options['overwrite']) && !in_array($options['overwrite'], [0, 1, 2])) {
            $errors[] = 'Параметр overwrite может принимать значения 0, 1 или 2';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('. ', $errors));
        }

        $params = [
            'fields' => json_encode($fields),
            'list_ids' => is_array($listIds) ? implode(',', $listIds) : $listIds
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
        $errors = [];

        if (!in_array($contactType, ['email', 'phone'])) {
            $errors[] = "Параметр contact_type должен быть 'email' или 'phone'";
        }

        if ($contactType === 'email' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Некорректный формат email";
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('. ', $errors));
        }

        $params = [
            'contact_type' => $contactType,
            'contact' => $contact
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
        if ($listId <= 0) {
            throw new \InvalidArgumentException('ID списка должен быть положительным числом');
        }

        if (empty($options)) {
            throw new \InvalidArgumentException('Не указаны параметры для обновления');
        }

        $params = ['list_id' => $listId];
        $allowedParams = ['title', 'before_subscribe_url', 'after_subscribe_url'];

        foreach ($options as $key => $value) {
            if (!in_array($key, $allowedParams)) {
                throw new \InvalidArgumentException("Недопустимый параметр для обновления: {$key}");
            }

            if (str_ends_with($key, '_url') && !filter_var($value, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException("Некорректный URL для параметра {$key}");
            }

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
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Некорректный email");
        }

        if (!in_array($condition, ['or', 'and'])) {
            throw new \InvalidArgumentException("condition должно быть 'or' или 'and'");
        }

        $params = [
            'email' => $email,
            'list_ids' => is_array($listIds) ? implode(',', $listIds) : $listIds,
            'condition' => $condition
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
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Некорректный формат email");
        }

        $params = [
            'email' => $email,
            'include_lists' => isset($options['include_lists']) ? 1 : 0,
            'include_fields' => isset($options['include_fields']) ? 1 : 0,
            'include_details' => isset($options['include_details']) ? 1 : 0
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
        $ids = is_array($emailIds) ? $emailIds : explode(',', str_replace(' ', '', $emailIds));
        $ids = array_map('intval', $ids);

        if (count($ids) > 500) {
            throw new \InvalidArgumentException('Максимум 500 emailId в запросе');
        }

        foreach ($ids as $id) {
            if ($id <= 0) {
                throw new \InvalidArgumentException("emailId должно быть положительным числом: $id");
            }
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
        if (!is_numeric($smsId) || $smsId <= 0) {
            throw new \InvalidArgumentException("smsId должно быть положительным числом: $smsId");
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
        if ($messageId <= 0) {
            throw new \InvalidArgumentException("ID сообщения должно быть положительным числом");
        }

        //валидация start_time с использованием Carbon
        if (isset($options['start_time'])) {
            try {
                $startTime = Carbon::parse($options['start_time']);
                $maxDate = Carbon::now()->addDays(100);

                if ($startTime->gt($maxDate)) {
                    throw new \InvalidArgumentException(
                        "Дата старта не может быть больше 100 дней от текущей"
                    );
                }

                //приводим к нужному формату
                $options['start_time'] = $startTime->format('Y-m-d H:i');

            } catch (\Exception $e) {
                throw new \InvalidArgumentException(
                    "Неверный формат даты. Используйте 'ГГГГ-ММ-ДД чч:мм'"
                );
            }
        }

        //валидация contacts/contacts_url
        if (isset($options['contacts']) && isset($options['contacts_url'])) {
            throw new \InvalidArgumentException("Используйте только contacts или contacts_url");
        }

        //подготовка параметров
        $params = [
            'message_id' => $messageId,
            'track_read' => isset($options['track_read']) ? 1 : 0,
            'track_links' => isset($options['track_links']) ? 1 : 0
        ];

        //добавление опциональных параметров
        $optionalParams = [
            'start_time', 'timezone', 'contacts_url',
            'track_ga', 'payment_limit', 'payment_currency'
        ];

        foreach ($optionalParams as $param) {
            if (isset($options[$param])) {
                $params[$param] = $options[$param];
            }
        }

        //обработка contacts
        if (isset($options['contacts'])) {
            $params['contacts'] = is_array($options['contacts'])
                ? implode(',', $options['contacts'])
                : $options['contacts'];
        }

        //параметры Google Analytics
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
        //обязательные параметры (если не используется шаблон)
        $requiredWithoutTemplate = ['sender_name', 'sender_email', 'subject', 'body', 'list_id'];

        if (!isset($params['template_id']) && !isset($params['system_template_id'])) {
            foreach ($requiredWithoutTemplate as $field) {
                if (empty($params[$field])) {
                    throw new \InvalidArgumentException("Обязательный параметр {$field} отсутствует");
                }
            }
        }

        //валидация email отправителя
        if (isset($params['sender_email']) && !filter_var($params['sender_email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Некорректный email отправителя");
        }

        //валидация list_id
        if (isset($params['list_id']) && (!is_numeric($params['list_id']) || $params['list_id'] <= 0)) {
            throw new \InvalidArgumentException("list_id должен быть положительным числом");
        }

        //подготовка параметров запроса
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

        //обработка generate_text (конвертация bool в int)
        if (isset($apiParams['generate_text'])) {
            $apiParams['generate_text'] = $apiParams['generate_text'] ? 1 : 0;
        }

        //обработка вложений (если есть)
        if (!empty($params['attachments']) && is_array($params['attachments'])) {
            foreach ($params['attachments'] as $filename => $content) {
                if (!preg_match('/^[a-z0-9_\-\.]+$/i', $filename)) {
                    throw new \InvalidArgumentException("Имя файла должно содержать только латинские символы");
                }
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
        if (!preg_match('/^[a-z0-9]{3,11}$/i', $sender)) {
            throw new \InvalidArgumentException(
                "sender должно содержать 3-11 латинских букв или цифр"
            );
        }

        if (empty(trim($body))) {
            throw new \InvalidArgumentException("body не может быть пустым");
        }

        if ($listId <= 0) {
            throw new \InvalidArgumentException("list_id должен быть положительным числом");
        }

        $params = [
            'sender' => $sender,
            'body' => $body,
            'list_id' => $listId
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
        if ($messageId <= 0) {
            throw new \InvalidArgumentException('messageId должен быть положительным числом');
        }

        $params = [
            'message_id' => $messageId,
        ];

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
        if ($campaignId <= 0) {
            throw new \InvalidArgumentException('campaignId должен быть положительным числом');
        }

        if (!in_array($format, ['json', 'html'])) {
            throw new \InvalidArgumentException("format должен быть 'json' или 'html'");
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
        $phoneList = is_array($phones) ? $phones : [$phones];
        $phoneList = array_map(function($phone) {
            return ltrim($phone, '+');
        }, $phoneList);

        if (count($phoneList) > 150) {
            throw new \InvalidArgumentException('Максимально 150 номеров в запросе');
        }

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
        $emailList = is_array($emails) ? $emails : explode(',', str_replace(' ', '', $emails));

        foreach ($emailList as $email) {
            if (!filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("Invalid email address: $email");
            }
        }

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
        if ($messageId <= 0) {
            throw new \InvalidArgumentException('Идентификатор сообщения должен быть положительным числом');
        }

        $requestParams = ['id' => $messageId];

        if (isset($params['sender_name'])) {
            if (empty($params['sender_name'])) {
                throw new \InvalidArgumentException('Имя отправителя не может быть пустым');
            }
            $requestParams['sender_name'] = $params['sender_name'];
        }

        if (isset($params['sender_email'])) {
            if (!filter_var($params['sender_email'], FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Некорректный email отправителя');
            }
            $requestParams['sender_email'] = $params['sender_email'];
        }

        if (isset($params['subject']) && empty($params['subject'])) {
            throw new \InvalidArgumentException('Тема письма не может быть пустой');
        }

        $optionalParams = ['subject', 'body', 'list_id', 'text_body', 'lang', 'categories'];
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
        if (empty($senderName)) {
            throw new \InvalidArgumentException('Имя отправителя не может быть пустым');
        }

        if (empty($senderEmail) || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Неверный адрес email отправителя');
        }

        if (!str_contains($body, '{{ConfirmUrl}}')) {
            throw new \InvalidArgumentException('body должно содержать {{ConfirmUrl}}');
        }

        if ($listId <= 0) {
            throw new \InvalidArgumentException('listId должно быть положительным числом');
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
        $params = [
            'username' => $username,
            'format' => $options['format'] ?? 'json',
        ];

        if (!in_array($params['format'], ['json', 'html'])) {
            throw new \InvalidArgumentException("Format must be either 'json' or 'html'");
        }

        if (isset($options['domain'])) {
            $params['domain'] = $options['domain'];
        }

        if (isset($options['limit'])) {
            $limit = (int)$options['limit'];
            if ($limit < 1 || $limit > 100) {
                throw new \InvalidArgumentException('Limit must be between 1 and 100');
            }
            $params['limit'] = $limit;
        }

        if (isset($options['offset'])) {
            $offset = (int)$options['offset'];
            if ($offset < 0) {
                throw new \InvalidArgumentException('Offset must be 0 or greater');
            }
            $params['offset'] = $offset;
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
        if (empty($title)) {
            throw new \InvalidArgumentException('title не может быть пустым');
        }

        if (empty($subject)) {
            throw new \InvalidArgumentException('subject не может быть пустой');
        }

        if (empty($body)) {
            throw new \InvalidArgumentException('body не может быть пустым');
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

        if (isset($params['lang'])) {
            $supportedLangs = ['ru', 'en', 'ua', 'it', 'da', 'de', 'es', 'fr', 'nl', 'pl', 'pt', 'tr'];
            if (!in_array($params['lang'], $supportedLangs)) {
                throw new \InvalidArgumentException('Указан неподдерживаемый язык');
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
        if ($templateId <= 0) {
            throw new \InvalidArgumentException('templateId должен быть положительным числом');
        }

        $params = [
            'template_id' => $templateId,
        ];

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
        if ($templateId === null && $systemTemplateId === null) {
            throw new \InvalidArgumentException('Необходимо указать либо templateId, либо systemTemplateId');
        }

        if (!in_array($format, ['json', 'html'])) {
            throw new \InvalidArgumentException("format должен быть 'json' или 'html'");
        }

        $params = ['format' => $format];

        if ($templateId !== null) {
            if ($templateId <= 0) {
                throw new \InvalidArgumentException('templateId должен быть положительным числом');
            }
            $params['template_id'] = $templateId;
        } else {
            if ($systemTemplateId <= 0) {
                throw new \InvalidArgumentException('systemTemplateId должен быть положительным числом');
            }
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
        $params = [];

        if (isset($options['type'])) {
            if (!in_array($options['type'], ['system', 'user'])) {
                throw new \InvalidArgumentException("Тип шаблона должен быть 'system' или 'user'");
            }
            $params['type'] = $options['type'];
        }

        $dateFields = ['date_from', 'date_to'];
        foreach ($dateFields as $field) {
            if (isset($options[$field])) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $options[$field])) {
                    throw new \InvalidArgumentException("Дата $field должна быть в формате 'ГГГГ-ММ-ДД чч:мм'");
                }
                $params[$field] = $options[$field];
            }
        }

        if (isset($options['format'])) {
            if (!in_array($options['format'], ['json', 'html'])) {
                throw new \InvalidArgumentException("Формат вывода должен быть 'json' или 'html'");
            }
            $params['format'] = $options['format'];
        }

        if (isset($options['limit'])) {
            $limit = (int)$options['limit'];
            if ($limit < 1 || $limit > 100) {
                throw new \InvalidArgumentException('Лимит должен быть в диапазоне 1-100');
            }
            $params['limit'] = $limit;
        }

        if (isset($options['offset'])) {
            $offset = (int)$options['offset'];
            if ($offset < 0) {
                throw new \InvalidArgumentException('Смещение не может быть отрицательным');
            }
            $params['offset'] = $offset;
        }

        return $this->callApi('getTemplates', $params);
    }


    /**
     * Получает список шаблонов с возможностью фильтрации и пагинации
     *
     * @param array $options        Параметры запроса:
     *     - 'type' => string           Тип шаблонов (system|user), по умолчанию 'user'
     *     - 'date_from' => string      Начальная дата создания в формате 'YYYY-MM-DD HH:MM' (UTC)
     *     - 'date_to' => string        Конечная дата создания в формате 'YYYY-MM-DD HH:MM' (UTC)
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
        $params = [];

        if (isset($options['type'])) {
            $type = strtolower($options['type']);
            if (!in_array($type, ['system', 'user'])) {
                throw new \InvalidArgumentException("Тип шаблона должен быть 'system' или 'user'");
            }
            $params['type'] = $type;
        }

        foreach (['date_from', 'date_to'] as $dateField) {
            if (isset($options[$dateField])) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $options[$dateField])) {
                    throw new \InvalidArgumentException(
                        "Дата $dateField должна быть в формате 'ГГГГ-ММ-ДД чч:мм'"
                    );
                }
                $params[$dateField] = $options[$dateField];
            }
        }

        if (isset($options['format'])) {
            $format = strtolower($options['format']);
            if (!in_array($format, ['json', 'html'])) {
                throw new \InvalidArgumentException("Формат должен быть 'json' или 'html'");
            }
            $params['format'] = $format;
        }

        if (isset($options['limit'])) {
            $limit = (int)$options['limit'];
            if ($limit < 1 || $limit > 100) {
                throw new \InvalidArgumentException('Лимит должен быть от 1 до 100');
            }
            $params['limit'] = $limit;
        }

        if (isset($options['offset'])) {
            $offset = (int)$options['offset'];
            if ($offset < 0) {
                throw new \InvalidArgumentException('Смещение не может быть отрицательным');
            }
            $params['offset'] = $offset;
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
        if ($templateId <= 0) {
            throw new \InvalidArgumentException('Идентификатор шаблона должен быть положительным числом');
        }

        if (empty($params)) {
            throw new \InvalidArgumentException('Не указаны параметры для обновления');
        }

        $requestParams = ['template_id' => $templateId];

        if (isset($params['title']) && empty($params['title'])) {
            throw new \InvalidArgumentException('Название шаблона не может быть пустым');
        }

        if (isset($params['subject']) && empty($params['subject'])) {
            throw new \InvalidArgumentException('Тема письма не может быть пустой');
        }

        if (isset($params['body']) && empty($params['body'])) {
            throw new \InvalidArgumentException('Тело шаблона не может быть пустым');
        }

        $supportedLangs = ['ru', 'en', 'ua', 'it', 'da', 'de', 'es', 'fr', 'nl', 'pl', 'pt', 'tr'];

        if (isset($params['lang']) && !in_array($params['lang'], $supportedLangs)) {
            throw new \InvalidArgumentException('Указан неподдерживаемый язык');
        }

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
        if ($subscriberId <= 0) {
            throw new \InvalidArgumentException('subscriberId должен быть положительным числом');
        }

        $content = trim($content);
        if (empty($content)) {
            throw new \InvalidArgumentException('content не может быть пустым');
        }

        if (mb_strlen($content) > 255) {
            throw new \InvalidArgumentException('content не должен превышать 255 символов');
        }

        $params = [
            'subscriber_id' => $subscriberId,
            'content' => $content,
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
        if ($noteId <= 0) {
            throw new \InvalidArgumentException('noteId должен быть положительным числом');
        }

        $content = trim($content);
        if (empty($content)) {
            throw new \InvalidArgumentException('content не может быть пустым');
        }

        if (mb_strlen($content) > 255) {
            throw new \InvalidArgumentException('content не должен превышать 255 символов');
        }

        if (!in_array($format, ['json', 'html'])) {
            throw new \InvalidArgumentException("format должен быть 'json' или 'html'");
        }

        $params = [
            'id' => $noteId,
            'content' => $content,
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
        if ($noteId <= 0) {
            throw new \InvalidArgumentException('noteId должен быть положительным числом');
        }

        if (!in_array($format, ['json', 'html'])) {
            throw new \InvalidArgumentException("format должен быть 'json' или 'html'");
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
        if ($noteId <= 0) {
            throw new \InvalidArgumentException('noteId должен быть положительным числом');
        }

        if (!in_array($format, ['json', 'html'])) {
            throw new \InvalidArgumentException("format должен быть 'json' или 'html'");
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
        if ($subscriberId <= 0) {
            throw new \InvalidArgumentException('subscriberId должен быть положительным числом');
        }

        $params = [
            'subscriber_id' => $subscriberId,
        ];

        if (isset($options['limit'])) {
            $limit = (int)$options['limit'];
            if ($limit < 1) {
                throw new \InvalidArgumentException('Лимит должен быть положительным числом');
            }
            $params['limit'] = $limit;
        }

        if (isset($options['offset'])) {
            $offset = (int)$options['offset'];
            if ($offset < 0) {
                throw new \InvalidArgumentException('offset не может быть отрицательным');
            }
            $params['offset'] = $offset;
        }

        if (isset($options['order_type'])) {
            $orderType = strtoupper($options['order_type']);
            if (!in_array($orderType, ['ASC', 'DESC'])) {
                throw new \InvalidArgumentException("order_type должен быть 'ASC' или 'DESC'");
            }
            $params['order_type'] = $orderType;
        }

        if (isset($options['order_by'])) {
            $orderBy = strtolower($options['order_by']);
            if (!in_array($orderBy, ['created_at', 'updated_at', 'pinned_at'])) {
                throw new \InvalidArgumentException("order_by должно быть 'created_at', 'updated_at' или 'pinned_at'");
            }
            $params['order_by'] = $orderBy;
        }

        if (isset($options['is_pinned'])) {
            $isPinned = (int)$options['is_pinned'];
            if (!in_array($isPinned, [0, 1])) {
                throw new \InvalidArgumentException("is_pinned должен быть 0 или 1");
            }
            $params['is_pinned'] = $isPinned;
        }

        if (isset($options['format'])) {
            $format = strtolower($options['format']);
            if (!in_array($format, ['json', 'html'])) {
                throw new \InvalidArgumentException("format должен быть 'json' или 'html'");
            }
            $params['format'] = $format;
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
        if (!preg_match('/^[a-z][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException(
                "Имя поля должно содержать только латинские буквы, цифры и _, начинаться с буквы"
            );
        }

        $reservedNames = ['tags', 'email', 'phone', 'email_status', 'phone_status'];
        if (in_array(strtolower($name), array_map('strtolower', $reservedNames))) {
            throw new \InvalidArgumentException(
                "Имя поля не может совпадать с системными: " . implode(', ', $reservedNames)
            );
        }

        $allowedTypes = ['string', 'text', 'number', 'date', 'bool'];
        if (!in_array($type, $allowedTypes)) {
            throw new \InvalidArgumentException(
                "Недопустимый тип поля. Допустимые значения: " . implode(', ', $allowedTypes)
            );
        }

        $params = [
            'name' => $name,
            'type' => $type
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
        if ($fieldId <= 0) {
            throw new \InvalidArgumentException("fieldId должен быть положительным числом");
        }

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
        if ($id <= 0) {
            throw new \InvalidArgumentException("ID поля должен быть положительным числом");
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException(
                "Имя поля должно содержать только латинские буквы, цифры и _, начинаться с буквы"
            );
        }

        $reservedNames = ['tags', 'email', 'phone', 'email_status', 'phone_status'];
        if (in_array(strtolower($name), array_map('strtolower', $reservedNames))) {
            throw new \InvalidArgumentException(
                "Имя поля не может совпадать с системными: " . implode(', ', $reservedNames)
            );
        }

        if ($publicName !== null && !preg_match('/^[a-z][a-z0-9_-]*$/i', $publicName)) {
            throw new \InvalidArgumentException(
                "Отображаемое имя может содержать только латинские буквы, цифры, _ и -, начинаться с буквы"
            );
        }

        $params = [
            'id' => $id,
            'name' => $name
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
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Некорректный формат email");
        }

        $fieldIdsStr = is_array($fieldIds) ? implode(',', $fieldIds) : $fieldIds;

        if (empty(trim($fieldIdsStr))) {
            throw new \InvalidArgumentException("Не указаны ID полей");
        }

        if (!preg_match('/^\d+(,\d+)*$/', $fieldIdsStr)) {
            throw new \InvalidArgumentException("ID полей должны быть числами, разделенными запятыми");
        }

        $params = [
            'email' => $email,
            'field_ids' => $fieldIdsStr
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
