<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use JetBrains\PhpStorm\ArrayShape;


/**
 * Сервис для работы с API SendSay (https://app.sendsay.ru)
 * Документация API: https://sendsay.ru/api/api.html
 */
class SendSayService
{
    protected string $baseUrl = 'https://api.sendsay.ru/general/api/v100/json/';
    protected string $accountId;
    protected string $login;
    protected string $password;
    protected ?string $session = null;
    protected string $cacheKey;
    protected bool $autoAuth = true;
    protected int $sessionTtl = 3500;

    /**
     * @throws \Exception
     */
    public function __construct(bool $autoAuth = true)
    {
        $this->login = env('SENDSAY_LOGIN');
        $this->accountId = env('SENDSAY_ACCOUNT_ID');
        $this->password = env('SENDSAY_PASSWORD');
        $this->autoAuth = $autoAuth;

        Cache::forget("sendsay_session_{$this->login}");

        if ($this->autoAuth) {
            $this->initializeSession();
        }
    }

    /**
     * @throws \Exception
     */
    private function initializeSession(): void
    {
        if (!$this->login || !$this->password) {
            throw new \Exception('Заполните переменные окружения в env');
        }
        $this->cacheKey = "sendsay_session_{$this->login}";
        $this->session = Cache::get($this->cacheKey);

        if (!$this->session) {
            $this->auth();
        }
    }

