<?php

namespace App\Console\Commands\ImportNewMt;

use App\Logging\CustomLog;
use App\Models\CommonDatabase;
use App\Models\ProjectMT;
use App\Models\ProjectTouchMT;
use App\Models\UserMT;
use Carbon\Carbon;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\DB;


/**
 * В API нужно доделать фильтрацию по updated_after, иначе придётся проходится по всем касаниям каждый раз!!
 */
//todo: Ты там проектам присваивал id, а у них там id уже есть нужно оставить те которые есть и в таблице projects_touches оставить
//todo: уникальные id названия проектов, дату начала можно поставить дату первого касания
class ImportByApiV2 extends Common
{

    protected $signature = 'import:new-mt-touches
                            {--updated_after= : Дата последнего обновления в формате d.m.Y}
                            {--pageSize=100 : Колличество записей за один запрос}';

    protected $description = 'Импорт касаний нового сайта МедТач, в ограниченной памяти';

    /**
     * Версия апи
     * @var int
     */
    protected int $apiVersion = 2;

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @return int
     */
    public function handle(): int
    {
        $updatedAfter = $this->option('updated_after');
        $queryParams = [
            'exclude_subobjects' => ['contact'],
            'updated_after' => $updatedAfter
                ? Carbon::parse($updatedAfter)->startOfDay()->format('Y-m-d H:i:s.v')
                : Carbon::now()->subDay()->startOfDay()->format('Y-m-d H:i:s.v'),
            'pageSize' => $this->option('pageSize'),
        ];

        try {
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Начало импорта');
            $this->processTouchesData($queryParams);
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Импорт завершен');
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи');
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Обработка данных касаний
     * @param array $queryParams    Параметры запроса
     * @throws \Exception
     */
    private function processTouchesData(array $queryParams)
    {
        $this->info('Извлекаем касания...');

        $hasMorePages = true;
        $page = 1;
        $totalProcessed = 0;
        $countBatchInsert = 0;

        //массивы для пакетной обработки
        $usersMTBatch = [];
        $commonDBBatch = [];
        $projectsBatch = [];
        $touchesBatch = [];
        $processedEmails = [];
        $processedProjectKeys = [];

        try {
            while ($hasMorePages) {
                $this->info("Запрос данных - страница $page");

                $response = $this->getData('outer/get-touches', $queryParams, $page);

                if (empty($data = $response['data'])) {
                    break;
                }

                foreach ($data as $touchData) {
                    //обработка данных пользователя
                    $this->processUserData($touchData, $usersMTBatch, $commonDBBatch, $processedEmails);

                    //обработка проекта
                    $this->processProjectData($touchData, $projectsBatch, $processedProjectKeys);

                    //подготовка касания
                    $touchesBatch[] = $this->prepareTouchData($touchData, $this->getProjectKey($touchData['project'], $touchData['wave']));

                    $totalProcessed++;

                    if (count($usersMTBatch) >= self::BATCH_SIZE ||
                        count($touchesBatch) >= self::BATCH_SIZE) {

                        $this->processBatchInsert(
                            $usersMTBatch,
                            $commonDBBatch,
                            $projectsBatch,
                            $touchesBatch,
                            $processedEmails,
                            $processedProjectKeys,
                            $totalProcessed,
                            $countBatchInsert
                        );
                    }
                }

                $hasMorePages = !empty($response['next_page_url']);
                $page++;
                sleep(1);
            }

            //обработка оставшихся данных
            if (!empty($usersMTBatch)) {
                $this->processBatchInsert(
                    $usersMTBatch,
                    $commonDBBatch,
                    $projectsBatch,
                    $touchesBatch,
                    $processedEmails,
                    $processedProjectKeys,
                    $totalProcessed,
                    $countBatchInsert,
                    true
                );
            }

            $this->info("Обработано $totalProcessed записей");
        } catch (\Exception $e) {
            $this->error("Ошибка получения данных: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Обработка данных пользователя
     * @param array $touchData              Данные касания
     * @param array $usersMTBatch           Ссылка на массив пользователей для users_mt
     * @param array $commonDBBatch          Ссылка на массив пользователей для common_database
     * @param array $processedEmails        Ссылка на массив обработанных email
     */
    private function processUserData(array $touchData, array &$usersMTBatch, array &$commonDBBatch, array &$processedEmails): void
    {
        $userData = $touchData['user'];
        $email = $userData['email'];

        if (!in_array($email, $processedEmails)) {
            $fullName = trim(implode(' ', array_filter([
                $userData['last_name'] ?? null,
                $userData['first_name'] ?? null,
                $userData['middle_name'] ?? null
            ])));

            $usersMTBatch[] = $this->prepareMtUserData($userData, $fullName);
            $commonDBBatch[] = $this->prepareCommonDBData($userData, $fullName);
            $processedEmails[] = $email;
        }
    }

    /**
     * Обработка данных проекта
     * @param array $touchData                  Данные касания
     * @param array $projectsBatch              Ссылка на массив проектов
     * @param array $processedProjectKeys       Ссылка на массив обработанных ключей проектов
     */
    private function processProjectData(array $touchData, array &$projectsBatch, array &$processedProjectKeys): void
    {
        $projectData = $touchData['project'];
        $waveData = $touchData['wave'];
        $projectKey = $this->getProjectKey($projectData, $waveData);

        if (!in_array($projectKey, $processedProjectKeys)) {
            $projectsBatch[] = $this->prepareProjectData($projectData, $waveData);
            $processedProjectKeys[] = $projectKey;
        }
    }

    /**
     * Обработка пакетной вставки данных
     * @param array $usersMTBatch           Массив пользователей для users_mt
     * @param array $commonDBBatch          Массив пользователей для common_database
     * @param array $projectsBatch          Массив проектов
     * @param array $touchesBatch           Массив касаний
     * @param array $processedEmails        Массив обработанных email
     * @param array $processedProjectKeys   Массив обработанных ключей проектов
     * @param int $totalProcessed           Общее количество обработанных записей
     * @param int $countBatchInsert         Счетчик пакетных вставок
     * @param bool $isFinal                 Флаг финальной вставки
     */
    private function processBatchInsert(
        array &$usersMTBatch,
        array &$commonDBBatch,
        array &$projectsBatch,
        array &$touchesBatch,
        array &$processedEmails,
        array &$processedProjectKeys,
        int $totalProcessed,
        int &$countBatchInsert,
        bool $isFinal = false
    ): void {
        $countBatchInsert++;
        $this->info(($isFinal ? "Финальная пакетная вставка" : "Пакетная вставка") . " - $countBatchInsert (всего записей $totalProcessed)");

        $this->insertBatchData(
            $usersMTBatch,
            $commonDBBatch,
            $projectsBatch,
            $touchesBatch
        );

        $usersMTBatch = [];
        $commonDBBatch = [];
        $projectsBatch = [];
        $touchesBatch = [];
        $processedEmails = [];
        $processedProjectKeys = [];

        gc_mem_caches();
    }

    /**
     * Пакетная вставка
     * @param array $usersMTBatch       Массив пользователей для users_mt
     * @param array $commonDBBatch      Массив пользователей для common_database
     * @param array $projectsBatch      Массив проектов
     * @param array $touchesBatch       Массив касаний
     */
    private function insertBatchData(
        array $usersMTBatch,
        array $commonDBBatch,
        array $projectsBatch,
        array $touchesBatch
    ): void {
        DB::transaction(function () use ($usersMTBatch, $commonDBBatch, $projectsBatch, $touchesBatch) {
            //вставка пользователей в users_mt
            $this->processUsersInsert($usersMTBatch, $commonDBBatch);

            //вставка проектов и получение их ID
            $projectIdMap = $this->processProjectsInsert($projectsBatch);

            //обновление project_id в касаниях
            $this->updateTouchesProjectIds($touchesBatch, $projectIdMap);

            //вставка пользователей в common_database
            $this->processCommonDatabaseInsert($commonDBBatch);

            //вставка касаний
            $this->processTouchesInsert($touchesBatch);
        });
    }

    /**
     * Вставка пользователей в таблицу users_mt
     * @param array $usersMTBatch Массив пользователей
     * @param array $commonDBBatch Ссылка на массив для common_database
     */
    private function processUsersInsert(array $usersMTBatch, array &$commonDBBatch): void
    {
        if (!empty($usersMTBatch)) {
            $this->withTableLock('users_mt', function () use ($usersMTBatch) {
                UserMT::query()->upsert(
                    $usersMTBatch,
                    ['email'],
                    ['full_name', 'email', 'phone']
                );
            });

            //получаем ID-ки вставленных пользователей
            $emails = array_column($usersMTBatch, 'email');
            $usersIdByEmail = UserMT::query()
                ->whereIn('email', $emails)
                ->pluck('id', 'email')
                ->toArray();

            //обновляем пакет с ID-ками пользователей
            $commonDBBatch = array_map(function ($item) use ($usersIdByEmail) {
                $item['mt_user_id'] = $usersIdByEmail[$item['email']] ?? null;
                return $item;
            }, $commonDBBatch);
        }
    }

    /**
     * Вставка проектов и получение их ID
     * @param array $projectsBatch      Массив проектов
     * @return array                    Маппинг ключей проектов на их ID
     */
    private function processProjectsInsert(array $projectsBatch): array
    {
        $projectIdMap = [];

        if (!empty($projectsBatch)) {
            $this->withTableLock('projects_mt', function () use ($projectsBatch, &$projectIdMap) {
                //вставляем проекты, игнорируя дубликаты
                ProjectMT::query()->insertOrIgnore($projectsBatch);

                //получаем ID-ки вставленных проектов
                $projectNames = array_column($projectsBatch, 'project');
                $waveNames = array_column($projectsBatch, 'wave');

                $existingProjects = ProjectMT::query()
                    ->whereIn('project', $projectNames)
                    ->whereIn('wave', $waveNames)
                    ->get();

                foreach ($existingProjects as $project) {
                    $key = $this->createProjectKey($project->project, $project->wave);
                    $projectIdMap[$key] = $project->id;
                }
            });
        }

        return $projectIdMap;
    }

    /**
     * Обновление project_id в касаниях
     * @param array $touchesBatch       Массив касаний
     * @param array $projectIdMap       Маппинг ключей проектов на их ID
     */
    private function updateTouchesProjectIds(array &$touchesBatch, array $projectIdMap): void
    {
        foreach ($touchesBatch as &$touch) {
            if (isset($projectIdMap[$touch['project_key']])) {
                $touch['project_id'] = $projectIdMap[$touch['project_key']];
            }
        }
        unset($touch);
    }

    /**
     * Вставка пользователей в common_database
     * @param array $commonDBBatch      Массив пользователей
     */
    private function processCommonDatabaseInsert(array $commonDBBatch): void
    {
        if (!empty($commonDBBatch)) {
            $this->withTableLock('common_database', function () use ($commonDBBatch) {
                CommonDatabase::query()->upsert(
                    $commonDBBatch,
                    ['email'],
                    ['full_name', 'username', 'phone']
                );
            });
        }
    }

    /**
     * Вставка касаний проекта
     * @param array $touchesBatch   Массив касаний
     */
    private function processTouchesInsert(array $touchesBatch): void
    {
        if (!empty($touchesBatch)) {
            $userEmails = array_unique(array_column($touchesBatch, 'user_email'));

            $usersIdByEmail = UserMT::query()
                ->whereIn('email', $userEmails)
                ->pluck('id', 'email')
                ->toArray();

            $preparedTouches = [];
            foreach ($touchesBatch as $touch) {
                $mtUserId = $usersIdByEmail[$touch['user_email']] ?? null;
                $projectId = $touch['project_id'] ?? null;

                if ($mtUserId && $projectId) {
                    $preparedTouches[] = [
                        'mt_user_id' => $mtUserId,
                        'project_id' => $projectId,
                        'touch_type' => $touch['touch_type'],
                        'status' => $touch['status'],
                        'date_time' => $touch['date_time']
                    ];
                }
            }

            if (!empty($preparedTouches)) {
                $this->withTableLock('project_touches_mt', function () use ($preparedTouches) {
                    ProjectTouchMT::query()->insertOrIgnore($preparedTouches);
                });
            }
        }
    }

    /**
     * Генерация ключа проекта на основе названия и волны
     * @param array $projectData        Данные проекта
     * @param array $waveData           Данные волны
     * @return string                   Уникальный ключ проекта
     */
    #[Pure]
    private function getProjectKey(array $projectData, array $waveData): string
    {
        return $this->createProjectKey($projectData['name'], $waveData['name']);
    }

    /**
     * Создание уникального ключа для проекта
     * @param string $projectName       Название проекта
     * @param string $waveName          Название волны
     * @return string                   MD5-хеш ключа проекта
     */
    private function createProjectKey(string $projectName, string $waveName): string
    {
        return md5($projectName . '|' . $waveName);
    }

    /**
     * Подготовка данных пользователя
     * @param array $userData       Данные пользователя
     * @param string $fullName      Полное имя пользователя
     * @return array
     */
    #[ArrayShape(['full_name' => "string", 'email' => "mixed", 'phone' => "mixed|null"])]
    private function prepareMtUserData(array $userData, string $fullName): array
    {
        return [
            'full_name' => $fullName,
            'email' => $userData['email'],
            'phone' => $userData['phone'] ?? null,
        ];
    }

    /**
     * Подготовка данных пользователя
     * @param array $userData       Данные пользователя
     * @param string $fullName      Полное имя пользователя
     * @return array
     */
    #[ArrayShape(['full_name' => "string", 'email' => "mixed", 'mt_user_id' => "null", 'phone' => "mixed|null"])]
    private function prepareCommonDBData(array $userData, string $fullName): array
    {
        return [
            'full_name' => $fullName,
            'email' => $userData['email'],
            'mt_user_id' => null,
            'phone' => $userData['phone'] ?? null,
        ];
    }

    /**
     * Подготовка данных проекта для вставки
     * @param array $projectData    Данные проекта
     * @param array $waveData       Данные волны
     * @return array
     */
    #[ArrayShape(['project' => "mixed", 'wave' => "mixed", 'date_time' => "string"])]
    private function prepareProjectData(array $projectData, array $waveData): array
    {
        return [
            'project' => $projectData['name'],
            'wave' => $waveData['name'],
            'date_time' => Carbon::parse($projectData['created_at'])->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Подготовка данных касания для вставки
     * @param array $touchData      Данные касания
     * @param string $projectKey    Ключ проекта
     * @return array
     */
    #[ArrayShape(['user_email' => "mixed", 'project_key' => "string", 'touch_type' => "mixed", 'status' => "mixed", 'date_time' => "string"])]
    private function prepareTouchData(array $touchData, string $projectKey): array
    {
        return [
            'user_email' => $touchData['user']['email'],
            'project_key' => $projectKey,
            'touch_type' => $touchData['touch_type'],
            'status' => $touchData['success'],
            'date_time' => Carbon::parse($touchData['touch_date'])->format('Y-m-d H:i:s')
        ];
    }
}
