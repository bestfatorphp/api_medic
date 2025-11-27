<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CommonDatabase;
use App\Models\UserMT;
use App\Models\Doctor;
use Illuminate\Support\Facades\DB;

class NormalizeFullNameTrim extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fullname:normalize-trim
                            {--chunk=500 : Количество записей для обработки за раз}
                            {--dry-run : Показать что будет сделано без реальных изменений}
                            {--stats : Показать только статистику}
                            {--tables= : Список таблиц для обработки через запятую (common_database,users_mt,doctors)}';

    /**
     * @var string
     */
    protected $description = 'Применяем trim к полям full_name во всех таблицах';

    /**
     * Доступные таблицы для обработки
     */
    private array $availableTables = [
        'common_database' => [CommonDatabase::class, 'CommonDatabase', 'common_database'],
        'users_mt' => [UserMT::class, 'UserMT', 'users_mt'],
        'doctors' => [Doctor::class, 'Doctor', 'doctors']
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

        $this->info('Начинаем применение trim к полям full_name...');
        $this->showStatistics();

        foreach ($tablesToProcess as $tableKey => [$modelClass, $modelName, $tableName]) {
            $this->normalizeTable($modelClass, $modelName, $tableName, $dryRun, $chunkSize);
        }

        $this->info('Применение trim к полям full_name завершено!');
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
     * Применяем trim к полю full_name в конкретной таблице
     */
    private function normalizeTable(string $modelClass, string $modelName, string $tableName, bool $dryRun, int $chunkSize): void
    {
        $this->info("Обрабатываем full_name в таблице {$modelName}...");

        //получаем общее количество записей для обновления
        $totalCount = DB::table($tableName)
            ->where(function($query) {
                $query->whereRaw('full_name != TRIM(full_name)')
                    ->orWhereRaw('LENGTH(full_name) != CHAR_LENGTH(full_name)');
            })
            ->whereNotNull('full_name')
            ->count();

        if ($totalCount === 0) {
            $this->info("{$modelName}: Не найдено записей с лишними пробелами в full_name");
            return;
        }

        $this->info("{$modelName}: Найдено {$totalCount} записей с лишними пробелами в full_name");

        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->start();

        $updatedCount = 0;

        $offset = 0;
        do {
            //получаем ID записей, которые нужно обновить
            $ids = DB::table($tableName)
                ->where(function($query) {
                    $query->whereRaw('full_name != TRIM(full_name)')
                        ->orWhereRaw('LENGTH(full_name) != CHAR_LENGTH(full_name)');
                })
                ->whereNotNull('full_name')
                ->orderBy('id')
                ->limit($chunkSize)
                ->offset($offset)
                ->pluck('id')
                ->toArray();

            if (empty($ids)) {
                break;
            }

            if (!$dryRun) {
                $affected = DB::update("
                    UPDATE {$tableName}
                    SET full_name = TRIM(full_name)
                    WHERE id IN (" . implode(',', $ids) . ")
                ");

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
        $this->info("{$modelName}: {$action} {$updatedCount} записей с полем full_name");
    }

    /**
     * Показываем статистику по всем таблицам
     */
    private function showStatistics(): void
    {
        $this->info('Статистика по полям full_name:');

        $stats = [];

        foreach ($this->availableTables as $tableName => [$modelClass, $modelName, $dbTableName]) {
            $total = DB::table($dbTableName)->whereNotNull('full_name')->count();

            $trimmed = DB::table($dbTableName)
                ->whereNotNull('full_name')
                ->whereRaw('full_name = TRIM(full_name)')
                ->whereRaw('LENGTH(full_name) = CHAR_LENGTH(full_name)')
                ->count();

            $nonTrimmed = $total - $trimmed;

            $stats[] = [
                'Таблица' => $modelName,
                'Всего full_name' => $total,
                'Без лишних пробелов' => $trimmed,
                'С лишними пробелами' => $nonTrimmed,
                'Процент нормализованных' => $total > 0 ? round(($trimmed / $total) * 100, 2) . '%' : '0%',
            ];
        }

        $this->table(
            ['Таблица', 'Всего full_name', 'Без лишних пробелов', 'С лишними пробелами', 'Процент нормализованных'],
            $stats
        );
    }
}
