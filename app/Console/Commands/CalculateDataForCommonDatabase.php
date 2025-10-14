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

            //считаем planned_actions
//            $this->calculatePlannedActions();

            //считаем resulting_actions
//            $this->calculateResultingActions();

            //расставляем категории
            $this->setCategories();

            $this->info('Подсчёт окончен!');
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи: ' . $e->getMessage());
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Подсчёт planned_actions
     * @throws \Exception
     */
    private function calculatePlannedActions()
    {
        $this->info('Подсчёт planned_actions...');

        $processed = 0;

        CommonDatabase::query()
            ->select('email', 'mt_user_id')
            ->chunk($this->chunk, function ($users) use (&$processed) {
                $batch = [];
                $emails = $users->pluck('email')->toArray();
                $mtUserIds = $users->pluck('mt_user_id')->toArray();

                //групповой запрос для actions_mt (уникальные сочетания activity_id и email)
                $actionsCounts = ActionMT::query()
                    ->whereIn('email', $emails)
                    ->select('email', DB::raw('COUNT(DISTINCT activity_id) as count'))
                    ->groupBy('email')
                    ->get()
                    ->keyBy('email');

                //групповой запрос для project_touches_mt touch_type !== 'verification'
                $touchesCounts = ProjectTouchMT::query()
                    ->whereIn('mt_user_id', $mtUserIds)
                    ->where('touch_type', '!=', 'verification')
                    ->select('mt_user_id', DB::raw('COUNT(*) as count'))
                    ->groupBy('mt_user_id')
                    ->get()
                    ->keyBy('mt_user_id');

                foreach ($users as $user) {
                    $actionsCount = $actionsCounts[$user->email]->count ?? 0;
                    $touchesCount = $touchesCounts[$user->mt_user_id]->count ?? 0;

                    $batch[] = [
                        'email' => $user->email,
                        'planned_actions' => $actionsCount + $touchesCount
                    ];
                }

                if (!empty($batch)) {
                    $this->withTableLock('common_database', function () use ($batch) {
                        CommonDatabase::query()->upsert(
                            $batch,
                            ['email'],
                            ['planned_actions']
                        );
                    });
                }

                $processed += count($batch);
                $this->info("Обработано {$processed} записей для planned_actions");
            });
    }

    /**
     * Подсчёт resulting_actions
     * @throws \Exception
     */
    private function calculateResultingActions()
    {
        $this->info('Подсчёт resulting_actions...');

        $processed = 0;

        CommonDatabase::query()
            ->select('email', 'mt_user_id')
            ->chunk($this->chunk, function ($users) use (&$processed) {
                $batch = [];
                $emails = $users->pluck('email')->toArray();
                $mtUserIds = $users->pluck('mt_user_id')->toArray();

                //групповой запрос для actions_mt с result > 0
                $actionsCounts = ActionMT::query()
                    ->whereIn('email', $emails)
                    ->where('result', '>', 0)
                    ->select('email', DB::raw('COUNT(DISTINCT activity_id) as count'))
                    ->groupBy('email')
                    ->get()
                    ->keyBy('email');

                //групповой запрос для project_touches_mt с status === true
                $touchesCounts = ProjectTouchMT::query()
                    ->whereIn('mt_user_id', $mtUserIds)
                    ->where('status', true)
                    ->select('mt_user_id', DB::raw('COUNT(*) as count'))
                    ->groupBy('mt_user_id')
                    ->get()
                    ->keyBy('mt_user_id');

                foreach ($users as $user) {
                    $actionsCount = $actionsCounts[$user->email]->count ?? 0;
                    $touchesCount = $touchesCounts[$user->mt_user_id]->count ?? 0;

                    $batch[] = [
                        'email' => $user->email,
                        'resulting_actions' => $actionsCount + $touchesCount
                    ];
                }

                if (!empty($batch)) {
                    $this->withTableLock('common_database', function () use ($batch) {
                        CommonDatabase::query()->upsert(
                            $batch,
                            ['email'],
                            ['resulting_actions']
                        );
                    });
                }

                $processed += count($batch);
                $this->info("Обработано {$processed} записей для resulting_actions");
            });
    }

    /**
     * Расставляем категории для каждого пользователя
     * @throws \Exception
     */
    private function setCategories()
    {
        $this->info('Расстановка категорий...');

        $processed = 0;

        CommonDatabase::query()
            ->select('email', 'mt_user_id', 'specialization', 'registration_date')
            ->chunk($this->chunk, function ($users) use (&$processed) {
                $batch = [];
                $emails = $users->pluck('email')->toArray();
                $mtUserIds = $users->pluck('mt_user_id')->toArray();

                //получаем категории для всех пользователей в чанке
                $categories = $this->getCategories($users, $emails, $mtUserIds);

                foreach ($users as $user) {
                    $category = $categories[$user->email] ?? 'D';

                    $batch[] = [
                        'email' => $user->email,
                        'category' => $category
                    ];
                }

                if (!empty($batch)) {
                    $this->withTableLock('common_database', function () use ($batch) {
                        CommonDatabase::query()->upsert(
                            $batch,
                            ['email'],
                            ['category']
                        );
                    });
                }

                $processed += count($batch);
                $this->info("Обработано {$processed} записей для категорий");
            });
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
     * Проверяет условия для категории A
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
        $validMtUserIds = array_filter($mtUserIds, function ($id) { //фильтруем mtUserIds от null значений
            return !is_null($id);
        });

        if (!empty($validMtUserIds)) {
            $videoTouchesUsers = ProjectTouchMT::query()
                ->whereIn('project_touches_mt.mt_user_id', $validMtUserIds) // уточняем таблицу
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
     * Проверяет условия для категории B
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
