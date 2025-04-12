<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Exception;

/**
 * СМС сервис
 * Документация https://iqsms.ru/api/api_rest/
 *
 * Данный вариант выбран, т.к. перед отправкой смс не нужно сохранять её в БД, чтобы передать id смс со стороны клиента (вариант json),
 * что не очень хорошо для организации массовых рассылок.
 */
class IqSmsService
{
    private string $baseUrl;
    private array $auth;

    public function __construct()
    {
        $this->baseUrl = config('iqsms.base_url');
        $this->auth = [
            'login' => config('iqsms.login'),
            'password' => config('iqsms.password'),
        ];
    }

    /**
     * Отправка SMS-сообщения
     * @param string $phone         Номер телефона получателя
     * @param string $text          Текст сообщения
     * @param array $options        Дополнительные параметры (необязательные)
     * @return string
     * @throws Exception
     */
    public function sendSms(string $phone, string $text, array $options = []): string
    {
        $validator = Validator::make(
            ['phone' => $phone, 'text' => $text] + $options,
            [
                'phone' => 'required|string|starts_with:+',
                'text' => 'required|string|max:160',
                'wapurl' => 'nullable|url',
                'sender' => 'nullable|string',
                'flash' => 'nullable|boolean',
                'scheduleTime' => 'nullable|date_format:Y-m-d\TH:i:sP',
                'statusQueueName' => 'nullable|string|min:3|max:16|regex:/^[a-zA-Z0-9]+$/',
            ]);

        if ($validator->fails()) {
            throw new Exception("Validation Error: " . $validator->errors()->first());
        }

        $queryParams = array_merge($this->auth, [
            'phone' => $phone,
            'text' => $text,
        ]);

        if (!empty($options['wapurl'])) {
            $queryParams['wapurl'] = $options['wapurl'];
        }
        if (!empty($options['sender'])) {
            $queryParams['sender'] = $options['sender'];
        }
        if (isset($options['flash'])) {
            $queryParams['flash'] = $options['flash'] ? 1 : 0;
        }
        if (!empty($options['scheduleTime'])) {
            $queryParams['scheduleTime'] = $options['scheduleTime'];
        }
        if (!empty($options['statusQueueName'])) {
            $queryParams['statusQueueName'] = $options['statusQueueName'];
        }

        return $this->get('send', $queryParams);
    }

    /**
     * Получение статуса отправленных сообщений
     * @param array $ids    Массив ID сообщений
     * @return string
     * @throws Exception
     */
    public function status(array $ids): string
    {
        $validator = Validator::make(['ids' => $ids], [
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new Exception("Validation Error: " . $validator->errors()->first());
        }

        $queryParams = array_merge($this->auth, ['id' => implode(',', $ids)]);

        return $this->get('status', $queryParams);
    }

    /**
     * Получение очереди статусов
     * @param string $queueName     Имя очереди
     * @param int $limit            Лимит записей
     * @return string
     * @throws Exception
     */
    public function statusQueue(string $queueName, int $limit = 5): string
    {
        $validator = Validator::make([
            'queueName' => $queueName,
            'limit' => $limit,
        ], [
            'queueName' => 'required|string|min:3|max:16|regex:/^[a-zA-Z0-9]+$/',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            throw new Exception("Validation Error: " . $validator->errors()->all());
        }

        $queryParams = array_merge($this->auth, [
            'statusQueueName' => $queueName,
            'limit' => $limit,
        ]);

        return $this->get('statusQueue', $queryParams);
    }

    /**
     * Получение баланса
     * @return string
     * @throws Exception
     */
    public function balance(): string
    {
        return $this->get('balance', $this->auth);
    }

    /**
     * Получение списка отправителей
     * @return string
     * @throws Exception
     */
    public function senders(): string
    {
        return $this->get('senders', $this->auth);
    }

    /**
     * Универсальный метод для отправки GET-запросов
     * @param string $endpoint      Эндпоинт API
     * @param array $queryParams    Параметры запроса
     *
     * @return string
     * @throws \Exception
     */
    private function get(string $endpoint, array $queryParams = []): string
    {
        try {
            $response = Http::get($this->baseUrl . $endpoint . '/', $queryParams);

            return $response->throw()->body();
        } catch (RequestException $e) {
            throw new \Exception(
                "Ошибка {$endpoint}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
