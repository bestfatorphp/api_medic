<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CommonDatabase;
use App\Models\UserMT;
use App\Models\Doctor;
use App\Models\ActionMT;
use Illuminate\Support\Facades\DB;

class NormalizeEmailCase extends Command
{
    /**
     * @var string
     */
    protected $signature = 'emails:normalize-case
                            {--chunk=500 : Количество записей для обработки за раз}
                            {--dry-run : Показать что будет сделано без реальных изменений}
                            {--stats : Показать только статистику}
                            {--tables= : Список таблиц для обработки через запятую (common_database,users_mt,doctors,actions_mt)}';

    /**
     * @var string
     */
    protected $description = 'Приводим email адреса к нижнему регистру во всех таблицах';

    /**
     * Доступные таблицы для обработки
     */
    private array $availableTables = [
        'common_database' => [CommonDatabase::class, 'CommonDatabase', 'common_database'],
        'users_mt' => [UserMT::class, 'UserMT', 'users_mt'],
        'doctors' => [Doctor::class, 'Doctor', 'doctors'],
        'actions_mt' => [ActionMT::class, 'ActionMT', 'actions_mt'],
    ];

    /**
     * @return int
     */
    public function handle()
    {
        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M');
        set_time_limit(0);
        DB::disableQueryLog();

        if ($this->option('stats')) {
            $this->showStatistics();
            return 0;
        }

        $dryRun = $this->option('dry-run');
        $chunkSize = (int)$this->option('chunk');
        $tablesOption = $this->option('tables');

        if ($dryRun) {
            $this->info('РЕЖИМ ПРЕДПРОСМОТРА: Изменения не будут сохранены');
        }

        $tablesToProcess = $this->getTablesToProcess($tablesOption);

        $this->info('Начинаем приведение email к нижнему регистру...');
        $this->showStatistics();

        foreach ($tablesToProcess as $tableKey => [$modelClass, $modelName, $tableName]) {
            $this->normalizeTable($modelClass, $modelName, $tableName, $dryRun, $chunkSize);
        }

        $this->info('Приведение email к нижнему регистру завершено!');
        $this->showStatistics();

        return 0;
    }

    /**
     * Получаем список таблиц для обработки на основе опции
     */
    private function getTablesToProcess(?string $tablesOption): array
    {
        if (empty($tablesOption)) {
            return $this->availableTables;
        }

        $requestedTables = array_map('trim', explode(',', $tablesOption));
        $tablesToProcess = [];

        foreach ($requestedTables as $table) {
            if (isset($this->availableTables[$table])) {
                $tablesToProcess[$table] = $this->availableTables[$table];
            } else {
                $this->warn("Таблица '{$table}' недоступна. Доступные таблицы: " .
                    implode(', ', array_keys($this->availableTables)));
            }
        }

        if (empty($tablesToProcess)) {
            $this->error('Не указаны корректные таблицы. Используются все таблицы.');
            return $this->availableTables;
        }

        return $tablesToProcess;
    }

    /**
     * Приводим email к нижнему регистру в конкретной таблице
     */
    private function normalizeTable(string $modelClass, string $modelName, string $tableName, bool $dryRun, int $chunkSize): void
    {
        $this->info("Обрабатываем email в таблице {$modelName}...");

        // Получаем общее количество записей для обновления
        $totalCount = DB::table($tableName)
            ->whereRaw('email != LOWER(email)')
            ->whereNotNull('email')
            ->count();

        if ($totalCount === 0) {
            $this->info("{$modelName}: Не найдено записей с email не в нижнем регистре");
            return;
        }

        $this->info("{$modelName}: Найдено {$totalCount} записей с email не в нижнем регистре");

        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->start();

        $updatedCount = 0;

        $offset = 0;
        do {
            $ids = DB::table($tableName)
                ->whereRaw('email != LOWER(email)')
                ->whereNotNull('email')
                ->orderBy('id')
                ->limit($chunkSize)
                ->offset($offset)
                ->pluck('id')
                ->toArray();

            if (empty($ids)) {
                break;
            }

            if (!$dryRun) {
                $affected = DB::table($tableName)
                    ->whereIn('id', $ids)
                    ->update([
                        'email' => DB::raw('LOWER(email)')
                    ]);

                $updatedCount += $affected;
            } else {
                //в режиме предпросмотра просто считаем количество
                $updatedCount += count($ids);
            }

            $progressBar->advance(count($ids));
            $offset += $chunkSize;

        } while (!empty($ids));

        $progressBar->finish();
        $this->newLine();

        $action = $dryRun ? 'Будет обработано' : 'Обработано';
        $this->info("{$modelName}: {$action} {$updatedCount} email адресов");
    }

    /**
     * Показываем статистику по всем таблицам
     */
    private function showStatistics(): void
    {
        $this->info('Статистика по регистру email:');

        $stats = [];

        foreach ($this->availableTables as $tableName => [$modelClass, $modelName, $dbTableName]) {
            $total = DB::table($dbTableName)->whereNotNull('email')->count();

            $lowercase = DB::table($dbTableName)
                ->whereNotNull('email')
                ->whereRaw('email = LOWER(email)')
                ->count();

            $nonLowercase = $total - $lowercase;

            $stats[] = [
                'Таблица' => $modelName,
                'Всего email' => $total,
                'Нижний регистр' => $lowercase,
                'Не нижний регистр' => $nonLowercase,
                'Процент нижнего регистра' => $total > 0 ? round(($lowercase / $total) * 100, 2) . '%' : '0%',
            ];
        }

        $this->table(
            ['Таблица', 'Всего email', 'Нижний регистр', 'Не нижний регистр', 'Процент нижнего регистра'],
            $stats
        );
    }
}
