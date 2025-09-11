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
            $this->calculatePlannedActions();

            //считаем resulting_actions
            $this->calculateResultingActions();

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
}
