<?php

namespace App\Console\Commands;

use App\Logging\CustomLog;
use App\Models\ActionMT;
use App\Models\CommonDatabase;
use App\Models\ProjectTouchMT;
use App\Traits\WriteLockTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as CommandAlias;

class CalculateDataForCommonDatabase extends Command
{
    use WriteLockTrait;

    protected $signature = 'calculate:data-common-db
                          {--chunk=500 : Количество записей за одну транзакцию}';

    protected $description = 'Подсчёт данных по разным таблицам для заполнения полей common_database, с обработкой в ограниченной памяти';

    private int $chunk;

    public function handle(): int
    {
        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M');
        set_time_limit(0);
        DB::disableQueryLog();

        $this->chunk = $this->option('chunk');

        try {
            $this->info("Начинаем подсчёт. Чанк {$this->chunk}...");

            $this->calculateAllData();

            $this->info('Подсчёт окончен!');
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи: ' . $e->getMessage());
            return CommandAlias::FAILURE;
        }
    }

    /**
     * @throws \Exception
     */
    private function calculateAllData()
    {
        $this->info('Подсчёт данных...');

        $processed = 0;

        CommonDatabase::query()
            ->select('email', 'mt_user_id', 'specialization', 'registration_date')
            ->chunk($this->chunk, function ($users) use (&$processed) {
                $batch = [];
                $emails = $users->pluck('email')->toArray();
                $mtUserIds = $users->pluck('mt_user_id')->toArray();

                $plannedActions = $this->getPlannedActions($emails, $mtUserIds);
                $resultingActions = $this->getResultingActions($emails, $mtUserIds);
                $categories = $this->getCategories($users, $emails, $mtUserIds);

                foreach ($users as $user) {
                    $plannedCount = $plannedActions[$user->email] ?? 0;
                    $resultingCount = $resultingActions[$user->email] ?? 0;
                    $category = $categories[$user->email] ?? 'D';

                    $batch[] = [
                        'email' => $user->email,
                        'planned_actions' => $plannedCount,
                        'resulting_actions' => $resultingCount,
                        'category' => $category
                    ];
                }

                if (!empty($batch)) {
                    $this->withTableLock('common_database', function () use ($batch) {
                        CommonDatabase::query()->upsert(
                            $batch,
                            ['email'],
                            ['planned_actions', 'resulting_actions', 'category']
                        );
                    });
                }

                $processed += count($batch);
                $this->info("Обработано {$processed} записей");
            });
    }

    /**
     * Получаем planned_actions для группы пользователей
     */
    private function getPlannedActions(array $emails, array $mtUserIds): array
    {
        $plannedActions = [];

        //групповой запрос для actions_mt (уникальные сочетания activity_id и email)
        $actionsCounts = ActionMT::query()
            ->whereIn('email', $emails)
            ->select('email', DB::raw('COUNT(DISTINCT activity_id) as count'))
            ->groupBy('email')
            ->get()
            ->keyBy('email');

        //групповой запрос для project_touches_mt touch_type !== 'verification'
        $validMtUserIds = array_filter($mtUserIds, fn($id) => !is_null($id));
        $touchesCounts = [];

        if (!empty($validMtUserIds)) {
            $touchesCounts = ProjectTouchMT::query()
                ->whereIn('mt_user_id', $validMtUserIds)
                ->where('touch_type', '!=', 'verification')
                ->select('mt_user_id', DB::raw('COUNT(*) as count'))
                ->groupBy('mt_user_id')
                ->get()
                ->keyBy('mt_user_id');
        }

        foreach ($emails as $index => $email) {
            $actionsCount = $actionsCounts[$email]->count ?? 0;
            $mtUserId = $mtUserIds[$index] ?? null;
            $touchesCount = $mtUserId ? ($touchesCounts[$mtUserId]->count ?? 0) : 0;

            $plannedActions[$email] = $actionsCount + $touchesCount;
        }

        return $plannedActions;
    }

    /**
     * Получаем resulting_actions для группы пользователей
     */
    private function getResultingActions(array $emails, array $mtUserIds): array
    {
        $resultingActions = [];

        //групповой запрос для actions_mt с result > 0
        $actionsCounts = ActionMT::query()
            ->whereIn('email', $emails)
            ->where('result', '>', 0)
            ->select('email', DB::raw('COUNT(DISTINCT activity_id) as count'))
            ->groupBy('email')
            ->get()
            ->keyBy('email');

        //групповой запрос для project_touches_mt с status === true
        $validMtUserIds = array_filter($mtUserIds, fn($id) => !is_null($id));
        $touchesCounts = [];

        if (!empty($validMtUserIds)) {
            $touchesCounts = ProjectTouchMT::query()
                ->whereIn('mt_user_id', $validMtUserIds)
                ->where('status', true)
                ->select('mt_user_id', DB::raw('COUNT(*) as count'))
                ->groupBy('mt_user_id')
                ->get()
                ->keyBy('mt_user_id');
        }

        foreach ($emails as $index => $email) {
            $actionsCount = $actionsCounts[$email]->count ?? 0;
            $mtUserId = $mtUserIds[$index] ?? null;
            $touchesCount = $mtUserId ? ($touchesCounts[$mtUserId]->count ?? 0) : 0;

            $resultingActions[$email] = $actionsCount + $touchesCount;
        }

        return $resultingActions;
    }

