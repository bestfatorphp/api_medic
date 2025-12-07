<?php

namespace App\Console\Commands\ImportNewMt;

use App\Logging\CustomLog;
use App\Models\ActionMT;
use App\Models\ActivityMT;
use App\Models\UserMT;
use Carbon\Carbon;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\Console\Command\Command as CommandAlias;


class UpdateNewFieldActionsMt extends Common
{

    protected $signature = 'update:actions-mt-field-by-fc
                            {--updated_after= : Дата последнего обновления в формате d.m.Y}
                            {--pageSize=500 : Колличество записей за один запрос}';

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

        $queryParams = [
            'pageSize' => $this->pageSize,
        ];

        $queryParams = array_merge([
            'updated_after' => $updatedAfter
                ? Carbon::parse($updatedAfter)->startOfDay()->format('Y-m-d H:i:s.v')
                : Carbon::now()->subDay()->startOfDay()->format('Y-m-d H:i:s.v'),
            'order' => 'updated_at',
        ], $queryParams);


        try {
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Начало обновления');
            $this->processEventsFCData($queryParams);
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Обновление завершено');
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи');
            return CommandAlias::FAILURE;
        }
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

                    $activity = null;

                    /** @var ActivityMT $activity */
                    $activity = ActivityMT::query()
                        ->where('name', '=', $eventData['name'] . $additionalEventName)
                        ->where('event_id', '=', $eventId)
                        ->first();

                    if (!$activity) {
                        $this->error("Не удалось создать/найти активность для события $eventId");
                        continue;
                    }

                    //подготавливае действия
                    $batchActions[] = $this->prepareActionFcData($fcData, $activity->id);

                    $totalProcessed++;
                }

                if (count($batchActions) >= self::BATCH_SIZE) {
                    $this->updateActions($batchActions);
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
            $this->updateActions($batchActions);
            gc_mem_caches(); //очищаем кэши памяти Zend Engine
        }

        $this->info("Извлечено $totalProcessed событий");
    }

    /**
     * @param array $fcData
     * @param int $activityId
     * @return array
     */
    #[ArrayShape(['email' => "string", 'mt_user_id' => "mixed", 'activity_id' => "int", 'date_time' => "string", 'duration' => "string", 'result' => "float", 'registered_at' => "mixed"])]
    private function prepareActionFcData(array $fcData, int $activityId): array
    {
        return [
            'email' => strtolower($fcData['user']['email']),
            'mt_user_id' => $fcData['user']['id'],
            'activity_id' => $activityId,
            'date_time' => $fcData['created_at'],
            'registered_at' => $fcData['created_at']
        ];
    }

    /**
     * Вставляем действия пользователей пакетом
     * @param array $batchActions
     * @throws \Exception
     */
    private function updateActions(array $batchActions)
    {
        $this->info("Пакетная вставка - " . count($batchActions));
        $this->withTableLock('actions_mt', function () use ($batchActions) {
            foreach ($batchActions as $data) {
                ActionMT::query()
                    ->where('email', $data['email'])
                    ->where('mt_user_id', $data['mt_user_id'])
                    ->where('activity_id', $data['activity_id'])
                    ->where('date_time', $data['date_time'])
                    ->update([
                        'date_time' => $data['registered_at'],
                        'registered_at' => $data['registered_at']
                    ]);
            }
        });
    }
}
