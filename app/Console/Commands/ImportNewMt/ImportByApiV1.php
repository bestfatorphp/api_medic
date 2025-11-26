<?php

namespace App\Console\Commands\ImportNewMt;

use App\Logging\CustomLog;
use App\Models\ActionMT;
use App\Models\ActivityMT;
use App\Models\CommonDatabase;
use App\Models\UserMT;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\DB;


/**
 * Пока у нас не будет на стороне нового МТ, при изменениях любой связи, изменяться updated_at у пользователя, придётся прокручивать всех пользователей!
 * Команда очень долгая.
 */
class ImportByApiV1 extends Common
{

    protected $signature = 'import:new-mt-users
                            {--updated_after= : Дата последнего обновления в формате d.m.Y}
                            {--pageSize=500 : Колличество записей за один запрос}
                            {--onlyUsers=0 : Только пользователей}';

    protected $description = 'Импорт пользователей нового сайта МедТач, в ограниченной памяти';

    /**
     * Версия апи
     * @var int
     */
    protected int $apiVersion = 1;

    protected bool $needLogs = false;

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();
        $this->needLogs = env('APP_ENV') !== 'production';
    }

    /**
     * @return int
     */
    public function handle(): int
    {
        $updatedAfter = $this->option('updated_after');
        $this->pageSize = $this->option('pageSize');
        $onlyUsers = $this->option('onlyUsers');

        $queryParams = [
            'pageSize' => $this->pageSize,
        ];

        if (!$onlyUsers) {
            $queryParams = array_merge([
                'updated_after' => $updatedAfter
                    ? Carbon::parse($updatedAfter)->startOfDay()->format('Y-m-d H:i:s.v')
                    : Carbon::now()->subDay()->startOfDay()->format('Y-m-d H:i:s.v'),
                'order' => 'updated_at',
            ], $queryParams);
        } else {
            $queryParams = array_merge([
                "orderBy" => "asc",
                'order' => 'id',
            ], $queryParams);
        }

        try {
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Начало импорта');
            $this->processUserData($queryParams);
            if (!(bool)$onlyUsers) {
                $this->processEventsFCData($queryParams);
                $this->processEventsData($queryParams);
                $this->processQuizData($queryParams);
            }
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

                if (empty($response) || empty($data = $response['data'])) {
                    break;
                }
                foreach ($data as $userData) {
                    $email = $userData['email'];

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $this->info("Пропущен пользователь с невалидным email: {$email}");
                        continue;
                    }

                    $email = strtolower($email);
                    $userData['email'] = $email;

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

                if (count($usersMTBatch) >= static::BATCH_SIZE) {
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
                UserMT::upsertWithMutators(
                    $usersMTBatch,
                    ['email'],
                    [
//                        'new_mt_id',
//                        'old_mt_id',
//                        'full_name',
//                        'email',
//                        'registration_date',
                        'gender',
                        'birth_date',
                        'specialty',
                        'phone',
                        'place_of_employment',
                        'city',
                        'last_login',
                        'medtouch_uuid',
                        'oralink_uuid'
                    ]
                );
            }, true);

            $emails = array_column($usersMTBatch, 'email');
            $usersIdByEmail = UserMT::query()
                ->whereIn('email', $emails)
                ->pluck('id', 'email')
                ->toArray();

            $commonDBBatch = array_map(
                function ($item) use ($usersIdByEmail) {
                    $item['mt_user_id'] = $usersIdByEmail[$item['email']] ?? null;
                    return $item;
                },
                $commonDBBatch
            );
            $this->withTableLock('common_database', function () use ($commonDBBatch) {
                CommonDatabase::upsertWithMutators(
                    $commonDBBatch,
                    ['email'],
                    [
//                        'full_name',
//                        'mt_user_id',
//                        'new_mt_id',
//                        'old_mt_id',
//                        'registration_date',
//                        'verification_status',
                        'email_status',
                        'username',
                        'gender',
                        'birth_date',
                        'specialty',
                        'phone',
                        'city',
                        'last_login'
                    ]
                );
            }, true);
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
            'old_mt_id' => $userData['medtouch_id'],
            'full_name' => $fullName,
            'email' => $userData['email'],
            'registration_date' => $userData['created_at'],
            'gender' => $userData['gender'] ?? null,
            'birth_date' => isset($userData['birthdate']) ? Carbon::parse($userData['birthdate'])->format('Y-m-d') : null,
            'specialty' => $userData['speciality'] ?? null,
            'phone' => $userData['phone'] ?? null,
            'place_of_employment' => $userData['workplace'] ?? null,
            'city' => $userData['city'] ?? null,
            'last_login' => $userData['last_login'] ? Carbon::parse($userData['last_login']) : null,
            'medtouch_uuid' => !empty($userData['medtouch_uuid']) ? $userData['medtouch_uuid'] : null,
            'oralink_uuid' => !empty($userData['oralink_uuid']) ? $userData['oralink_uuid'] : null,
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
            'new_mt_id' => $userData['id'],
            'old_mt_id' => $userData['medtouch_id'],
            'full_name' => $fullName,
            'email' => $userData['email'],
            'mt_user_id' => null,
            'registration_date' => $userData['created_at'],
//            'verification_status' => $userData['email_verified_at'] ? 'verified' : 'not_verified',
            'email_status' => $userData['activated'] ? 'active' : 'inactive',
            'username' => $userData['name'] ?? null,
            'gender' => $userData['gender'] ?? null,
            'birth_date' => isset($userData['birthdate']) ? Carbon::parse($userData['birthdate'])->format('Y-m-d H:i:s') : null,
            'specialty' => $userData['speciality'] ?? null,
            'phone' => $userData['phone'] ?? null,
            'city' => $userData['city'] ?? null,
            'last_login' => $userData['last_login'] ? Carbon::parse($userData['last_login']) : null,
        ];
    }

    /**
     * Обрабатываем face cast события (общая таблица - activities_mt)
     * @throws \Exception
     */
    private function processEventsFCData(array $queryParams)
    {
        $this->info('Извлекаем события FaceCast...');

        $hasMorePages = true;
        $page = 1;
        $totalProcessed = 0;

        $queryParams['orderBy'] = 'asc';
        $queryParams['order'] = 'event_id';

        $batchActions = [];

        while ($hasMorePages) {
            try {
                $response = $this->getData('outer/facecast-stats-total', $queryParams, $page);

                if (empty($response) || empty($data = $response['data'])) {
                    break;
                }

                //обработка данных законченных событий
                foreach ($data as $fcData) {
                    $this->info("Извлекаю фейскаст...");
                    /** @var UserMT $user */
                    $user = UserMT::query()->where('new_mt_id', $fcData['user']['id'])->exists();
                    if (!$user) {
                        $this->info("Не найден пользователь. Пропускаю...");
                        continue;
                    }
                    $eventData = $fcData['event'];
                    $eventId = $eventData['id'];
                    if (is_null($eventData['finished_at'])) {
                        $this->info("Не законченное событие с ID $eventId. Пропускаю...");
                        continue;
                    }

                    $issetDay = isset($fcData['day']);
                    $issetRoom = isset($fcData['room']);
                    $additionalEventName = !$issetDay && !$issetRoom ? '' : ' (';
                    if ($issetDay) {
                        $additionalEventName .= 'day - ' .$fcData['day']['name'] . (!$issetRoom ? ')' : ', ');
                    }
                    if ($issetRoom) {
                        $additionalEventName .= 'room - ' . $fcData['room']['name'] . ')';
                    }
                    $activityData = $this->prepareActivityEventData($eventData, $additionalEventName);

                    $activity = null;

                    /** @var ActivityMT $activity */
                    $activity = ActivityMT::query()
                        ->where('name', '=', $eventData['name'])
                        ->where('event_id', '=', $activityData['event_id'])
                        ->first();

                    if ($activity) {
                        $this->withTableLock('activities_mt', function () use ($activity, $activityData) {
                            $activity->update($activityData);
                        });
                    } else {
                        $activity = ActivityMT::query()
                            ->where('name', '=', $activityData['name'])
                            ->where('event_id', '=', $activityData['event_id'])
                            ->first();
                        if (!$activity) {
                            $activity = $this->withTableLock('activities_mt', function () use ($activityData) {
                                return ActivityMT::create($activityData);
                            });
                        }
                    }

                    if (!$activity) {
                        $this->error("Не удалось создать/найти активность для события $eventId");
                        continue;
                    }

                    //подготавливае действия
                    $batchActions[] = $this->prepareActionFcData($fcData, $activity->id);

                    $totalProcessed++;
                }

                if (count($batchActions) >= self::BATCH_SIZE) {
                    $this->insertActions($batchActions);
                    $batchActions = [];
                    gc_mem_caches(); //очищаем кэши памяти Zend Engine
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

        if (count($batchActions) > 0) {
            $this->insertActions($batchActions);
            $batchActions = [];
            gc_mem_caches(); //очищаем кэши памяти Zend Engine
        }

        $this->info("Извлечено $totalProcessed событий");
    }

    /**
     * @throws \Exception
     */
    private function processEventsData(array $queryParams)
    {
        $this->info('Извлекаем события...');

        $hasMorePages = true;
        $page = 1;
        $totalProcessed = 0;

        $queryParams = [];

        $queryParams['orderBy'] = 'asc';
        $queryParams['order'] = 'id';


        while ($hasMorePages) {
            try {
                $response = $this->getData('outer/event', $queryParams, $page);

                if (empty($response) || empty($data = $response['data'])) {
                    break;
                }

                //обработка данных законченных событий
                foreach ($data as $eventData) {
                    $eventId = $eventData['id'];

                    /** @var ActivityMT $activity */
                    $activity = ActivityMT::query()->where('event_id', '=', $eventId)->first();

                    if (!$activity) {
                        $activityData = $this->prepareActivityEventData($eventData);

                        $activity = $this->withTableLock('activities_mt', function () use ($eventData, $activityData) {
                            return ActivityMT::create($activityData);
                        });
                    }

                    $this->processEventRegisteredUsers($eventId, [
                        'activity_id' => $activity->id,
                        'format' => $eventData['format'],
                        'started_at' => $eventData['started_at']
                    ]);

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
     * Формируем действия участников не посетивших мерроприятие (duration 0, result 0)
     * @param int $eventId
     * @param array $data
     * @throws \Exception
     */
    private function processEventRegisteredUsers(int $eventId, array $data)
    {
        $format = $data['format'];
        $actionData = [
            'activity_id' => $data['activity_id'],
            'date_time' => Carbon::parse($data['started_at'])->format('Y-m-d H:i:s'),
            'duration' => 0,
            'result' => 0
        ];

        //формируем действия участников offline
        if (in_array($format, ['hybrid', 'offline'])) {
            $this->processActionsEvent('offline', $eventId, $actionData);
        }

        //формируем действия участников online
        if (in_array($format, ['hybrid', 'online'])) {
            $this->processActionsEvent('online', $eventId, $actionData);
        }
    }

    /**
     * Подготавливаем данные активности события
     * @param array $activityData
     * @param string $additionalEventName
     * @return array
     */
    #[ArrayShape(['type' => "mixed", 'name' => "string", 'date_time' => "string", 'is_online' => "bool", 'event_id' => "mixed"])]
    private function prepareActivityEventData(array $activityData, string $additionalEventName = ''): array
    {
        return [
            'type' => $activityData['type'],
            'name' => $activityData['name'] . $additionalEventName,
            'date_time' => $activityData['started_at'] ? Carbon::parse($activityData['started_at'])->format('Y-m-d H:i:s') : null,
            'is_online' => in_array($activityData['format'], ['hybrid', 'online']),
            'event_id' => $activityData['id']
        ];
    }

    /**
     * @param array $fcData
     * @param int $activityId
     * @return array
     */
    #[ArrayShape(['email' => "mixed", 'mt_user_id' => "mixed", 'activity_id' => "int", 'date_time' => "string", 'duration' => "string", 'result' => "float"])]
    private function prepareActionFcData(array $fcData, int $activityId): array
    {
        //По мероприятиям, если я правильно понимаю, то тут для каждого мероприятия есть started_at+finished_at, значит мы можем посчитать сколько мероприятие шло
        //а дальше длительность просмотра/на длительность мероприятия
        //Это будет результат мероприятия

        $totalWatchSeconds = $fcData['minutes_total_watched'] * 60;
        $eventDuration = $this->getEventDurationInSeconds(
            $fcData['event']['started_at'],
            $fcData['event']['finished_at']
        );

        return [
            'email' => strtolower($fcData['user']['email']),
            'mt_user_id' => $fcData['user']['id'],
            'activity_id' => $activityId,
            'date_time' => Carbon::parse($fcData['created_at'])->format('Y-m-d H:i:s'),
            'duration' => $this->formatDuration($totalWatchSeconds),
            'result' => $this->calculateEventResult($totalWatchSeconds, $eventDuration)
        ];
    }

    /**
     * Регистрация пользователей на событие
     * @param string $format            Формат события (online, offline)
     * @param int $eventId              ID события
     * @param array $actionMTData       Стартовые данные действия для сохранения
     * @throws \Exception
     */
    private function processActionsEvent(string $format, int $eventId, array $actionMTData)
    {
        $this->info("Извлекаем регистрации пользователей в событии с id $eventId, формат $format...");

        $actionMTData['format'] = $format;

        $queryParams = [
            'pageSize' => $this->pageSize,
        ];

        $hasMorePages = true;
        $page = 1;
        $totalProcessed = 0;

        $usersMTData = [];

        $batchActions = [];

        try {
            while ($hasMorePages) {
                $response = $this->getData("outer/event/$eventId/$format", $queryParams, $page);

                if (empty($response) || empty($data = $response['data'])) {
                    break;
                }

                foreach ($data as $actionData) {
                    $newMTId = $actionData['user_id'];
                    if (empty($usersMTData[$newMTId])) {
                        /** @var UserMT $user */
                        $user = UserMT::query()->where('new_mt_id', $newMTId)->select(['id', 'email'])->first();
                        if (!$user) {
                            $this->info("Не найден пользователь. Пропускаю...");
                            continue;
                        }

                        $usersMTData[$newMTId] = ['id' => $user->id, 'email' => $user->email];
                    }

                    $actionMTData['email'] = $usersMTData[$newMTId]['email'];
                    $actionMTData['mt_user_id'] = $usersMTData[$newMTId]['id'];

                    //формируем пакет для вставки
                    $batchActions[] = $actionMTData;

                    $totalProcessed++;
                }

                if ($totalProcessed % static::BATCH_SIZE === 0) {
                    $this->insertActions($batchActions);
                    $usersMTData = [];
                    $batchActions = [];
                    gc_mem_caches(); //очищаем кэши памяти Zend Engine
                }

                //проверяем, есть ли следующая страница
                $hasMorePages = !empty($response['next_page_url']);
                $page++;

                //задержка между запросами
                sleep(1);
            }

            //вставляем оставшиеся записи
            if (!empty($batchActions)) {
                $this->insertActions($batchActions);
            }
        } catch (\Exception $e) {
            $this->error("Ошибка получения данных: " . $e->getMessage());
            throw $e;
        }

        $this->info("Извлечено $totalProcessed регистраций");
    }

    /**
     * Обрабатываем квизы
     * @param array $queryParams
     * @throws \Exception
     */
    private function processQuizData(array $queryParams)
    {
        $this->info("Извлекаем квизы...");

        //создаём временную таблицу
        $this->createTempQuizTable();

        try {
            //заполняем временную таблицу
            $this->fillTempQuizTable($queryParams);

            //обрабатываем данные из временной таблицы
            $processedCount = $this->processTempQuizData();

            $this->info("Обработано $processedCount записей квизов");
        } catch (\Exception $e) {
            $this->error("Ошибка при обработке квизов: " . $e->getMessage());
            throw $e;
        } finally {
            //удаляем временные таблицы
            $this->dropTempQuizTable();
        }
    }

    /**
     * Создаём временную таблицу для хранения данных квизов
     */
    private function createTempQuizTable()
    {
        DB::statement('
        CREATE TEMPORARY TABLE temp_quiz_actions (
            id SERIAL,
            user_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            action_time TIMESTAMP NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            quiz_question_code VARCHAR(255) NULL,
            is_correct BOOLEAN NULL,
            PRIMARY KEY (id)
        )
    ');

        DB::statement('CREATE INDEX idx_temp_quiz_user_name ON temp_quiz_actions (user_id, name)');
        DB::statement('CREATE INDEX idx_temp_quiz_action_time ON temp_quiz_actions (action_time)');

        $this->info("Создана временная таблица temp_quiz_actions");
    }

    /**
     * Заполняем временную таблицу данными из API
     * @param array $queryParams
     * @throws \Exception
     */
    private function fillTempQuizTable(array $queryParams)
    {
        $queryParams['orderBy'] = 'asc';
        $queryParams['order'] = 'user_id';

        $hasMorePages = true;
        $page = 1;
        $totalInserted = 0;

        while ($hasMorePages) {
            $response = $this->getData("outer/qts", $queryParams, $page);

            if (empty($response) || empty($data = $response['data'])) {
                break;
            }

            $batch = [];
            foreach ($data as $quizData) {
                $batch[] = [
                    'user_id' => $quizData['user_id'],
                    'name' => $quizData['name'],
                    'action_time' => $quizData['created_at'],
                    'action_type' => $quizData['action'],
                    'quiz_question_code' => $quizData['quiz_question_code'],
                    'is_correct' => $quizData['is_correct'],
                ];
            }

            DB::table('temp_quiz_actions')->insert($batch);
            $totalInserted += count($batch);

            $hasMorePages = !empty($response['next_page_url']);
            $page++;
            sleep(1);
        }

        $this->info("Загружено $totalInserted действий во временную таблицу");
    }

    /**
     * Обрабатываем данные из временной таблицы квизов
     * @return int
     * @throws \Exception
     */
    private function processTempQuizData(): int
    {
        //создаем временную таблицу с нумерованными действиями
        DB::statement('
        CREATE TEMPORARY TABLE temp_quiz_actions_ordered AS
        SELECT
            *,
            ROW_NUMBER() OVER (PARTITION BY user_id, name ORDER BY action_time) as action_num
        FROM temp_quiz_actions
        ORDER BY user_id, name, action_time
    ');

        if ($this->needLogs) {
            //логируем примеры записей из исходной таблицы
            $sampleRecords = DB::table('temp_quiz_actions')
                ->select('user_id', 'name', 'action_time', 'action_type', 'quiz_question_code', 'is_correct')
                ->orderBy('user_id')
                ->orderBy('action_time')
                ->limit(10)
                ->get()
                ->toArray();

            Log::channel('commands')->debug("Примеры записей из temp_quiz_actions:", $sampleRecords);
        }


        //создаем таблицу с 10-минутными группами
        DB::statement('
        CREATE TEMPORARY TABLE temp_quiz_windows AS
        WITH RECURSIVE action_groups AS (
            SELECT
                user_id,
                name,
                action_time as window_start,
                action_time as current_action,
                action_num,
                action_type,
                1 as group_id
            FROM temp_quiz_actions_ordered
            WHERE action_num = 1

            UNION ALL
            SELECT
                a.user_id,
                a.name,
                CASE
                    WHEN EXTRACT(EPOCH FROM (a.action_time - ag.window_start)) > 600
                    THEN a.action_time
                    ELSE ag.window_start
                END as window_start,
                a.action_time as current_action,
                a.action_num,
                a.action_type,
                CASE
                    WHEN EXTRACT(EPOCH FROM (a.action_time - ag.window_start)) > 600
                    THEN ag.group_id + 1
                    ELSE ag.group_id
                END as group_id
            FROM temp_quiz_actions_ordered a
            JOIN action_groups ag ON
                a.user_id = ag.user_id AND
                a.name = ag.name AND
                a.action_num = ag.action_num + 1
        )
        SELECT
            user_id,
            name,
            group_id,
            window_start,
            MAX(current_action) as window_end,
            COUNT(*) as actions_count,
            MAX(CASE WHEN action_type = \'answer\' THEN 1 ELSE 0 END) as has_answer
        FROM action_groups
        GROUP BY user_id, name, group_id, window_start
        ORDER BY user_id, name, window_start
    ');

        if ($this->needLogs) {
            //логируем сформированные группы
            $sampleGroups = DB::table('temp_quiz_windows')
                ->select('user_id', 'name', 'group_id', 'window_start', 'window_end', 'actions_count', 'has_answer')
                ->orderBy('user_id')
                ->orderBy('window_start')
                ->limit(10)
                ->get()
                ->toArray();

            Log::channel('commands')->debug("Примеры сформированных 10-минутных групп:", $sampleGroups);

            //для каждой группы логируем входящие в нее записи
            foreach ($sampleGroups as $group) {
                $groupRecords = DB::table('temp_quiz_actions_ordered')
                    ->where('user_id', $group->user_id)
                    ->where('name', $group->name)
                    ->whereBetween('action_time', [$group->window_start, $group->window_end])
                    ->orderBy('action_time')
                    ->get()
                    ->toArray();

                Log::channel('commands')->debug("Записи группы {$group->group_id} для пользователя {$group->user_id}, квиз '{$group->name}':", [
                    'window_start' => $group->window_start,
                    'window_end' => $group->window_end,
                    'duration_seconds' => Carbon::parse($group->window_end)->diffInSeconds(Carbon::parse($group->window_start)),
                    'records' => $groupRecords
                ]);
            }
        }

        //получаем список уникальных пользователей
        $userIds = DB::table('temp_quiz_windows')
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        //предзагружаем данные пользователей
        $users = UserMT::query()
            ->whereIn('new_mt_id', $userIds)
            ->get()
            ->keyBy('new_mt_id');

        //обрабатываем данные чанками
        $processedCount = 0;
        $batchActions = [];
        $batchSize = static::BATCH_SIZE;

        DB::table('temp_quiz_windows')
            ->orderBy('user_id')
            ->orderBy('window_start')
            ->chunk($batchSize, function ($groups) use ($users, &$batchActions, &$processedCount, $batchSize) {
                foreach ($groups as $group) {
                    if (!isset($users[$group->user_id])) {
                        continue;
                    }

                    $user = $users[$group->user_id];

                    //рассчитываем duration между первым и последним действием в окне
                    $duration = Carbon::parse($group->window_end)
                        ->diffInSeconds(Carbon::parse($group->window_start));

                    $activity = ActivityMT::firstOrCreate(
                        [
                            'type' => str_contains($group->name, 'Longread') ? 'Лонгрид' : 'Квиз',
                            'name' => $group->name
                        ],
                        [
                            'date_time' => $group->window_start,
                            'is_online' => true
                        ]
                    );

                    $formattedDuration = $this->formatDuration($duration);
                    if ($formattedDuration == 0) {
                        $formattedDuration = 0.01;
                    }

                    $batchActions[] = [
                        'email' => $user->email,
                        'mt_user_id' => $user->id,
                        'activity_id' => $activity->id,
                        'date_time' => $group->window_start,
                        'duration' => $formattedDuration,
                        'result' => $group->has_answer ? 100 : 0,
                    ];

                    $processedCount++;

                    if (count($batchActions) >= $batchSize) {
                        $this->insertActions($batchActions);
                        $batchActions = [];
                    }
                }
            });

        if (!empty($batchActions)) {
            $this->insertActions($batchActions);
        }

        return $processedCount;
    }

    /**
     * Вставляем действия пользователей пакетом
     * @param array $batchActions
     * @throws \Exception
     */
    private function insertActions(array $batchActions)
    {
        $this->info("Пакетная вставка - " . count($batchActions));
        $this->withTableLock('actions_mt', function () use ($batchActions) {
            ActionMT::insertOrIgnore($batchActions);
        });
    }

    /**
     * Удаляем временные таблицу
     */
    private function dropTempQuizTable()
    {
        DB::statement('DROP TABLE IF EXISTS temp_quiz_actions_ordered');
        DB::statement('DROP TABLE IF EXISTS temp_quiz_windows');
        DB::statement('DROP TABLE IF EXISTS temp_quiz_actions');
        $this->info("Временные таблицы удалены");
    }

    /**
     * Форматируем duration в минуты.секунды
     * @param int $seconds
     * @return string
     */
    private function formatDuration(int $seconds): string
    {
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;
        return sprintf('%d.%02d', $minutes, $seconds);
    }

    /**
     * Вычисляем длительность мероприятия в секундах
     * @param string $startedAt
     * @param string $finishedAt
     * @return int
     */
    private function getEventDurationInSeconds(string $startedAt, string $finishedAt): int
    {
        return Carbon::parse($finishedAt)->diffInSeconds(Carbon::parse($startedAt));
    }

    /**
     * Вычисляем результат мероприятия в процентах
     * @param int $watchSeconds     - время просмотра в секундах
     * @param int $eventDuration    - длительность мероприятия в секундах
     * @return float                - результат в процентах (0-100)
     */
    private function calculateEventResult(int $watchSeconds, int $eventDuration): float
    {
        if ($eventDuration <= 0) {
            return 0.0;
        }

        $percentage = ($watchSeconds / $eventDuration) * 100;
        return (float) min(round($percentage, 2), 100.0);
    }
}
