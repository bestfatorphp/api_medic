<?php

namespace App\Console\Commands;

use App\Helpers\DBHelper;
use App\Logging\CustomLog;
use App\Models\ActionMT;
use App\Models\ActivityMT;
use App\Models\CommonDatabase;
use App\Models\UserMT;
use App\Traits\WriteLockTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\DB;


/**
 * Пока у нас не будет на стороне нового МТ, при изменениях любой связи, изменяться updated_at у пользователя, придётся прокручивать всех пользователей!
 * Команда очень долгая.
 */
class ImportNewMTUsers extends Command
{
    use WriteLockTrait;

    protected $signature = 'import:new-mt-users
                            {--updated_after= : Дата последнего обновления в формате d.m.Y}
                            {--pageSize=100 : Колличество записей за один запрос}';

    protected $description = 'Импорт пользователей нового сайта МедТач, в ограниченной памяти';


    /**
     * Колличество записей за один запрос
     * @var int
     */
    private int $pageSize;

    /**
     * Размер пакета
     * @var int
     */
    private int $chunkSize = 500;

    /**
     * @return int
     */
    public function handle(): int
    {
        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M'); //установливаем ограничение по памяти
        set_time_limit(0); //без ограничения времени выполнения
        DB::disableQueryLog(); //отключаем логирование запросов

        $updatedAfter = $this->option('updated_after');
        $this->pageSize = $this->option('pageSize');
        $queryParams = [
            'updated_after' => $updatedAfter
                ? Carbon::parse($updatedAfter)->startOfDay()->format('Y-m-d H:i:s.v')
                : Carbon::now()->subDay()->startOfDay()->format('Y-m-d H:i:s.v'),
            'order' => 'updated_at',
            'pageSize' => $this->pageSize,
        ];

        try {
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Начало импорта');
            $this->processUserData($queryParams);
            $this->processEventsData($queryParams);
            $this->processQuizData($queryParams);
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Импорт завершен');
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи');
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Обработываем данные пользователей
     * @throws \Exception
     */
    private function processUserData(array $queryParams)
    {
        $this->info('Извлекаем пользователей...');

        $hasMorePages = true;
        $page = 1;
        $totalResponses = 0;
        $totalProcessed = 0;
        $countBatchInsert = 0;

        $usersMTBatch = [];
        $commonDBBatch = [];

        $EMAILS = [];

        try {
            while ($hasMorePages) {
                ++$totalResponses;
                $this->info("Запрос данных - $totalResponses");
                $response = $this->getData('outer/user', $queryParams, $page);

                if (empty($data = $response['data'])) {
                    break;
                }
                foreach ($data as $userData) {
                    $email = $userData['email'];

                    if (in_array($email, $EMAILS)) {
                        continue;
                    }

                    $EMAILS[] = $email;

                    $fullName = trim(implode(' ', array_filter([
                        $userData['last_name'] ?? null,
                        $userData['first_name'] ?? null,
                        $userData['middle_name'] ?? null
                    ])));

                    $usersMTBatch[] = $this->prepareMtUserData($userData, $fullName);
                    $commonDBBatch[] = $this->prepareCommonDBData($userData, $fullName);

                    $totalProcessed++;
                }

                if (count($usersMTBatch) >= $this->chunkSize) {
                    ++$countBatchInsert;
                    $this->info("Пакетная вставка - $countBatchInsert (всего записей $totalProcessed)");
                    $this->insertUsersBatch($usersMTBatch, $commonDBBatch);
                    $usersMTBatch = [];
                    $commonDBBatch = [];
                    $EMAILS = [];
                    gc_mem_caches();
                }

                //проверяем, есть ли следующая страница
                $hasMorePages = !empty($response['next_page_url']);
                $page++;

                //задержка между запросами
                sleep(1);
            }

            if (!empty($usersMTBatch)) {
                ++$countBatchInsert;
                $this->info("Пакетная вставка - $countBatchInsert");
                $this->insertUsersBatch($usersMTBatch, $commonDBBatch);
            }

            $this->info("Извлечено $totalProcessed пользователей");
        } catch (\Exception $e) {
            $this->error("Ошибка получения данных: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Пакетная вставка данных пользователей
     * @param array $usersMTBatch
     * @param array $commonDBBatch
     */
    private function insertUsersBatch(array $usersMTBatch, array $commonDBBatch)
    {
        DB::transaction(function () use ($usersMTBatch, $commonDBBatch) {

            $this->withTableLock('users_mt', function () use ($usersMTBatch) {
                DBHelper::upsertWithNullCheck(
                    new UserMT(),
                    $usersMTBatch,
                    ['email'],
                    ['new_mt_id', 'full_name', 'email', 'registration_date', 'gender', 'birth_date', 'specialty', 'phone', 'place_of_employment', 'city']
                );
            });

            $emails = array_column($usersMTBatch, 'email');
            $usersIdByEmail = UserMT::query()
                ->whereIn('email', $emails)
                ->pluck('id', 'email')
                ->toArray();

            $commonDBBatch = array_map(
                function ($item) use ($usersIdByEmail) {
                    $item['mt_user_id'] = $usersIdByEmail[$item['email']];
                    return $item;
                },
                $commonDBBatch
            );
            $this->withTableLock('common_database', function () use ($commonDBBatch) {
                DBHelper::upsertWithNullCheck(
                    new CommonDatabase(),
                    $commonDBBatch,
                    ['email'],
                    ['full_name', 'mt_user_id', 'registration_date', 'verification_status', 'email_status', 'username', 'gender', 'birth_date', 'specialty', 'phone', 'city']
                );
            });
        });
    }

    /**
     * Подготавливаем данные для сохранения в users_mt
     * @param array $userData
     * @param string $fullName
     * @return array
     */
    protected function prepareMtUserData(array $userData, string $fullName): array
    {
        return [
            'new_mt_id' => $userData['id'],
            'full_name' => $fullName,
            'email' => $userData['email'],
            'registration_date' => Carbon::parse($userData['created_at'])->format('Y-m-d'),
            'gender' => $userData['gender'] ?? null,
            'birth_date' => isset($userData['birthdate']) ? Carbon::parse($userData['birthdate'])->format('Y-m-d') : null,
            'specialty' => $userData['speciality'] ?? null,
            'phone' => $userData['phone'] ?? null,
            'place_of_employment' => $userData['workplace'] ?? null,
            'city' => $userData['city'] ?? null
        ];
    }

    /**
     * Подготавливаем данные для сохранения в common_database
     * @param array $userData
     * @param string $fullName
     * @return array
     */
    protected function prepareCommonDBData(array $userData, string $fullName): array
    {
        return [
            'full_name' => $fullName,
            'email' => $userData['email'],
            'mt_user_id' => null,
            'registration_date' => Carbon::parse($userData['created_at'])->format('Y-m-d H:i:s'),
            'verification_status' => $userData['email_verified_at'] ? 'verified' : 'not_verified',
            'email_status' => $userData['activated'] ? 'active' : 'inactive',
            'username' => $userData['name'] ?? null,
            'gender' => $userData['gender'] ?? null,
            'birth_date' => isset($userData['birthdate']) ? Carbon::parse($userData['birthdate'])->format('Y-m-d H:i:s') : null,
            'specialty' => $userData['speciality'] ?? null,
            'phone' => $userData['phone'] ?? null,
            'city' => $userData['city'] ?? null
        ];
    }

    /**
     * Обрабатываем события (общая таблица - activities_mt)
     * @throws \Exception
     */
    private function processEventsData(array $queryParams)
    {
        $this->info('Извлекаем события...');

        $hasMorePages = true;
        $page = 1;
        $totalProcessed = 0;

        while ($hasMorePages) {
            try {
                $response = $this->getData('outer/event', $queryParams, $page);

                if (empty($data = $response['data'])) {
                    break;
                }

                //обработка данных законченных событий
                foreach ($data as $eventData) {
                    $eventId = $eventData['id'];
                    if (is_null($eventData['finished_at'])) {
                        $this->info("Не законченное событие с ID $eventId. Пропускаю...");
                        continue;
                    }

                    $format = $eventData['format'];
                    $activity = $this->withTableLock('activities_mt', function () use ($eventData) {
                        $activityData = $this->prepareActivityEventData($eventData);
                        return ActivityMT::firstOrCreate(
                            $activityData,
                            $activityData
                        );
                    });

                    $actionData = [
                        'activity_id' => $activity->id,
                    ];

                    //формируем действия offline
                    if (in_array($format, ['hybrid', 'offline'])) {
                        $this->processActionsEvent('offline', $eventData['id'], $actionData);
                    }

                    //формируем действия online
                    if (in_array($format, ['hybrid', 'online'])) {
                        $this->processActionsEvent('online', $eventData['id'], $actionData);
                    }

                    $totalProcessed++;
                }

                //проверяем, есть ли следующая страница
                $hasMorePages = !empty($response['next_page_url']);
                $page++;

                //задержка между запросами
                sleep(1);

            } catch (\Exception $e) {
                $this->error("Ошибка получения данных: " . $e->getMessage());
                throw $e;
            }
        }

        $this->info("Извлечено $totalProcessed событий");
    }

    /**
     * Подготавливаем данные активности события
     * @param array $activityData
     * @return array
     */
    #[ArrayShape(['type' => "mixed", 'name' => "mixed", 'date_time' => "string", 'is_online' => "bool"])]
    private function prepareActivityEventData(array $activityData): array
    {
        return [
            'type' => $activityData['type'],
            'name' => $activityData['name'],
            'date_time' => Carbon::parse($activityData['started_at'])->format('Y-m-d H:i:s'),
            'is_online' => in_array($activityData['format'], ['hybrid', 'online']),
        ];
    }

    /**
     * Обрабатываем действия пользователей в событии
     * @param string $format            Формат события (online, offline)
     * @param int $eventId              ID события
     * @param array $actionMTData       Стартовые данные действия для сохранения
     * @throws \Exception
     */
    private function processActionsEvent(string $format, int $eventId, array $actionMTData)
    {
        $this->info("Извлекаем действия пользователей в событии с id $eventId, формат $format...");

        $queryParams = [
            'pageSize' => $this->pageSize,
        ];

        $hasMorePages = true;
        $page = 1;
        $totalProcessed = 0;

        $usersMTData = [];

        $actionsToInsert = [];
        $countBatchInsert = 0;

        try {
            while ($hasMorePages) {
                $response = $this->getData("outer/event/$eventId/$format", $queryParams, $page);

                if (empty($data = $response['data'])) {
                    break;
                }

                foreach ($data as $actionData) {
                    $newMTId = $actionData['user_id'];
                    if (empty($usersMTData[$newMTId])) {
                        /** @var UserMT $user */
                        $user = UserMT::query()->where('new_mt_id', $newMTId)->select('id')->first();
                        if (!$user) {
                            $this->info("Не найден пользователь. Пропускаю...");
                            continue;
                        }
                        $usersMTData[$newMTId] = $user->id;
                    }

                    $actionMTData['mt_user_id'] = $usersMTData[$newMTId];

                    //устанавливаем дефолтное значение, если created_at null, чтобы при вставке не было дублей
                    $actionMTData['date_time'] = Carbon::parse($actionData['created_at'] ?? '1970-01-01 00:00:00')->format('Y-m-d H:i:s');

                    //формируем пакет для вставки
                    $actionsToInsert[] = $actionMTData;

                    $totalProcessed++;
                }

                if ($totalProcessed % $this->chunkSize === 0) {
                    ++$countBatchInsert;
                    $this->info("Пакетная вставка - $countBatchInsert");
                    $this->insertActions($actionsToInsert);
                    $usersMTData = [];
                    $actionsToInsert = [];
                    gc_mem_caches(); //очищаем кэши памяти Zend Engine
                }

                //проверяем, есть ли следующая страница
                $hasMorePages = !empty($response['next_page_url']);
                $page++;

                //задержка между запросами
                sleep(1);
            }

            //вставляем оставшиеся записи
            if (!empty($actionsToInsert)) {
                ++$countBatchInsert;
                $this->info("Пакетная вставка - $countBatchInsert");
                $this->insertActions($actionsToInsert);
            }
        } catch (\Exception $e) {
            $this->error("Ошибка получения данных: " . $e->getMessage());
            throw $e;
        }

        $this->info("Извлечено $totalProcessed действий пользователей");
    }

    /**
     * Вставляем действия пользователей пакетом
     * @param array $actionsToInsert
     */
    private function insertActions(array $actionsToInsert)
    {
        $this->withTableLock('actions_mt', function () use ($actionsToInsert) {
            ActionMT::insertOrIgnore($actionsToInsert);
        });
    }

    /**
     * Обрабатываем квизы (общая таблица - activities_mt)
     * @throws \Exception
     */
    private function processQuizData(array $queryParams)
    {
        $this->info("Извлекаем квизы...");

        $hasMorePages = true;
        $page = 1;
        $totalProcessed = 0;

        $usersMTData = [];

        $actionsToInsert = [];
        $countBatchInsert = 0;

        try {
            while ($hasMorePages) {
                $response = $this->getData("outer/qts", $queryParams, $page);

                if (empty($data = $response['data'])) {
                    break;
                }

                foreach ($data as $quizData) {
                    $activity = $this->withTableLock('activities_mt', function () use ($quizData) {
                        $activityData = [
                            'type' => 'Квиз',
                            'name' => $quizData['name'],
                        ];
                        return ActivityMT::firstOrCreate(
                            $activityData,
                            $activityData
                        );
                    });

                    $newMTId = $quizData['user_id'];
                    if (empty($usersMTData[$newMTId])) {
                        /** @var UserMT $user */
                        $user = UserMT::query()->where('new_mt_id', $newMTId)->select('id')->first();
                        if (!$user) {
                            $this->info("Не найден пользователь. Пропускаю...");
                            continue;
                        }
                        $usersMTData[$newMTId] = $user->id;
                    }

                    $actionData = [
                        'activity_id' => $activity->id,
                        'mt_user_id' => $usersMTData[$newMTId],
                        'date_time' => Carbon::parse($quizData['created_at'])->format('Y-m-d H:i:s'),
                    ];

                    //формируем пакет для вставки
                    $actionsToInsert[] = $actionData;

                    $totalProcessed++;
                }

                if ($totalProcessed % $this->chunkSize === 0) {
                    ++$countBatchInsert;
                    $this->info("Пакетная вставка - $countBatchInsert");
                    $this->insertActions($actionsToInsert);
                    $usersMTData = [];
                    $actionsToInsert = [];
                    gc_mem_caches(); //очищаем кэши памяти Zend Engine
                }

                //проверяем, есть ли следующая страница
                $hasMorePages = !empty($response['next_page_url']);
                $page++;

                //задержка между запросами
                sleep(1);
            }

            //вставляем оставшиеся записи
            if (!empty($actionsToInsert)) {
                ++$countBatchInsert;
                $this->info("Пакетная вставка - $countBatchInsert");
                $this->insertActions($actionsToInsert);
            }
        } catch (\Exception $e) {
            $this->error("Ошибка получения данных: " . $e->getMessage());
            throw $e;
        }

        $this->info("Извлечено $totalProcessed квизов");
    }

    /**
     * Делаем запрос на сторонний сервис
     * @param string $endpoint  Эндпоинт
     * @param array $body       Body гет-запроса
     * @param int $page         Номер страницы пагинации
     * @return mixed
     * @throws \Exception
     */
    private function getData(string $endpoint, array $body, int $page): mixed
    {
        try {
            $url = env('NEW_MT_URL_1') . $endpoint;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NEW_MT_OUTER_TOKEN'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->send('GET', $url . "?page=$page", [
                'body' => json_encode($body), //повторяем запрос Postman
            ]);

            if (!$response->successful()) {
                throw new \Exception("API request, по [$url], завершился с ошибкой. Status: {$response->status()}. Response: " . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            $this->error("Ошибка в getData: " . $e->getMessage());
            throw $e;
        }
    }
}
