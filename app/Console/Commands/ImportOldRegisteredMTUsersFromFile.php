<?php

namespace App\Console\Commands;

use App\Logging\CustomLog;
use App\Models\CommonDatabase;
use App\Models\UserMT;
use App\Traits\WriteLockTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\DB;

/**
 * Перед использованием, поместить файл по пути storage/app/additional/contacts_other_sources.csv
 */
class ImportOldRegisteredMTUsersFromFile extends Command
{
    use WriteLockTrait;

    protected $signature = 'import:old-registered-mt-users
                          {--chunk=500 : Количество записей за одну транзакцию}';

    protected $description = 'Импорт дат регистрации пользователей старого сайта МедТач из файла, с обработкой 1M+ записей в ограниченной памяти (разовая команда)';

    /**
     * Путь к CSV файлу относительно директории storage/app
     */
    private string $filePath = 'private/registered-users.csv';

    /**
     * @return int
     */
    public function handle(): int
    {
        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M'); //установливаем ограничение по памяти
        set_time_limit(0); //без ограничения времени выполнения
        DB::disableQueryLog(); //отключаем логирование запросов

        try {
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Начало импорта');
            $this->processData();
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Импорт завершен');
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи');
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Основной метод обработки данных из CSV файла
     * @throws \Exception
     */
    private function processData()
    {
        $filePath = storage_path('app/' . $this->filePath);

        if (!file_exists($filePath)) {
            throw new \Exception("Файл не найден: {$filePath}");
        }

        $file = fopen($filePath, 'r');
        if (!$file) {
            throw new \Exception("Ошибка получения файла: {$filePath}");
        }

        $headers = fgetcsv($file, 0, ';');
        if ($headers === false) {
            fclose($file);
            throw new \Exception("Ошибка чтения заголовков файла: {$filePath}");
        }

        $stats = [
            'common_database' => 0,
            'skipped' => 0,
            'processed' => 0,
        ];

        //создаём прогресс-бар
        $progressBar = $this->output->createProgressBar();

        //буферы для накопления данных перед пакетной вставкой
        $commonDbBatch = [];

        $EMAILS = [];

        //наш чанк
        $chunkSize = (int)$this->option('chunk');

        //построчно читаем CSV файл
        while (!feof($file)) {
            //читаем строку
            $line = fgetcsv($file, 0, ';');

            //пропуск некорректных строк
            if ($line === false || count($line) !== count($headers)) {
                continue;
            }

            //увеличиваем счетчик обработанных записей
            $stats['processed']++;

            $email = trim(strtolower($line[7]));
            $registrationDate = trim($line[4]);

            if ($registrationDate === "00.00.0000 00:00:00") {
                continue;
            }

            if (empty($email)) {
                $stats['skipped']++;
                $progressBar->advance();
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->warn("Не валидный email: {$email}");
                $stats['skipped']++;
                $progressBar->advance();
                continue;
            }

            if (in_array($email, $EMAILS)) {
                continue;
            }

            $EMAILS[] = $email;

            //подготавливаем данные для вставки
            $commonDbBatch[] = $this->prepareCommonDatabaseData($email, $registrationDate);


            //обработка пакета при достижении лимита
            if ($stats['processed'] % $chunkSize === 0) {
                $this->processBatch($commonDbBatch, $stats, $EMAILS);
            }

            $progressBar->advance();
        }

        //обработка последнего неполного пакета
        if (!empty($commonDbBatch)) {
            $this->processBatch($commonDbBatch, $stats, $EMAILS);
        }

        fclose($file);

        $progressBar->finish();
        $this->newLine();

        //статистика
        $this->info("Результат выполнения:");
        $this->info("- Обработано записей: {$stats['processed']}");
        $this->info("- Пропущено записей: {$stats['skipped']}");
        $this->info("- common_database: {$stats['common_database']} вставлено");
    }

    /**
     * @param string $email
     * @param string|null $registrationDate
     * @return array
     */
    protected function prepareCommonDatabaseData(string $email, ?string $registrationDate): array
    {
        return [
            'email' => $email,
            'registration_date' => $registrationDate ?? null,
        ];
    }

    /**
     * Пакетная обработка данных
     * @param array &$commonDbBatch         Ссылка на батч данных для common_database
     * @param array &$stats                 Ссылка на статистику выполнения
     */
    protected function processBatch(
        array &$commonDbBatch,
        array &$stats,
        array &$EMAILS
    ): void {
        DB::transaction(function () use (&$commonDbBatch, &$stats, &$EMAILS) {
            if (!empty($commonDbBatch)) {
                //вставляем новые записи
                $this->withTableLock('common_database', function () use ($commonDbBatch) {
                    UserMT::upsertWithMutators($commonDbBatch, ['email'], [
                        'registration_date',
                    ], 'email');
                });

                $this->withTableLock('common_database', function () use ($commonDbBatch) {
                    CommonDatabase::upsertWithMutators($commonDbBatch, ['email'], [
                        'registration_date',
                    ], 'email');
                });
                $stats['common_database'] += count($commonDbBatch);

                $commonDbBatch = [];
            }
        });
        $EMAILS = [];
        //чистим память
        gc_mem_caches(); //очищаем кэши памяти Zend Engine
    }
}