    /**
     * Получаем категории для группы пользователей
     */
    private function getCategories($users, $emails, $mtUserIds): array
    {
        $oneYearAgo = now()->subDays(365);
        $categories = [];

        //проверка условий для категории A
        $categoryAConditions = $this->getCategoryAConditions($emails, $mtUserIds, $oneYearAgo);

        //проверка условий для категории B
        $categoryBConditions = $this->getCategoryBConditions($users, $emails, $oneYearAgo);

        //определяем категории для каждого пользователя
        foreach ($users as $user) {
            $category = 'D';

            if ($categoryAConditions[$user->email] ?? false) {
                $category = 'A';
            } elseif ($categoryBConditions[$user->email] ?? false) {
                $category = 'B';
            } elseif (!empty($user->registration_date)) {
                $category = 'C';
            }

            $categories[$user->email] = $category;
        }

        return $categories;
    }

    /**
     * Проверяем условия для категории A
     */
    private function getCategoryAConditions($emails, $mtUserIds, $oneYearAgo): array
    {
        $conditions = [];

        //условие 1: действия в actions_mt с типами Лонгрид, Квиз, Видеовизит за последний год
        $actionsMtUsers = ActionMT::query()
            ->whereIn('email', $emails)
            ->where('date_time', '>=', $oneYearAgo)
            ->whereHas('activity', function ($query) {
                $query->whereIn('type', ['Лонгрид', 'Квиз', 'Видеовизит']);
            })
            ->select('email')
            ->groupBy('email')
            ->get()
            ->pluck('email')
            ->toArray();

        //условие 2: видеовизиты в project_touches_mt за последний год + registration_date заполнено
        $validMtUserIds = array_filter($mtUserIds, fn($id) => !is_null($id));

        if (!empty($validMtUserIds)) {
            $videoTouchesUsers = ProjectTouchMT::query()
                ->whereIn('project_touches_mt.mt_user_id', $validMtUserIds)
                ->where('touch_type', 'video')
                ->where('status', true)
                ->where('date_time', '>=', $oneYearAgo)
                ->join('common_database', 'project_touches_mt.mt_user_id', '=', 'common_database.mt_user_id')
                ->whereNotNull('common_database.registration_date')
                ->select('common_database.email')
                ->groupBy('common_database.email')
                ->get()
                ->pluck('email')
                ->toArray();

            //объединяем условия для категории A
            $categoryAUsers = array_unique(array_merge($actionsMtUsers, $videoTouchesUsers));
        } else {
            $categoryAUsers = $actionsMtUsers;
        }

        foreach ($categoryAUsers as $email) {
            $conditions[$email] = true;
        }

        return $conditions;
    }

    /**
     * Проверяем условия для категории B
     */
    private function getCategoryBConditions($users, $emails, $oneYearAgo): array
    {
        $conditions = [];

        //условие 1: заполнено поле specialization
        foreach ($users as $user) {
            if (!empty($user->specialization)) {
                $conditions[$user->email] = true;
            }
        }

        //если уже нашли условие для всех пользователей, дальше не проверяем
        if (count($conditions) === count($emails)) {
            return $conditions;
        }

        //условие 2: участия в активностях types conference, congress, school, webinar, Мероприятие
        $emailsWithoutCondition = array_diff($emails, array_keys($conditions));
        if (!empty($emailsWithoutCondition)) {
            $activitiesUsers = ActionMT::query()
                ->whereIn('email', $emailsWithoutCondition)
                ->where('date_time', '>=', $oneYearAgo)
                ->whereHas('activity', function ($query) {
                    $query->whereIn('type', ['conference', 'congress', 'school', 'webinar', 'Мероприятие']);
                })
                ->select('email')
                ->groupBy('email')
                ->get()
                ->pluck('email')
                ->toArray();

            foreach ($activitiesUsers as $email) {
                $conditions[$email] = true;
            }
        }

        //условие 3: авторизация на портале за последние 365 дней
        $emailsWithoutCondition = array_diff($emails, array_keys($conditions));
        if (!empty($emailsWithoutCondition)) {
            $authorizedUsers = CommonDatabase::query()
                ->whereIn('email', $emailsWithoutCondition)
                ->whereNotNull('last_auth_date')
                ->where('last_auth_date', '>=', $oneYearAgo)
                ->select('email')
                ->groupBy('email')
                ->get()
                ->pluck('email')
                ->toArray();

            foreach ($authorizedUsers as $email) {
                $conditions[$email] = true;
            }
        }

        return $conditions;
    }
}
