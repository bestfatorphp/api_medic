<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Сервис для работы с API IntellectDialog
 * Документация API: https://api.intellectdialog.com/#webhooks
 *
 * Предоставляет полный функционал для:
 */
class IntellectDialogService
{
    /**
     * @var string Ключ API для аутентификации, версия 1
     */
    protected string $apiKeyV1;

    /**
     * @var string Базовый URL API, версия 1.0
     */
    protected string $apiUrlV1;

    /**
     * @var string Ключ API для аутентификации, версия 2
     */
    protected string $apiKeyV2;

    /**
     * @var string Базовый URL API, версия 2.0
     */
    protected string $apiUrlV2;

    /**
     * @param string $apiKeyV1 API ключ, версия 1
     * @param ?string $apiUrlV1 URL API, версия 0.1
     * @param ?string $apiKeyV2 API ключ, версия 2
     * @param string $apiUrlV2 URL API, версия 0.2
     */
    public function __construct(
        string $apiKeyV1,
        ?string $apiUrlV1,
        ?string $apiKeyV2,
        string $apiUrlV2,
    ) {
        $this->apiKeyV1 = $apiKeyV1;
        $this->apiUrlV1 = $apiUrlV1 ?? null;
        $this->apiKeyV2 = $apiKeyV2 ?? null;
        $this->apiUrlV2 = $apiUrlV2;

    }

    /**
     * Получаем данные для передачи запроса
     * @param bool $isV1Token
     * @param bool $isV1Api
     * @return array
     */
    private function prepareBeforeRequest(bool $isV1Token, bool $isV1Api): array
    {
        return [
            $isV1Token ? $this->apiKeyV1 : $this->apiKeyV2,
            $isV1Api ? $this->apiUrlV1 : $this->apiUrlV2
        ];
    }

    /**
     * Универсальный метод для отправки GET-запросов.
     * @param string $endpoint Эндпоинт API
     * @return array Массив с данными ответа
     * @throws \Exception Если запрос завершился ошибкой
     */
    private function get(string $endpoint, bool $isV1Token = true, bool $isV1Api = false): array
    {
        try {
            list($token, $baseUrl) = $this->prepareBeforeRequest($isV1Token, $isV1Api);
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ])->get($baseUrl . $endpoint);

            return $response->throw()->json();
        } catch (RequestException $e) {
            throw new \Exception(
                "Ошибка получения данных по endpoint: {$endpoint}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Получить список организации (в документации неверное описание).
     * api_key - версия 1
     * api_version - версия 2.0
     * @return array {
     *     @var string $id Идентификатор организации
     *     @var string $name Наименование организации
     *     @var string $zone Часовой пояс организации
     *     @var string $created_at Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *     @var string $updated_at Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * }
     * @throws \Exception
     */
    public function getCompanyInfo(): array
    {
        $response = $this->get('companies', true, false);
        $response = $response['response'];

        if ($response['status'] === 'error') {
            throw new \Exception("Ошибка получения списка компаний: " . $response['error']);
        }

        return $response;
    }
}