    /**
     * Аутентификация/выход в API SendSay
     * @param bool $isLogout
     * @return bool
     */
    public function auth(bool $isLogout = false): bool
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . $this->login, [
                'action' => !$isLogout ? 'login' : 'logout',
                'login' => $this->login,
                'passwd' => $this->password,
            ]);

            if ($response->successful() && $response->json('session')) {
                $this->session = $response->json('session');
                Cache::put(
                    $this->cacheKey,
                    $this->session,
                    $this->sessionTtl
                );
                return true;
            }

            Log::channel('commands')->error('Ошибка аутентификации SendSay', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);
        } catch (\Exception $e) {
            Log::channel('commands')->error('Ошибка аутентификации SendSay', [
                'error' => $e->getMessage()
            ]);
        }

        return false;
    }

    /**
     * Формирование заголовка Authorization
     * @throws \Exception
     */
    #[ArrayShape(['Authorization' => "string", 'Content-Type' => "string"])]
    private function getAuthHeader(): array
    {
        if (!$this->session) {
            throw new \Exception('Сессия на получена');
        }

        return [
            'Authorization' => 'sendsay session=' . $this->session,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Общий метод для API-запросов
     * @throws \Exception
     */
    private function apiRequest(string $action, array $data = []): array
    {
        if (!$this->session && !$this->auth()) {
            throw new \Exception('Ошибка аутентификации API SendSay');
        }

        $payload = array_merge(['action' => $action], $data);
        $headers = $this->getAuthHeader();

        $response = Http::withHeaders($headers)
            ->post($this->baseUrl . $this->accountId, $payload);

        //если сессия устарела (403), пробуем авторизоваться снова
        if ($response->status() === 403) {
            if ($this->auth()) {
                $headers = $this->getAuthHeader();
                $response = Http::withHeaders($headers)
                    ->post($this->baseUrl, $payload);
            }
        }

        if (!$response->successful()) {
            Log::channel('commands')->error('Запрос API SendSay не удался', [
                'action' => $action,
                'status' => $response->status(),
                'response' => $response->json()
            ]);
            throw new \Exception('Запрос API SendSay не удался: ' . $response->body());
        }

        return $response->json();
    }



    /* ========== Методы для работы со подписчиками ============ */

    /**
     * Проверить существование подписчика в системе
     *
     * @param string $identifier        Идентификатор подписчика (email, телефон и т.д.)
     * @param string|null $addrType     Тип идентификатора (email|msisdn|viber|csid|push|vk|tg|vknotify|pushapp|id)
     * @return array
     * @throws \Exception
     */
    public function memberExists(string $identifier, ?string $addrType = null): array
    {
        $requestData = [
            'email' => $identifier,
        ];

        if ($addrType !== null) {
            $requestData['addr_type'] = $addrType;
        }

        return $this->apiRequest('member.exists', $requestData);
    }

    /**
     * Найти всех подписчиков с указанным идентификатором (по всем типам)
     *
     * @param string $identifier    Идентификатор для поиска (может соответствовать разным типам)
     * @return array
     * @throws \Exception
     */
    public function memberFind(string $identifier): array
    {
        return $this->apiRequest('member.find', [
            'email' => $identifier,
        ]);
    }

    /**
     * Получить данные подписчика
     *
     * @param string $identifier        Идентификатор подписчика
     * @param string|null $addrType     Тип идентификатора (email|msisdn|viber|csid|push|vk|tg|vknotify|pushapp|id)
     * @param array $options            Дополнительные параметры запроса:
     *   - with_stoplist: 0|1|2             - информация о стоп-листах
     *   - with_heads: 0|1                  - информация о головах адреса
     *   - missing_too: 0|1                 - возвращать данные даже если подписчика нет
     *   - datakey: string|array            - ключи данных для запроса ('*' для всех данных)
     * @return array
     * @throws \Exception
     */
    public function memberGet(string $identifier, ?string $addrType = null, array $options = []): array
    {
        $requestData = [
            'email' => $identifier,
        ];

        if ($addrType !== null) {
            $requestData['addr_type'] = $addrType;
        }

        if (isset($options['with_stoplist'])) {
            $requestData['with_stoplist'] = (int)$options['with_stoplist'];
        }
        if (isset($options['with_heads'])) {
            $requestData['with_heads'] = (int)$options['with_heads'];
        }
        if (isset($options['missing_too'])) {
            $requestData['missing_too'] = (int)$options['missing_too'];
        }
        if (isset($options['datakey'])) {
            $requestData['datakey'] = $options['datakey'];
        }

        return $this->apiRequest('member.get', $requestData);
    }


    /**
     * Получить список всех идентификаторов и сопутствующую информацию для указанного пользователя
     *
     * @param string $identifier        Один из идентификаторов существующего пользователя
     * @param string|null $addrType     Тип идентификатора (email|msisdn|viber|csid|push|vk|tg|vknotify|pushapp|id)
     * @return array
     * @throws \Exception
     */
    public function memberHeadList(string $identifier, ?string $addrType = null): array
    {
        $requestData = [
            'email' => $identifier,
        ];

        if ($addrType !== null) {
            $requestData['addr_type'] = $addrType;
        }

        return $this->apiRequest('member.head.list', $requestData);
    }

    /**
     * Получить список групп-фильтров, в которых состоит подписчик
     *
     * Время выполнения запроса ограничено 300 секундами. Для больших объемов данных
     * рекомендуется использовать асинхронный режим с возможностью прерывания через track.set
     *
     * @param string $identifier            Идентификатор подписчика
     * @param string|null $addrType         Тип идентификатора (email|msisdn|viber|csid|push|vk|tg|vknotify|pushapp|id)
     * @param string|array|null $groups     Одна или несколько групп для проверки (необязательно)
     * @param string|null $trackInfo        Дополнительная информация для track (макс. 1024 байта)
     * @return array
     * @throws \Exception
     */
    public function memberWhere(string $identifier, ?string $addrType = null, $groups = null, ?string $trackInfo = null): array {
        $requestData = [
            'email' => $identifier,
        ];

        //тип адреса, если указан
        if ($addrType !== null) {
            $requestData['addr_type'] = $addrType;
        }

        //группы для проверки, если указаны
        if ($groups !== null) {
            $requestData['group'] = $groups;
        }

        //track info, если указано
        if ($trackInfo !== null) {
            if (strlen($trackInfo) > 1024) {
                throw new \Exception('Информация о треке не может превышать 1024 байта');
            }
            $requestData['track.info'] = $trackInfo;
        }

        return $this->apiRequest('member.where', $requestData);
    }

    /**
     * Получить список подписчиков с возможностью фильтрации, сортировки и форматирования вывода
     *
     * Важно: При result=response размер выдачи ограничен 1000 строками (с 17 июля 2017 года)
     *
     * @param array $options            Массив параметров запроса:
     *   - group: string                    - идентификатор группы для выборки
     *   - group.filter: array              - фильтр отбора (альтернатива group)
     *   - addr_type: string                - тип выбираемых адресов (email|msisdn|viber|csid|push|vk|tg|vknotify|pushapp)
     *   - member.haslock: int              - фильтр по состоянию блокировки (-1,0,1,2,4 и др.)
     *   - format: string|array             - формат вывода (идентификатор или массив полей)
     *   - sort: string                     - поле для сортировки (member.id, member.email и др.)
     *   - sort.order: string               - направление сортировки (asc|desc)
     *   - result: string                   - способ возврата результата
     *   - caption: string|array            - настройки заголовка ('id', 'name' или массив названий)
     *   - answers: string                  - формат ответов ('decode', 'unroll')
     *   - page: int                        - номер страницы (только для result=response)
     *   - pagesize: int                    - размер страницы (макс. 1000 для result=response)
     *   - track.info: string               - дополнительная информация для track (макс. 1024 байта)
     * @return array
     * @throws \Exception
     */
    public function memberList(array $options = []): array
    {
        $requestData = [];

        //источник адресов (группа или фильтр)
        if (isset($options['group'])) {
            $requestData['group'] = $options['group'];
        } elseif (isset($options['group.filter'])) {
            $requestData['group.filter'] = $options['group.filter'];
        }

        //тип адресов
        if (isset($options['addr_type'])) {
            $requestData['addr_type'] = $options['addr_type'];
        }

        //фильтр по состоянию блокировки
        if (isset($options['member.haslock'])) {
            $requestData['member.haslock'] = $options['member.haslock'];
        }

        //формат вывода
        if (isset($options['format'])) {
            $requestData['format'] = $options['format'];
        }

        //сортировка
        if (isset($options['sort'])) {
            $requestData['sort'] = $options['sort'];

            if (isset($options['sort.order'])) {
                $requestData['sort.order'] = $options['sort.order'];
            }
        }

        //заголовок
        if (isset($options['caption'])) {
            $requestData['caption'] = $options['caption'];
        }

        //формат ответов
        if (isset($options['answers'])) {
            $requestData['answers'] = $options['answers'];
        }

        //пагинация (только для result=response)
        if (isset($options['page']) || isset($options['pagesize'])) {
            if (!isset($options['page']) || !isset($options['pagesize'])) {
                throw new \Exception('Необходимо указать одновременно и страницу, и размер страницы');
            }

            $requestData['page'] = (int)$options['page'];
            $requestData['pagesize'] = (int)$options['pagesize'];

            if ($requestData['pagesize'] > 1000) {
                throw new \Exception('Размер страницы не может превышать 1000 для result=response');
            }
        }

        //track info
        if (isset($options['track.info'])) {
            if (strlen($options['track.info']) > 1024) {
                throw new \Exception('Информация о треке не может превышать 1024 байта');
            }
            $requestData['track.info'] = $options['track.info'];
        }

        if (isset($options['result'])) {
            $requestData['result'] = $options['result'];
        }

        return $this->apiRequest('member.list', $requestData);
    }

    /**
     * Получить количество подписчиков с возможностью фильтрации и детализацией по статусам
     *
     * @param array $options        Массив параметров запроса:
     *   - group: string                - идентификатор группы для выборки
     *   - group.filter: array          - фильтр отбора (альтернатива group)
     *   - addr_type: string            - тип выбираемых адресов (email|msisdn|viber|csid|push|vk|tg|vknotify|pushapp)
     *   - member.haslock: int          - фильтр по состоянию блокировки (-1,0,1,2,4 и др.)
     *   - cache: array                 - параметры кэширования
     *   - sync: int                    - синхронность запроса (0 - асинхронный, 1 - синхронный, по умолчанию 1)
     *   - track.info: string           - дополнительная информация для track (макс. 1024 байта)
     *   - with_minmax: int             - возвращать минимальные и максимальные ID подписчиков (0|1)
     * @return array
     * @throws \Exception
     */
    public function memberListCount(array $options = []): array
    {
        $requestData = [];

        //источник адресов (группа или фильтр)
        if (isset($options['group'])) {
            $requestData['group'] = $options['group'];
        } elseif (isset($options['group.filter'])) {
            $requestData['group.filter'] = $options['group.filter'];
        }

        //тип адресов
        if (isset($options['addr_type'])) {
            $requestData['addr_type'] = $options['addr_type'];
        }

        //фильтр по состоянию блокировки
        if (isset($options['member.haslock'])) {
            $requestData['member.haslock'] = $options['member.haslock'];
        }

        //параметры кэширования
        if (isset($options['cache'])) {
            $requestData['cache'] = $options['cache'];
        }

        //синхронность запроса
        if (isset($options['sync'])) {
            $requestData['sync'] = (int)$options['sync'];
        }

        //track info
        if (isset($options['track.info'])) {
            if (strlen($options['track.info']) > 1024) {
                throw new \Exception('Информация о треке не может превышать 1024 байта');
            }
            $requestData['track.info'] = $options['track.info'];
        }

        //возврат min/max ID
        if (isset($options['with_minmax'])) {
            $requestData['with_minmax'] = (int)$options['with_minmax'];
        }

        return $this->apiRequest('member.list.count', $requestData);
    }


    /* ========== Методы для работы с группами ============ */

    /**
     * Получить список групп адресов с возможностью фильтрации и сортировки
     *
     * Группы позволяют сегментировать аудиторию по различным критериям:
     * - Группы-списки (list) - статические списки адресов
     * - Группы-фильтры (filter) - динамические списки на основе условий
     *
     * @param array $options        Массив параметров запроса:
     *   - filter: array                - условия фильтрации групп (хотя бы один параметр обязателен)
     *   - order: array                 - условия сортировки результата
     *   - skip: int                    - количество пропускаемых записей (по умолчанию 0)
     *   - first: int                   - количество выбираемых записей (по умолчанию 50, максимум 50)
     * @return array
     * @throws \Exception
     */
    public function groupList(array $options = []): array
    {
        //проверяем наличие хотя бы одного параметра фильтрации
        if (empty($options['filter'])) {
            throw new \Exception('Требуется хотя бы один параметр фильтра');
        }

        $requestData = [
            'filter' => $options['filter'],
        ];

        //добавляем опциональные параметры
        if (isset($options['order'])) {
            $requestData['order'] = $options['order'];
        }

        if (isset($options['skip'])) {
            $requestData['skip'] = (int)$options['skip'];
        }

        if (isset($options['first'])) {
            $first = (int)$options['first'];
            if ($first > 50) {
                throw new \Exception('Первый параметр не может превышать 50');
            }
            $requestData['first'] = $first;
        }

        return $this->apiRequest('group.list', $requestData);
    }

    /**
     * Получить информацию о группе или списке групп
     *
     * @param string|array $id      Идентификатор группы (строка, массив или ['*'] для всех групп)
     * @param bool $withFilter      Включать ли в ответ фильтр группы (для групп-фильтров)
     * @return array
     * @throws \Exception
     */
    public function groupGet($id, bool $withFilter = false): array
    {
        $requestData = [
            'with_filter' => $withFilter ? 1 : 0,
            'id' => $id
        ];

        //валидация входных параметров
        if (empty($id)) {
            throw new \Exception('Необходимо указать идентификатор(ы) группы');
        }

        //для случая, когда передали строку вместо массива
        if (is_string($id) && $id !== '*') {
            $requestData['id'] = [$id];
        }

        return $this->apiRequest('group.get', $requestData);
    }


    /* ========== Методы для работы с рассылками (выпусками) в архиве (завершены) ============ */

    /**
     * Получить список выпусков в архиве с возможностью фильтрации
     *
     * @param array $options        Массив параметров запроса:
     *   - from: string                 - начальная дата в формате YYYY-MM-DD (необязательно)
     *   - upto: string                 - конечная дата в формате YYYY-MM-DD (необязательно)
     *   - group: array                 - массив кодов групп для фильтрации (необязательно)
     *   - format: string               - формат выпуска (email|sms|viber|push|vk|tg|vknotify|pushapp) (необязательно)
     * @return array
     * @throws \Exception
     */
    public function issueList(array $options = []): array
    {
        $requestData = [];

        //добавляем параметры фильтрации, если они указаны
        if (isset($options['from'])) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['from'])) {
                throw new \Exception('Неверный формат даты для параметра "from". Используйте YYYY-MM-DD');
            }
            $requestData['from'] = $options['from'];
        }

        if (isset($options['upto'])) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['upto'])) {
                throw new \Exception('Неверный формат даты для параметра "upto". Используйте YYYY-MM-DD');
            }
            $requestData['upto'] = $options['upto'];
        }

        if (isset($options['group'])) {
            if (!is_array($options['group'])) {
                throw new \Exception('Параметр группы должен быть массивом');
            }
            $requestData['group'] = $options['group'];
        }

        if (isset($options['format'])) {
            $validFormats = ['email', 'sms', 'viber', 'push', 'vk', 'tg', 'vknotify', 'pushapp'];
            if (!in_array($options['format'], $validFormats)) {
                throw new \Exception('Неверный формат. Допустимые значения: ' . implode(', ', $validFormats));
            }
            $requestData['format'] = $options['format'];
        }

        return $this->apiRequest('issue.list', $requestData);
    }

    /**
     * Получить информацию о конкретном выпуске из архива
     *
     * @param string $id            Уникальный идентификатор выпуска
     * @param array $options        Дополнительные параметры запроса:
     *   - letter: int                  - номер письма (только для personal выпусков)
     *   - source: int (0|1|2)          - формат возвращаемого текста:
     *                                      0 - как в рассылке (по умолчанию),
     *                                      1 - исходный текст (кроме personal),
     *                                      2 - как 1 + attaches с URL и ссылкой на архив
     *   - with_name: bool              - возвращать название группы выпуска
     *   - with_archive: bool           - возвращать attaches с URL и ссылкой на архив
     * @return array
     * @throws \Exception
     */
    public function issueGet(string $id, array $options = []): array
    {
        if (empty($id)) {
            throw new \Exception('Укажите issue ID');
        }

        $requestData = [
            'id' => $id
        ];

        //добавляем опциональные параметры
        if (isset($options['letter'])) {
            $requestData['letter'] = (int)$options['letter'];
        }

        if (isset($options['source'])) {
            $source = (int)$options['source'];
            if (!in_array($source, [0, 1, 2])) {
                throw new \Exception('Недопустимое значение источника. Разрешено: 0, 1 или 2');
            }
            $requestData['source'] = $source;
        }

        if (isset($options['with_name'])) {
            $requestData['with_name'] = $options['with_name'] ? 1 : 0;
        }

        if (isset($options['with_archive'])) {
            $requestData['with_archive'] = $options['with_archive'] ? 1 : 0;
        }

        return $this->apiRequest('issue.get', $requestData);
    }



    /* ========== Методы для работы со статистикой ============ */

    /**
     * Универсальная статистика по переходам, открытиям, тиражам и результатам доставки
     *
     * @param array $options                Массив параметров запроса:
     *   - select: array                        - обязательный список полей для выборки
     *   - filter: array                        - условия фильтрации (необязательно)
     *   - have: array                          - фильтр после агрегации (необязательно)
     *   - order: array                         - порядок сортировки (необязательно)
     *   - skip: int                            - пропуск строк (необязательно)
     *   - first: int                           - лимит строк (необязательно)
     *   - join: array                          - объединяемые запросы (альтернатива единичному запросу)
     *   - joinby: int                          - размер уникального ключа для объединения
     *   - caption: array                       - заголовки столбцов (необязательно)
     *   - track.info: string                   - информация для track (необязательно, макс 1024 байта)
     *   - result: string                       - способ возврата результата
     *   - cache: array                         - параметры кэширования (необязательно)
     *   - relax_field_access_denied: int       - поведение при отсутствии прав (0-ошибка, 1-заменители)
     * @return array
     * @throws \Exception
     */
    public function statUni(array $options): array
    {
        if (empty($options['select']) && empty($options['join'])) {
            throw new \Exception('Требуется параметр «select» или «join»');
        }

        if (isset($options['join']) && !isset($options['joinby'])) {
            throw new \Exception('Параметр «joinby» обязателен при использовании «join»');
        }

        $requestData = [];

        //добавляем основные параметры запроса
        foreach (['select', 'filter', 'have', 'order', 'skip', 'first', 'join', 'joinby'] as $param) {
            if (isset($options[$param])) {
                $requestData[$param] = $options[$param];
            }
        }

        //добавляем дополнительные параметры
        foreach (['caption', 'track.info', 'result', 'cache', 'relax_field_access_denied'] as $param) {
            if (isset($options[$param])) {
                $requestData[$param] = $options[$param];
            }
        }

        if (isset($requestData['track.info']) && strlen($requestData['track.info']) > 1024) {
            throw new \Exception('Информация о треке не может превышать 1024 байта');
        }

        return $this->apiRequest('stat.uni', $requestData);
    }
}
