<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * Сервис для работы с API IntellectDialog
 * Документация API: https://api.intellectdialog.com/#webhooks
 *
 * Предоставляет полный функционал для:
 * - Упрвление чатами WhatsApp
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

    public function __construct() {
        $this->apiKeyV1 = config('intellect-dialog.api_key_v1');
        $this->apiUrlV1 = config('intellect-dialog.api_url_v1');
        $this->apiKeyV2 = config('intellect-dialog.api_key_v2');
        $this->apiUrlV2 = config('intellect-dialog.api_url_v2');
    }

    /**
     * Получить информацию об организации
     * api_key - версия 1
     * api_version - версия 2.0
     * @return array [
     *     @var string $id              Идентификатор организации
     *     @var string $name            Наименование организации
     *     @var string $zone            Часовой пояс организации
     *     @var string $created_at      Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *     @var string $updated_at      Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function company(): array
    {
        return $this->prepareResponse(
            $this->get('companies')
        );
    }

    /* ========== Методы, работа с отделами ============ */

    /**
     * Получить список отделов
     * api_key - версия 1
     * api_version - версия 2.0
     * @param array $options  [          Параметры запроса:
     *     @var int|null $limit               Лимит записей, максимум 500 (по умолчанию 20)
     *     @var int|null $offset              Пропустить записей (по умолчанию 0)
     *     @var string|null $name             Поиск по имени отдела
     *     @var string|null $created_after    Начиная с даты, указанной в формате YYYY-mm-dd HH:ii:ss
     *     @var string|null $created_before   До даты, указанной в формате YYYY-mm-dd HH:ii:ss
     *]
     *
     * @return array [
     *     @var string $date                Время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status              Статус выполнения: success или error
     *     @var array $pagin [               Объект параметров пагинации запроса:
     *         @var int $limit              Количество записей на запрос
     *         @var int $offset             Кол-во записей пропущено
     *         @var int $total              Кол-во записей всего
     *      ],
     *     @var array $data  [              Массив объектов "Отдел"
     *         [
     *              @var string $id                  Идентификатор отдела
     *              @var string $name                Наименование отдела
     *              @var string $created_at          Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *              @var string $updated_at          Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     *          ],
     *          ...
     *      ]
     * ]
     * @throws \Exception
     */
    public function departments(array $options = []): array {
        $validator = Validator::make($options, [
            'limit' => 'nullable|integer|min:1|max:500',
            'offset' => 'nullable|integer|min:0',
            'name' => 'nullable|string|max:255',
            'created_after' => 'nullable|date_format:Y-m-d H:i:s',
            'created_before' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        $queryParams = array_filter([
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
            'name' => $options['name'] ?? null,
            'created_after' => $options['created_after'] ?? null,
            'created_before' => $options['created_before'] ?? null,
        ]);

        return $this->prepareResponse(
            $this->get('departments', $queryParams),
            true
        );
    }

    /**
     * Получить отдел по ID.
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $departmentId      Идентификатор отдела
     *
     * @return array [                  Объект отдела:
     *         @var string $id                  Идентификатор отдела
     *         @var string $name                Наименование отдела
     *         @var string $created_at          Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at          Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function department(string $departmentId): array
    {
        return $this->prepareResponse(
            $this->get("departments/{$departmentId}")
        );
    }

    /**
     * Изменить отдел по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $departmentId Идентификатор отдела
     * @param string $name         Новое имя отдела
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function updateDepartment(string $departmentId, string $name): array
    {
        $data = ['name' => $name];
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->put("departments/{$departmentId}", $data)
        );
    }

    /**
     * Добавить отдел
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $name              Имя отдела
     *
     * @return array [                  Объект отдела:
     *         @var string $id                  Идентификатор отдела
     *         @var string $name                Наименование отдела
     *         @var string $created_at          Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at          Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function createDepartment(string $name): array
    {
        $data = ['name' => $name];
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->post("departments", $data)
        );
    }

    /**
     * Удалить отдел по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $departmentId Идентификатор отдела
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function deleteDepartment(string $departmentId): array
    {
        return $this->prepareResponse(
            $this->delete("/departments/{$departmentId}")
        );
    }


    /* ========== Методы, работа с сотрудниками ============ */

    /**
     * Получить список сотрудников
     * api_key - версия 1
     * api_version - версия 2.0
     * @param array $options [           Параметры запроса:
     *     @var int|null $limit               Лимит записей, максимум 500 (по умолчанию 20)
     *     @var int|null $offset              Пропустить записей (по умолчанию 0)
     *     @var string|null $phone            Поиск по телефону
     *     @var string|null $email            Поиск по email
     *     @var string|null $created_after    Начиная с даты, указанной в формате YYYY-mm-dd HH:ii:ss
     *     @var string|null $created_before   До даты, указанной в формате YYYY-mm-dd HH:ii:ss
     *  ]
     *
     * @return array [
     *     @var string $date                Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status              Статус выполнения: success или error
     *     @var array $pagin [             Объект параметров пагинации запроса:
     *         @var int $limit                  Количество записей на запрос
     *         @var int $offset                 Кол-во записей пропущено
     *         @var int $total                  Кол-во записей всего
     *      ],
     *     @var array $data [                Массив объектов "Сотрудник"
     *          [
     *              @var string $id                  Идентификатор сотрудника
     *              @var string $department_id       Идентификатор отдела
     *              @var string $department_name     Наименование отдела
     *              @var string $person_id           Идентификатор сотрудника
     *              @var string $person_name         Имя сотрудника
     *              @var string $person_surname      Фамилия сотрудника
     *              @var string $person_lastname     Отчество сотрудника
     *              @var string $person_phone        Телефон сотрудника
     *              @var string $person_email        Email сотрудника
     *              @var string $created_at          Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *              @var string $updated_at          Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     *      ],
     *      ...
     * ]
     * @throws \Exception
     */
    public function employees(array $options = []): array
    {
        $validator = Validator::make($options, [
            'limit' => 'nullable|integer|min:1|max:500',
            'offset' => 'nullable|integer|min:0',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'created_after' => 'nullable|date_format:Y-m-d H:i:s',
            'created_before' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        $queryParams = array_filter([
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
            'phone' => $options['phone'] ?? null,
            'email' => $options['email'] ?? null,
            'created_after' => $options['created_after'] ?? null,
            'created_before' => $options['created_before'] ?? null,
        ]);

        return $this->prepareResponse(
            $this->get('employees', $queryParams),
            true
        );
    }

    /**
     * Получить сотрудника по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $employeeId Идентификатор сотрудника
     *
     * @return array [                  Объект сотрудника:
     *         @var string $id                 Идентификатор сотрудника
     *         @var string $department_id      Идентификатор отдела
     *         @var string $department_name    Наименование отдела
     *         @var string $person_id          Идентификатор сотрудника
     *         @var string $person_name        Имя сотрудника
     *         @var string $person_surname     Фамилия сотрудника
     *         @var string $person_lastname    Отчество сотрудника
     *         @var string $person_phone       Телефон сотрудника
     *         @var string $person_email       Email сотрудника
     *         @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     *      ]
     * ]
     * @throws \Exception
     */
    public function employee(string $employeeId): array
    {
        return $this->prepareResponse(
            $this->get("employees/{$employeeId}")
        );
    }

    /**
     * Добавить сотрудника
     * api_key - версия 1
     * api_version - версия 2.0
     * @param array $data      [     Данные для добавления сотрудника:
     *     @var string|null $phone         Номер телефона сотрудника
     *     @var string $email              Email сотрудника
     *     @var string $name               Имя сотрудника
     *     @var string $department_id      Идентификатор отдела
     *     @var string $password           Пароль
     *     @var string|null $lastname      Отчество сотрудника
     *     @var string|null $surname       Фамилия сотрудника
     *     @var bool|null $admin           Право доступа в админку
     *     @var bool|null $dialogs         Видимость только назначенных диалогов
     *  ]
     *
     * @return array [                      Объект сотрудника:
     *         @var string $id                 Идентификатор сотрудника
     *         @var string $department_id      Идентификатор отдела
     *         @var string $department_name    Наименование отдела
     *         @var string $person_id          Идентификатор сотрудника
     *         @var string $person_name        Имя сотрудника
     *         @var string $person_surname     Фамилия сотрудника
     *         @var string $person_lastname    Отчество сотрудника
     *         @var string $person_phone       Телефон сотрудника
     *         @var string $person_email       Email сотрудника
     *         @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function createEmployee(array $data): array
    {
        $validator = Validator::make($data, [
            'email' => 'required|email',
            'name' => 'required|string|max:255',
            'department_id' => 'required|string|max:255',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:20',
            'lastname' => 'nullable|string|max:255',
            'surname' => 'nullable|string|max:255',
            'admin' => 'nullable|boolean',
            'dialogs' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->post("employees", $data)
        );
    }

    /**
     * Изменить сотрудника
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $employeeId             Идентификатор сотрудника
     * @param array $data [                  Данные для изменения сотрудника:
     *     @var string|null $department_id      Идентификатор отдела
     *     @var string|null $password           Пароль
     *     @var bool|null $admin                Право доступа в админку
     *     @var bool|null $dialogs              Видимость только назначенных диалогов
     * ]
     *
     * @return array [                          Объект сотрудника:
     *         @var string $id                      Идентификатор сотрудника
     *         @var string $department_id           Идентификатор отдела
     *         @var string $department_name         Наименование отдела
     *         @var string $person_id               Идентификатор сотрудника
     *         @var string $person_name             Имя сотрудника
     *         @var string $person_surname          Фамилия сотрудника
     *         @var string $person_lastname         Отчество сотрудника
     *         @var string $person_phone            Телефон сотрудника
     *         @var string $person_email            Email сотрудника
     *         @var string $created_at              Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at              Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function updateEmployee(string $employeeId, array $data): array
    {
        $validator = Validator::make($data, [
            'department_id' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:6',
            'admin' => 'nullable|boolean',
            'dialogs' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->put("employees/{$employeeId}", $data)
        );
    }

    /**
     * Получить код авторизации сотрудника
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $employeeId Идентификатор сотрудника
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     *     @var array $code [        Объект с кодом авторизации:
     *         @var string $code   Код авторизации сотрудника
     *      ]
     * ]
     *
     * @throws \Exception
     */
    public function employeeAuthorizationCode(string $employeeId): array
    {
        return $this->prepareResponse(
            $this->post("employees/{$employeeId}/code")
        );
    }


    /* ========== Методы, работа с персонами ============ */

    /**
     * Получить список персон.
     * api_key - версия 1
     * api_version - версия 2.0
     * @param array $options  [              Параметры запроса:
     *     @var int|null $limit               Лимит записей, максимум 500 (по умолчанию 20)
     *     @var int|null $offset              Пропустить записей (по умолчанию 0)
     *     @var string|null $phone            Поиск по телефону
     *     @var string|null $email            Поиск по email
     *     @var string|null $tags             Поиск по тегам, указанным через запятую
     *     @var string|null $created_after    Начиная с даты, указанной в формате YYYY-mm-dd HH:ii:ss
     *     @var string|null $created_before   До даты, указанной в формате YYYY-mm-dd HH:ii:ss
     * ]
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     *     @var array $pagin [     Объект параметров пагинации запроса:
     *         @var int $limit     Количество записей на запрос
     *         @var int $offset    Кол-во записей пропущено
     *         @var int $total     Кол-во записей всего
     *      ],
     *     @var array $data [      Массив объектов "Персона":
     *          [
     *              @var string $id                 Идентификатор персоны
     *              @var string $name               Имя персоны
     *              @var string $surname            Фамилия персоны
     *              @var string $lastname           Отчество персоны
     *              @var string $phone              Телефон персоны
     *              @var string $email              Email персоны
     *              @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *              @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     *          ],
     *          ...
     *      ]
     * ]
     * @throws \Exception
     */
    public function persons(array $options = []): array
    {
        $validator = Validator::make($options, [
            'limit' => 'nullable|integer|min:1|max:500',
            'offset' => 'nullable|integer|min:0',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'tags' => 'nullable|string|max:255',
            'created_after' => 'nullable|date_format:Y-m-d H:i:s',
            'created_before' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        $queryParams = array_filter([
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
            'phone' => $options['phone'] ?? null,
            'email' => $options['email'] ?? null,
            'tags' => $options['tags'] ?? null,
            'created_after' => $options['created_after'] ?? null,
            'created_before' => $options['created_before'] ?? null,
        ]);

        return $this->prepareResponse(
            $this->get('persons', $queryParams),
            true
        );
    }

    /**
     * Получить персону по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $personId      Идентификатор персоны
     *
     * @return array [
     *         @var string $id                 Идентификатор персоны
     *         @var string $name               Имя персоны
     *         @var string $surname            Фамилия персоны
     *         @var string $lastname           Отчество персоны
     *         @var string $phone              Телефон персоны
     *         @var string $email              Email персоны
     *         @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     *         @var array $tags [              Массив объектов "Тег":
     *             @var string $name                Наименование тега
     *             @var string $id                  Идентификатор тега
     *          ],
     *         @var array $channels [          Массив объектов "Провайдер":
     *             @var string $id                  Идентификатор провайдера
     *             @var string $type                Тип провайдера
     *             @var string $name                Наименование провайдера
     *          ],
     *         @var array $custom_fields [     Массив объектов "Кастомное поле":
     *             @var string $id                  Идентификатор поля
     *             @var string $name                Наименование поля
     *          ]
     * ]
     * @throws \Exception
     */
    public function person(string $personId): array
    {
        return $this->prepareResponse(
            $this->get("persons/{$personId}")
        );
    }

    /**
     * Добавить персону
     * api_key - версия 1
     * api_version - версия 2.0
     *
     * @param array $data   [       Данные для добавления персоны:
     *     @var string $name                Имя персоны (обязательное поле)
     *     @var string $phone               Телефон персоны (обязательное поле)
     *     @var string|null $surname        Фамилия персоны
     *     @var string|null $lastname       Отчество персоны
     *     @var string|null $email          Email персоны
     * ]
     *
     * @return array [
     *         @var string $id                 Идентификатор персоны
     *         @var string $name               Имя персоны
     *         @var string $surname            Фамилия персоны
     *         @var string $lastname           Отчество персоны
     *         @var string $phone              Телефон персоны
     *         @var string $email              Email персоны
     *         @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function createPerson(array $data): array
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'surname' => 'nullable|string|max:255',
            'lastname' => 'nullable|string|max:255',
            'email' => 'nullable|email',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->post('persons', $data)
        );
    }

    /**
     * Изменить персону
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $personId          Идентификатор персоны
     * @param array $data [             Данные для изменения персоны:
     *     @var string|null $name           Имя персоны
     *     @var string|null $surname        Фамилия персоны
     *     @var string|null $lastname       Отчество персоны
     *     @var string|null $email          Email персоны
     *     @var string|null $phone          Телефон персоны
     * ]
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function updatePerson(string $personId, array $data): array
    {
        $validator = Validator::make($data, [
            'name' => 'nullable|string|max:255',
            'surname' => 'nullable|string|max:255',
            'lastname' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->put("persons/{$personId}", $data)
        );
    }

    /**
     * Удалить персону по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $personId      Идентификатор персоны
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function deletePerson(string $personId): array
    {
        return $this->prepareResponse(
            $this->delete("persons/{$personId}")
        );
    }


    /* ========== Методы, работа с стоп-листом ============ */

    /**
     * Получить список персон в стоп листе
     * api_key - версия 1
     * api_version - версия 2.0
     * @param array $options    [       Параметры запроса:
     *     @var int|null $limit               Лимит записей, максимум 500 (по умолчанию 20)
     *     @var int|null $offset              Пропустить записей (по умолчанию 0)
     *     @var string|null $phone            Поиск по телефону
     *     @var string|null $email            Поиск по email
     *     @var string|null $created_after    Начиная с даты, указанной в формате YYYY-mm-dd HH:ii:ss
     *     @var string|null $created_before   До даты, указанной в формате YYYY-mm-dd HH:ii:ss
     * ]
     *
     * @return array [
     *     @var string $date            Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status          Статус выполнения: success или error
     *     @var array $pagin [         Объект параметров пагинации запроса:
     *         @var int $limit          Количество записей на запрос
     *         @var int $offset         Кол-во записей пропущено
     *         @var int $total          Кол-во записей всего
     *      ],
     *     @var array[] $data [      Массив объектов "Стоп лист":
     *          [
     *         @var string $id                 Идентификатор записи
     *         @var string $person_id          Идентификатор персоны
     *         @var string $person_name        Имя персоны
     *         @var string $person_surname     Фамилия персоны
     *         @var string $person_lastname    Отчество персоны
     *         @var string $person_phone       Телефон персоны
     *         @var string $person_email       Email персоны
     *         @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     *          ],
     *          ...
     *      ]
     * ]
     * @throws \Exception
     */
    public function stopListOfPersons(array $options = []): array
    {
        $validator = Validator::make($options, [
            'limit' => 'nullable|integer|min:1|max:500',
            'offset' => 'nullable|integer|min:0',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'created_after' => 'nullable|date_format:Y-m-d H:i:s',
            'created_before' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        $queryParams = array_filter([
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
            'phone' => $options['phone'] ?? null,
            'email' => $options['email'] ?? null,
            'created_after' => $options['created_after'] ?? null,
            'created_before' => $options['created_before'] ?? null,
        ]);

        return $this->prepareResponse(
            $this->get('stoplist', $queryParams),
            true
        );
    }

    /**
     * Получить запись персоны в стоп листе по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $stopListId   Идентификатор записи стоп листа
     *
     * @return array [
     *         @var string $id                 Идентификатор записи
     *         @var string $person_id          Идентификатор персоны
     *         @var string $person_name        Имя персоны
     *         @var string $person_surname     Фамилия персоны
     *         @var string $person_lastname    Отчество персоны
     *         @var string $person_phone       Телефон персоны
     *         @var string $person_email       Email персоны
     *         @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function personInStopList(string $stopListId): array
    {
        return $this->prepareResponse(
            $this->get("stoplist/{$stopListId}")
        );
    }

    /**
     * Добавить персону в стоп лист
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $person_id Идентификатор персоны (обязательное поле)
     *
     * @return array [
     *         @var string $id                 Идентификатор записи
     *         @var string $person_id          Идентификатор персоны
     *         @var string $person_name        Имя персоны
     *         @var string $person_surname     Фамилия персоны
     *         @var string $person_lastname    Отчество персоны
     *         @var string $person_phone       Телефон персоны
     *         @var string $person_email       Email персоны
     *         @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function addPersonToStopList(string $person_id): array
    {
        $data = ['person_id' => $person_id];

        $validator = Validator::make($data, [
            'person_id' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->post('stoplist', $data)
        );
    }

    /**
     * Исключить персону из стоп листа по ID записи
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $personId     Идентификатор записи стоп листа
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function removePersonFromStopList(string $personId): array
    {
        return $this->prepareResponse(
            $this->delete("stoplist/{$personId}")
        );
    }

    /**
     * Назначить тег персоне
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $personId      Идентификатор персоны
     * @param string $tag_id        Идентификатор тега (обязательное поле)
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function assignTagToPerson(string $personId, string $tag_id): array
    {
        $data = ['tag_id' => $tag_id];
        $validator = Validator::make($data, [
            'tag_id' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->post("persons/{$personId}/tags", $data)
        );
    }

    /**
     * Удалить тег у персоны
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $personId      Идентификатор персоны
     * @param string $tag_id        Идентификатор тега (обязательное поле)
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function removeTagFromPerson(string $personId, string $tag_id): array
    {
        $data = ['tag_id' => $tag_id];
        $validator = Validator::make($data, [
            'tag_id' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->delete("persons/{$personId}/tags", $data)
        );
    }


    /* ========== Методы, работа с полями персоны ============ */

    /**
     * Установить кастомное поля персоны
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $personId      Идентификатор персоны
     * @param array $data   [       Данные для установки полей:
     *     @var array $fields [     Массив полей персоны, каждый элемент содержит:
     *         @var string $name    Название поля (обязательное поле)
     *         @var string $value   Значение поля (обязательное поле)
     *      ]
     *]
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function setPersonFields(string $personId, array $data): array
    {
        $validator = Validator::make($data, [
            'fields' => 'required|array',
            'fields.*.name' => 'required|string|max:255',
            'fields.*.value' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->post("persons/{$personId}/fields", $data)
        );
    }

    /**
     * Удалить кастомное поле персоны
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $personId      Идентификатор персоны
     * @param string $field         Наименование поля (обязательное поле)
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function removePersonField(string $personId, string $field): array
    {
        $data = ['field' => $field];
        $validator = Validator::make($data, [
            'field' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->delete("persons/{$personId}/fields", $data)
        );
    }


    /* ========== Методы, работа с чатами ============ */

    /**
     * Получить список чатов
     * api_key - версия 1
     * api_version - версия 2.0
     * @param array $options [       Параметры запроса:
     *     @var int|null $limit               Лимит записей, максимум 500 (по умолчанию 20)
     *     @var int|null $offset              Пропустить записей (по умолчанию 0)
     *     @var string|null $provider_type    Поиск по названию типа провайдера
     *     @var string|null $provider_type_id Поиск по идентификатору типа провайдера
     *     @var string|null $provider         Поиск по идентификатору провайдера
     *     @var string|null $phone            Поиск по номеру телефона персоны
     *     @var string|null $email            Поиск по email персоны
     *     @var string|null $created_after    Начиная с даты, указанной в формате YYYY-mm-dd HH:ii:ss
     *     @var string|null $created_before   До даты, указанной в формате YYYY-mm-dd HH:ii:ss
     * ]
     *
     * @return array [
     *     @var string $date                Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status              Статус выполнения: success или error
     *     @var array $pagin  [            Объект параметров пагинации запроса:
     *         @var int $limit                      Количество записей на запрос
     *         @var int $offset                     Кол-во записей пропущено
     *         @var int $total                      Кол-во записей всего
     *      ],
     *     @var array $data [     Массив объектов "Чат":
     *          [
     *              @var string $id                     Идентификатор чата
     *              @var string $title                  Наименование чата
     *              @var string $provider_id            Идентификатор провайдера
     *              @var string $provider_type_id       Идентификатор типа провайдера
     *              @var string $provider_type_name     Наименование типа провайдера
     *              @var string $provider_name          Наименование провайдера
     *              @var string $person_id              Идентификатор персоны
     *              @var string $person_name            Имя персоны
     *              @var string $person_surname         Фамилия персоны
     *              @var string $person_lastname        Отчество персоны
     *              @var string $person_phone           Телефон персоны
     *              @var string $person_email           Email персоны
     *              @var string $created_at             Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *              @var string $updated_at             Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     *          ],
     *          ...
     * ]
     * @throws \Exception
     */
    public function chats(array $options = []): array
    {
        $validator = Validator::make($options, [
            'limit' => 'nullable|integer|min:1|max:500',
            'offset' => 'nullable|integer|min:0',
            'provider_type' => 'nullable|string|max:255',
            'provider_type_id' => 'nullable|string|max:255',
            'provider' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'created_after' => 'nullable|date_format:Y-m-d H:i:s',
            'created_before' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        $queryParams = array_filter([
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
            'provider_type' => $options['provider_type'] ?? null,
            'provider_type_id' => $options['provider_type_id'] ?? null,
            'provider' => $options['provider'] ?? null,
            'phone' => $options['phone'] ?? null,
            'email' => $options['email'] ?? null,
            'created_after' => $options['created_after'] ?? null,
            'created_before' => $options['created_before'] ?? null,
        ]);

        return $this->prepareResponse(
            $this->get('chats', $queryParams),
            true
        );
    }

    /**
     * Получить чат по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $chatId        Идентификатор чата
     *
     * @return array [
     *         @var string $id                     Идентификатор чата
     *         @var string $title                  Наименование чата
     *         @var string $provider_id            Идентификатор провайдера
     *         @var string $provider_type_id       Идентификатор типа провайдера
     *         @var string $provider_type_name     Наименование типа провайдера
     *         @var string $provider_name          Наименование провайдера
     *         @var string $person_id              Идентификатор персоны
     *         @var string $person_name            Имя персоны
     *         @var string $person_surname         Фамилия персоны
     *         @var string $person_lastname        Отчество персоны
     *         @var string $person_phone           Телефон персоны
     *         @var string $person_email           Email персоны
     *         @var string $created_at             Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at             Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function chat(string $chatId): array
    {
        return $this->prepareResponse(
            $this->get("chats/{$chatId}")
        );
    }

    /**
     * Удалить чат по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $chatId        Идентификатор чата
     *
     * @return array [
     *     @var string $date        Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status      Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function deleteChat(string $chatId): array
    {
        return $this->prepareResponse(
            $this->delete("chats/{$chatId}")
        );
    }


    /* ========== Методы, работа с сообщениями ============ */

    /**
     * Получить список сообщений
     * api_key - версия 1
     * api_version - версия 2.0
     * @param array $options    [         Параметры запроса:
     *     @var int|null $limit               Лимит записей, максимум 500 (по умолчанию 20)
     *     @var int|null $offset              Пропустить записей (по умолчанию 0)
     *     @var string|null $provider_type    Поиск по названию типа провайдера
     *     @var string|null $provider_type_id Поиск по идентификатору типа провайдера
     *     @var string|null $provider_id      Поиск по идентификатору провайдера
     *     @var string|null $ex_id            Поиск по внешнему идентификатору сообщения
     *     @var string|null $chat_id          Поиск по идентификатору чата
     *     @var string|null $person_id        Поиск по идентификатору персоны
     *     @var string|null $transfer_to      Поиск по идентификатору сотрудника или отдела сообщения перевода
     *     @var string|null $type             Поиск по типу сообщения: to_client или from_client
     *     @var int|null $read                Поиск по признаку прочтения: 1 - прочитано, 0 - не прочитано
     *     @var string|null $created_after    Начиная с даты, указанной в формате YYYY-mm-dd HH:ii:ss
     *     @var string|null $created_before   До даты, указанной в формате YYYY-mm-dd HH:ii:ss
     * ]
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     *     @var array $pagin [     Объект параметров пагинации запроса:
     *         @var int $limit     Количество записей на запрос
     *         @var int $offset    Кол-во записей пропущено
     *         @var int $total     Кол-во записей всего
     *      ],
     *     @var array $data [                 Массив объектов "Сообщение":
     *          [
     *              @var string $id                     Идентификатор сообщения
     *              @var string $ex_id                  Идентификатор сообщения, полученный от провайдера
     *              @var string $provider_name          Наименование провайдера
     *              @var string $provider_id            Идентификатор провайдера
     *              @var string $provider_type_name     Наименование типа провайдера
     *              @var string $provider_type_id       Идентификатор типа провайдера
     *              @var string $chat_id                Идентификатор чата
     *              @var string $chat_title             Наименование чата
     *              @var string $person_id              Идентификатор персоны
     *              @var string $person_name            Имя персоны
     *              @var string $person_surname         Фамилия персоны
     *              @var string $person_lastname        Отчество персоны
     *              @var string $person_phone           Телефон персоны
     *              @var string $person_email           Email персоны
     *              @var string $type_id                Идентификатор типа сообщения
     *              @var string $type_name              Наименование типа сообщения (Входящее, Исходящее)
     *              @var string $text                   Текст сообщения
     *              @var string $employee_id            Идентификатор сотрудника
     *              @var string $marker                 Идентификатор маркера сообщения (проставляется при рассылках)
     *              @var string $transfer_to           Идентификатор отдела или сотрудника на которого произведен перевод сообщения
     *              @var string $delivered_at          Дата и время доставки исходящего сообщения в формате YYYY-mm-dd HH:ii:ss
     *              @var string $viewed_at             Дата и время прочтения сообщения в формате YYYY-mm-dd HH:ii:ss
     *              @var array $attachments [         Объект вложения:
     *                  @var string $name              Наименование вложения
     *                  @var string $link              Ссылка на скачивание
     *              ],
     *              @var string $created_at            Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *              @var string $updated_at            Дата последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     *          ],
     *          ...
     * ]
     * @throws \Exception
     */
    public function messages(array $options = []): array
    {
        $validator = Validator::make($options, [
            'limit' => 'nullable|integer|min:1|max:500',
            'offset' => 'nullable|integer|min:0',
            'provider_type' => 'nullable|string|max:255',
            'provider_type_id' => 'nullable|string|max:255',
            'provider_id' => 'nullable|string|max:255',
            'ex_id' => 'nullable|string|max:255',
            'chat_id' => 'nullable|string|max:255',
            'person_id' => 'nullable|string|max:255',
            'transfer_to' => 'nullable|string|max:255',
            'type' => 'nullable|string|in:to_client,from_client',
            'read' => 'nullable|integer|in:0,1',
            'created_after' => 'nullable|date_format:Y-m-d H:i:s',
            'created_before' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        $queryParams = array_filter([
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
            'provider_type' => $options['provider_type'] ?? null,
            'provider_type_id' => $options['provider_type_id'] ?? null,
            'provider_id' => $options['provider_id'] ?? null,
            'ex_id' => $options['ex_id'] ?? null,
            'chat_id' => $options['chat_id'] ?? null,
            'person_id' => $options['person_id'] ?? null,
            'transfer_to' => $options['transfer_to'] ?? null,
            'type' => $options['type'] ?? null,
            'read' => $options['read'] ?? null,
            'created_after' => $options['created_after'] ?? null,
            'created_before' => $options['created_before'] ?? null,
        ]);

        return $this->prepareResponse(
            $this->get('messages', $queryParams),
            true
        );
    }

    /**
     * Получить сообщение по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $messageId     Идентификатор сообщения
     *
     * @return array [
     *         @var string $id                     Идентификатор сообщения
     *         @var string $ex_id                  Идентификатор сообщения, полученный от провайдера
     *         @var string $provider_name          Наименование провайдера
     *         @var string $provider_id            Идентификатор провайдера
     *         @var string $provider_type_name     Наименование типа провайдера
     *         @var string $provider_type_id       Идентификатор типа провайдера
     *         @var string $chat_id                Идентификатор чата
     *         @var string $chat_title             Наименование чата
     *         @var string $person_id              Идентификатор персоны
     *         @var string $person_name            Имя персоны
     *         @var string $person_surname         Фамилия персоны
     *         @var string $person_lastname        Отчество персоны
     *         @var string $person_phone           Телефон персоны
     *         @var string $person_email           Email персоны
     *         @var string $type_id                Идентификатор типа сообщения
     *         @var string $type_name              Наименование типа сообщения (Входящее, Исходящее)
     *         @var string $text                   Текст сообщения
     *         @var string $employee_id            Идентификатор сотрудника
     *         @var string $marker                 Идентификатор маркера сообщения (проставляется при рассылках)
     *         @var string $transfer_to           Идентификатор отдела или сотрудника на которого произведен перевод сообщения
     *         @var string $delivered_at          Дата и время доставки исходящего сообщения в формате YYYY-mm-dd HH:ii:ss
     *         @var string $viewed_at             Дата и время прочтения сообщения в формате YYYY-mm-dd HH:ii:ss
     *         @var array $attachments [         Объект вложения:
     *             @var string $name              Наименование вложения
     *             @var string $link              Ссылка на скачивание
     *          ],
     *         @var string $created_at            Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at            Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function message(string $messageId): array
    {
        return $this->prepareResponse(
            $this->get("messages/{$messageId}")
        );
    }

    /**
     * Отправить сообщение
     * api_key - версия 1
     * api_version - версия 2.0
     * @param array $data [             Данные для отправки сообщения:
     *     @var string $phone               Телефон получателя (обязательное поле)
     *     @var string|null $text           Текст сообщения (обязательное, если не указан template)
     *     @var string $provider_id         Идентификатор провайдера (обязательное поле)
     *     @var string|null $template       Наименование согласованного шаблона сообщения Whatsapp (обязательное, если не указан text)
     *     @var array|null $vars            Переменные шаблона сообщения для template (обязательное, если указан template)
     *     @var string|null $mark           Маркировка сообщения рассылки, максимальная длина 36 символов
     *     @var string|null $employee_id    Идентификатор сотрудника
     *     @var string|null $attachment     Ссылка на вложение
     *     @var array|null $buttons         Массив кнопок
     * ]
     *
     * @return array [
     *         @var string $message_id          Идентификатор сообщения (может быть пустым, если отправлена команда(ы))
     *         @var string $provider_name       Наименование провайдера
     *         @var string $provider_id         Идентификатор провайдера
     *         @var string $provider_type_name  Наименование типа провайдера
     *         @var string $provider_type_id    Идентификатор типа провайдера
     *         @var string $chat_id             Идентификатор чата (может быть пустым, если отправлена команда(ы))
     *         @var string $person_id           Идентификатор персоны
     *         @var string $type                Тип сообщения при отправке: только to_client
     *         @var string $text                Текст сообщения
     *         @var string $stack_id            Идентификатор очереди на отправку
     *         @var string $employee_id         Идентификатор сотрудника
     * ]
     * @throws \Exception
     */
    public function sendMessage(array $data): array
    {
        $validator = Validator::make($data, [
            'phone' => 'required|string|max:20',
            'text' => 'nullable|string|max:10000',
            'provider_id' => 'required|string|max:255',
            'template' => 'nullable|string|max:255|required_without:text',
            'vars' => 'nullable|array|required_with:template',
            'mark' => 'nullable|string|max:36',
            'employee_id' => 'nullable|string|max:255',
            'attachment' => 'nullable|string|max:255',
            'buttons' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->post('messages', $data)
        );
    }

    /**
     * Удалить сообщение по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $messageId     Идентификатор сообщения
     *
     * @return array [
     *     @var string $date        Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status      Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function deleteMessage(string $messageId): array
    {
        return $this->prepareResponse(
            $this->delete("messages/{$messageId}")
        );
    }

    /**
     * Проставить сообщению признак "прочитано" по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $messageId     Идентификатор сообщения
     *
     * @return array [
     *     @var string $date        Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status      Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function markMessageAsRead(string $messageId): array
    {
        return $this->prepareResponse(
            $this->put("messages/{$messageId}/read")
        );
    }

    /**
     * Снять у сообщения признак "прочитано" по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $messageId Идентификатор сообщения
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function markMessageAsUnread(string $messageId): array
    {
        return $this->prepareResponse(
            $this->put("messages/{$messageId}/unread")
        );
    }

    /**
     * Получить список шаблонов сообщений Whatsapp Business
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $providerId        Идентификатор провайдера
     * @param int|null $update          Признак обновления кеша шаблонов (1 - обновить, иначе кеш)
     *
     * @return array [
     *      [
     *         @var string $appId               Идентификатор приложения
     *         @var string $category           Категория шаблона (MARKETING, UTILITY, AUTHENTICATION, AUTHENTICATION INT)
     *         @var int $createdOn             Временная метка создания шаблона
     *         @var int $modifiedOn            Временная метка изменения шаблона
     *         @var string $data               Текст шаблона
     *         @var string $elementName        Имя шаблона
     *         @var string $buttonSupported    Тип кнопок в шаблоне (QR, PN, URL)
     *         @var string $containerMeta      Json строка с текстом шаблона и примером текста шаблона в формате Meta
     *         @var string $externalId         Meta идентификатор шаблона
     *         @var string $id                 Идентификатор шаблона
     *         @var string $status             Статус шаблона (Submitted, Approved, Rejected, Paused, Failed, Deactivated)
     *         @var string $languageCode       Язык шаблона
     *         @var string $stage              Стадия шаблона
     *         @var string $meta               Json строка с примером текста шаблона
     *         @var string $namespace          Namespace шаблона WhatsApp
     *         @var int $priority              Приоритет отправки
     *         @var string $quality            Качество шаблона (UNKNOWN, High Quality, Medium Quality, Low Quality)
     *         @var string $templateType       Тип шаблона
     *         @var string $wabaId             Идентификатор канала WhatsApp
     *      ],
     *      ...
     * ]
     * @throws \Exception
     */
    public function getWhatsappTemplates(string $providerId, int|null $update): array
    {
        $data = ['update' => $update];
        $validator = Validator::make($data, [
            'update' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        $queryParams = array_filter([
            'update' => $data['update'] ?? null,
        ]);

        return $this->prepareResponse(
            $this->get("messages/{$providerId}/templates", $queryParams) //получаем сразу data, т.к. нет пагинации
        );
    }

    /**
     * Назначить сотрудника или отдел сообщению
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $messageId         Идентификатор сообщения
     * @param array $data [             Данные для назначения:
     *     @var string|null $department_id Идентификатор отдела (обязательный, если не указан employee_id)
     *     @var string|null $employee_id  Идентификатор сотрудника (обязательный, если не указан department_id)
     * ]
     *
     * @return array [
     *         @var string $message_id Идентификатор сообщения
     *         @var string $type       Тип: department или employee
     *         @var string $id         Идентификатор типа (отдела или сотрудника)
     * ]
     * @throws \Exception
     */
    public function assignToMessage(string $messageId, array $data): array
    {
        $validator = Validator::make($data, [
            'department_id' => 'nullable|string|max:255|required_without:employee_id',
            'employee_id' => 'nullable|string|max:255|required_without:department_id',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->post("messages/{$messageId}/transferTo", $data)
        );
    }


    /* ========== Методы, работа с тегами ============ */

    /**
     * Получить список тегов
     * api_key - версия 1
     * api_version - версия 2.0
     * @param array $options  [      Параметры запроса:
     *     @var int|null $limit               Лимит записей, максимум 500 (по умолчанию 20)
     *     @var int|null $offset              Пропустить записей (по умолчанию 0)
     *     @var string|null $name             Поиск по имени
     *     @var string|null $created_after    Начиная с даты, указанной в формате YYYY-mm-dd HH:ii:ss
     *     @var string|null $created_before   До даты, указанной в формате YYYY-mm-dd HH:ii:ss
     *]
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     *     @var array $pagin [     Объект параметров пагинации запроса:
     *         @var int $limit     Количество записей на запрос
     *         @var int $offset    Кол-во записей пропущено
     *         @var int $total     Кол-во записей всего
     *      ],
     *     @var array $data [     Массив объектов "Тег":
     *          [
     *              @var string $id                 Идентификатор тега
     *              @var string $name               Наименование тега
     *              @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *              @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     *          ],
     *          ...
     * ]
     * @throws \Exception
     */
    public function tags(array $options = []): array
    {
        $validator = Validator::make($options, [
            'limit' => 'nullable|integer|min:1|max:500',
            'offset' => 'nullable|integer|min:0',
            'name' => 'nullable|string|max:255',
            'created_after' => 'nullable|date_format:Y-m-d H:i:s',
            'created_before' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        $queryParams = array_filter([
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
            'name' => $options['name'] ?? null,
            'created_after' => $options['created_after'] ?? null,
            'created_before' => $options['created_before'] ?? null,
        ]);

        return $this->prepareResponse(
            $this->get('tags', $queryParams),
            true
        );
    }

    /**
     * Получить тег по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $tagId         Идентификатор тега
     *
     * @return array [
     *         @var string $id                 Идентификатор тега
     *         @var string $name               Наименование тега
     *         @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function tag(string $tagId): array
    {
        return $this->prepareResponse(
            $this->get("tags/{$tagId}")
        );
    }

    /**
     * Добавить тег
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $name Наименование тега (обязательное поле)
     *
     * @return array [
     *         @var string $id                 Идентификатор тега
     *         @var string $name               Наименование тега
     *         @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function addTag(string $name): array
    {
        $data = ['name' => $name];
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->post('tags', $data)
        );
    }


    /* ========== Методы, работа с провайдерами организации ============ */

    /**
     * Получить список провайдеров организации
     * api_key - версия 1
     * api_version - версия 2.0
     * @param array $options [          Параметры запроса:
     *     @var int|null $limit               Лимит записей, максимум 500 (по умолчанию 20)
     *     @var int|null $offset              Пропустить записей (по умолчанию 0)
     *     @var string|null $name             Поиск по наименованию
     *     @var string|null $type             Поиск по наименованию типа
     *     @var string|null $type_id          Поиск по идентификатору типа провайдера
     *     @var string|null $created_after    Начиная с даты, указанной в формате YYYY-mm-dd HH:ii:ss
     *     @var string|null $created_before   До даты, указанной в формате YYYY-mm-dd HH:ii:ss
     * ]
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     *     @var array $pagin [    Объект параметров пагинации запроса:
     *         @var int $limit     Количество записей на запрос
     *         @var int $offset    Кол-во записей пропущено
     *         @var int $total     Кол-во записей всего
     *      ],
     *     @var array $data [      Массив объектов "Провайдер":
     *          [
     *              @var string $id                 Идентификатор провайдера
     *              @var string $name               Наименование провайдера
     *              @var string $type_id            Идентификатор типа провайдера
     *              @var string $type               Наименование типа провайдера
     *              @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *              @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     *          ],
     *          ...
     * ]
     * @throws \Exception
     */
    public function providers(array $options = []): array
    {
        $validator = Validator::make($options, [
            'limit' => 'nullable|integer|min:1|max:500',
            'offset' => 'nullable|integer|min:0',
            'name' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255',
            'type_id' => 'nullable|string|max:255',
            'created_after' => 'nullable|date_format:Y-m-d H:i:s',
            'created_before' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        $queryParams = array_filter([
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
            'name' => $options['name'] ?? null,
            'type' => $options['type'] ?? null,
            'type_id' => $options['type_id'] ?? null,
            'created_after' => $options['created_after'] ?? null,
            'created_before' => $options['created_before'] ?? null,
        ]);

        return $this->prepareResponse(
            $this->get('providers', $queryParams),
            true
        );
    }

    /**
     * Получить провайдера по ID
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $providerId    Идентификатор провайдера
     *
     * @return array [
     *         @var string $id                 Идентификатор провайдера
     *         @var string $name               Наименование провайдера
     *         @var string $type_id            Идентификатор типа провайдера
     *         @var string $type               Наименование типа провайдера
     *         @var string $created_at         Дата и время создания записи в формате YYYY-mm-dd HH:ii:ss
     *         @var string $updated_at         Дата и время последнего обновления записи в формате YYYY-mm-dd HH:ii:ss
     * ]
     * @throws \Exception
     */
    public function provider(string $providerId): array
    {
        return $this->prepareResponse(
            $this->get("providers/{$providerId}")
        );
    }

    /* ========== Метод, работа с файлом ============ */

    /**
     * Загрузить файл на сервер
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $filePath      Путь к файлу для загрузки
     *
     * @return array [
     *     @var string $url         Ссылка на загруженный файл
     * ]
     * @throws \Exception
     */
    public function uploadFile(string $filePath): array
    {
        $validator = Validator::make(['file' => $filePath], [
            'file' => 'required|file|max:50000|mimes:pdf,doc,docx,png,webp,jpg,jpeg,xls,xlsx,csv,mp3,mp4',
        ]);

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->post('files', ['file' => fopen($filePath, 'r')], true, false, true);
    }

    /* ========== Методы, работа с Webhook ============ */

    /**
     * Получить установленный Webhook
     * api_key - версия 1
     * api_version - версия 2.0
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     *     @var string $url        Адрес URL Webhook
     *     @var array $events      Массив событий подписки
     * ]
     * @throws \Exception
     */
    public function webhook(): array
    {
        return $this->prepareResponse(
            $this->get('webhooks')
        );
    }

    /**
     * Добавить или изменить Webhook
     * api_key - версия 1
     * api_version - версия 2.0
     * @param string $url     Адрес URL Webhook
     * @param array $events   Массив событий подписки
     *
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     *     @var string $url        Адрес URL Webhook
     *     @var array $events      Массив событий подписки
     * ]
     * @throws \Exception
     */
    public function setWebhook(string $url, array $events): array
    {
        $validator = Validator::make(
            ['url' => $url, 'events' => $events],
            [
                'url' => 'required|url',
                'events' => 'required|array',
                'events.*' => 'in:new_message,new_status,new_error',
            ]
        );

        if ($validator->fails()) {
            throw new \Exception("Ошибка валидации: " . implode(", ", $validator->errors()->all()));
        }

        return $this->prepareResponse(
            $this->post('webhooks', [
                'url' => $url,
                'events' => $events,
            ])
        );
    }

    /**
     * Удалить Webhook
     * api_key - версия 1
     * api_version - версия 2.0
     * @return array [
     *     @var string $date       Дата и время сервера в формате YYYY-mm-dd HH:ii:ss
     *     @var string $status     Статус выполнения: success или error
     * ]
     * @throws \Exception
     */
    public function deleteWebhook(): array
    {
        return $this->prepareResponse(
            $this->delete('webhooks')
        );
    }



    /* ========== Вспомогательные методы ============ */

    /**
    Универсальный метод для отправки DELETE-запросов.
     *
     * @param string $endpoint      Эндпоинт API
     * @param array $data
     * @param bool $isV1Token       Использовать ли токен v1
     * @param bool $isV1Api         Использовать ли базовый URL v1
     * @return array
     * @throws \Exception
     */
    private function delete(string $endpoint, array $data = [], bool $isV1Token = true, bool $isV1Api = false): array
    {
        try {
            list($token, $baseUrl) = $this->prepareBeforeRequest($isV1Token, $isV1Api);
            $response = Http::withHeaders([
                'Authorization' => $token,
                'Content-Type' => 'application/json',
            ])->delete($baseUrl . $endpoint, $data);

            return $response->throw()->json();
        } catch (RequestException $e) {
            throw new \Exception(
                "Ошибка {$endpoint}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Универсальный метод для отправки POST-запросов.
     *
     * @param string $endpoint      Эндпоинт API
     * @param array $data           Тело запроса (может содержать файлы)
     * @param bool $isV1Token       Использовать ли токен v1
     * @param bool $isV1Api         Использовать ли базовый URL v1
     * @param bool $isMultipart     Использовать ли multipart/form-data для отправки файлов
     * @return array
     * @throws \Exception
     */
    private function post(string $endpoint, array $data = [], bool $isV1Token = true, bool $isV1Api = false, bool $isMultipart = false): array
    {
        try {
            list($token, $baseUrl) = $this->prepareBeforeRequest($isV1Token, $isV1Api);
            $headers = [
                'Authorization' => $token,
            ];
            $client = Http::withHeaders($headers);

            if ($isMultipart) {
                $response = $client->attach($data)->post($baseUrl . $endpoint);
            } else {
                $headers['Content-Type'] = 'application/json';
                $response = $client->post($baseUrl . $endpoint, $data);
            }

            return $response->throw()->json();
        } catch (RequestException $e) {
            throw new \Exception(
                "Ошибка {$endpoint}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Универсальный метод для отправки PUT-запросов
     *
     * @param string $endpoint      Эндпоинт API
     * @param array $data           Тело запроса
     * @param bool $isV1Token       Использовать ли токен v1
     * @param bool $isV1Api         Использовать ли базовый URL v1
     * @return array
     * @throws \Exception
     */
    private function put(string $endpoint, array $data = [], bool $isV1Token = true, bool $isV1Api = false): array
    {
        try {
            list($token, $baseUrl) = $this->prepareBeforeRequest($isV1Token, $isV1Api);
            $response = Http::withHeaders([
                'Authorization' => $token,
                'Content-Type' => 'application/json',
            ])->put($baseUrl . $endpoint, $data);

            return $response->throw()->json();
        } catch (RequestException $e) {
            throw new \Exception(
                "Ошибка {$endpoint}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Универсальный метод для отправки GET-запросов
     * @param string $endpoint      Эндпоинт API
     * @param array $queryParams    Параметры запроса
     * @param bool $isV1Token       Использовать ли токен v1
     * @param bool $isV1Api         Использовать ли базовый URL v1
     *
     * @return array
     * @throws \Exception
     */
    private function get(string $endpoint, array $queryParams = [], bool $isV1Token = true, bool $isV1Api = false): array
    {
        try {
            list($token, $baseUrl) = $this->prepareBeforeRequest($isV1Token, $isV1Api);
            $response = Http::withHeaders([
                'Authorization' => $token,
                'Content-Type' => 'application/json',
            ])->get($baseUrl . $endpoint, $queryParams);

            return $response->throw()->json();
        } catch (RequestException $e) {
            throw new \Exception(
                "Ошибка {$endpoint}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Получаем данные для передачи запроса
     * @param bool $isV1Token   Нужен токен v1?
     * @param bool $isV1Api     Нужено api v1?
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
     * Обрабатываем ответ и отдаём нужное
     * @param array $response   Ответ
     * @param bool $isList      Это список?
     * @return array
     * @throws \Exception
     */
    private function prepareResponse(array $response, bool $isList = false): array
    {
        $response = $response['response'];

        if ($response['status'] === 'error') {
            throw new \Exception("Ошибка: " . $response['error']);
        }

        return !$isList && !empty($response['data']) ? $response['data'] : $response;
    }
}
