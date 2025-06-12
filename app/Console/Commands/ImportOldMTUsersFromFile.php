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
class ImportOldMTUsersFromFile extends Command
{
    use WriteLockTrait;

    protected $signature = 'import:old-mt-users
                          {--chunk=1000 : Количество записей за одну транзакцию}';

    protected $description = 'Импорт пользователей старого сайта МедТач, с обработкой 1M+ записей в ограниченной памяти';

    /**
     * Путь к CSV файлу относительно директории storage/app
     */
    private string $filePath = 'additional/contacts_other_sources.csv';

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

            //основной процесс обработки данных
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
            'users_mt' => ['created' => 0, 'updated' => 0],
            'common_database' => ['created' => 0, 'updated' => 0],
            'skipped' => 0,
            'processed' => 0,
        ];

        //создаём прогресс-бар
        $progressBar = $this->output->createProgressBar();

        //буферы для накопления данных перед пакетной вставкой
        $usersMtBatch = [];
        $commonDbBatch = [];

        //наш чанк
        $chunkSize = (int)$this->option('chunk');

        //кэши для хранения информации о существующих записях
        $existingUsersCache = [];
        $existingCommonCache = [];

        //построчно читаем CSV файл
        while (!feof($file)) {
            //читаем строку
            $line = fgetcsv($file, 0, ';');

            //пропуск некорректных строк
            if ($line === false || count($line) !== count($headers)) {
                continue;
            }

            //создаём ассоциативный массив из заголовков и значений
            $record = array_combine($headers, $line);
            $record = array_map('trim', $record);

            //увеличиваем счетчик обработанных записей
            $stats['processed']++;

            //нормализуем email в record
            $email = $record['﻿email'] ?? '';
            $record['email'] = $email;
            unset($record['﻿email']);

            //нормализуем ФИО в record (если ФИО > 255 символов, забираем первые три слова)
            if (mb_strlen($fio = $record['ФИО']) > 255) {
                $words = preg_split('/\s+/', $fio);
                $record['ФИО'] = implode(' ', array_slice($words, 0, 3));
            }

            if (empty($email)) {
                $stats['skipped']++;
                $progressBar->advance();
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
//                Log::channel('commands')->warning("Не валидный email: {$email}");
                $stats['skipped']++;
                $progressBar->advance();
                continue;
            }

            //подготавливаем данные для вставки
            $usersMtData = $this->prepareUsersMtData($record);
            $commonDbData = $this->prepareCommonDatabaseData($record);

            //проверка существования записей через кэш
            $existingUsersMt = $existingUsersCache[$email] ?? null;
            $existingCommonDb = $existingCommonCache[$email] ?? null;

            //проверка в текущем батче (если запись еще не вставлена в БД)
            if (!$existingUsersMt && count($usersMtBatch) > 0) {
                foreach ($usersMtBatch as $user) {
                    if ($user['email'] === $email) {
                        $existingUsersMt = (object)['email' => $email];
                        break;
                    }
                }
            }

            //обработка существующих/новых записей для users_mt
            if ($existingUsersMt) {
                $this->updateUsersMt($existingUsersMt, $usersMtData, $stats);
                $commonDbData['mt_user_id'] = $existingUsersMt->id ?? null;
            } else {
                $usersMtBatch[] = $usersMtData;
            }

            //обработка существующих/новых записей для common_database
            if ($existingCommonDb) {
                $this->updateCommonDatabase($existingCommonDb, $commonDbData, $stats);
            } elseif (!$existingUsersMt) {
                $commonDbBatch[] = $commonDbData;
            }

            //обработка пакета при достижении лимита
            if ($stats['processed'] % $chunkSize === 0) {
                $this->processBatchWithCache($usersMtBatch, $commonDbBatch, $stats, $existingUsersCache, $existingCommonCache);
                $existingUsersCache = [];
                $existingCommonCache = [];
            }

            $progressBar->advance();
        }

        //обработка последнего неполного пакета
        if (!empty($usersMtBatch) || !empty($commonDbBatch)) {
            $this->processBatchWithCache($usersMtBatch, $commonDbBatch, $stats, $existingUsersCache, $existingCommonCache);
        }

        fclose($file);

        $progressBar->finish();
        $this->newLine();

        //статистика
        $this->info("Результат выполнения:");
        $this->info("- Обработано записей: {$stats['processed']}");
        $this->info("- Пропущено записей: {$stats['skipped']}");
        $this->info("- users_mt: {$stats['users_mt']['created']} создано, {$stats['users_mt']['updated']} обновлено");
        $this->info("- common_database: {$stats['common_database']['created']} создано, {$stats['common_database']['updated']} обновлено");
    }

    /**
     * Подготовка данных для таблицы users_mt
     * @param array $record         Строка данных из CSV
     * @return array                Подготовленные данные для вставки
     */
    protected function prepareUsersMtData(array $record): array
    {
        return [
            'email' => $record['email'],
            'full_name' => $record['ФИО'] ?? null,
            'phone' => $record['Телефон'] ?? null,
            'specialty' => $record['Специальность'] ?? null,
            'city' => $record['Город'] ?? null,
            'registration_website' => $record['Источник'] ?? null,
        ];
    }

    /**
     * Подготовка данных для таблицы common_database
     * @param array $record         Строка данных из CSV
     * @return array                Подготовленные данные для вставки
     */
    protected function prepareCommonDatabaseData(array $record): array
    {
        return [
            'email' => $record['email'],
            'full_name' => $record['ФИО'] ?? null,
            'city' => $record['Город'] ?? null,
            'specialty' => $record['Специальность'] ?? null,
            'phone' => $record['Телефон'] ?? null,
            'registration_website' => $record['Источник'] ?? null,
        ];
    }

    /**
     * Обновление существующей записи в users_mt
     * @param object $existingRecord        Существующая запись из БД
     * @param array $newData                Новые данные из CSV
     * @param array $stats                  Ссылка на массив статистики для обновления
     */
    protected function updateUsersMt(object $existingRecord, array $newData, array &$stats): void
    {
        $updateData = [];

        foreach ($newData as $key => $value) {
            if ($value !== null && (empty($existingRecord->$key) || $existingRecord->$key === '')) {
                $updateData[$key] = $value;
            }
        }

        if (!empty($updateData)) {
            UserMT::query()->where('id', $existingRecord->id)->update($updateData);
            $stats['users_mt']['updated']++;
        }
    }

    /**
     * Обновление существующей записи в common_database
     * @param object $existingRecord            Существующая запись из БД
     * @param array $newData                    Новые данные из CSV
     * @param array $stats                      Ссылка на массив статистики для обновления
     */
    protected function updateCommonDatabase(object $existingRecord, array $newData, array &$stats): void
    {
        $updateData = [];

        foreach ($newData as $key => $value) {
            if ($value !== null && (empty($existingRecord->$key) || $existingRecord->$key === '')) {
                $updateData[$key] = $value;
            }
        }

        if (!empty($updateData)) {
            CommonDatabase::query()->where('id', $existingRecord->id)->update($updateData);
            $stats['common_database']['updated']++;
        }
    }

    /**
     * Пакетная обработка данных с использованием кэша
     * @param array &$usersMtBatch          Ссылка на батч данных для users_mt
     * @param array &$commonDbBatch         Ссылка на батч данных для common_database
     * @param array &$stats                 Ссылка на статистику выполнения
     * @param array &$existingUsersCache    Ссылка на кэш существующих пользователей
     * @param array &$existingCommonCache   Ссылка на кэш common_database
     */
    protected function processBatchWithCache(
        array &$usersMtBatch,
        array &$commonDbBatch,
        array &$stats,
        array &$existingUsersCache,
        array &$existingCommonCache
    ): void {
        DB::transaction(function () use (&$usersMtBatch, &$commonDbBatch, &$stats, &$existingUsersCache, &$existingCommonCache) {
            if (!empty($usersMtBatch)) {
                //получаем все email из текущего пакета
                $emails = array_column($usersMtBatch, 'email');

                //получаем существующие записи users_mt
                $existingUsers = UserMT::query()
                    ->whereIn('email', $emails)
                    ->get()
                    ->keyBy('email');  //преобразуем в ассоциативный массив по email

                //получаем существующие записи common_database
                $existingCommon = CommonDatabase::query()
                    ->whereIn('email', $emails)
                    ->get()
                    ->keyBy('email');

                // Обновляем кэш существующих записей
                foreach ($existingUsers as $email => $user) {
                    $existingUsersCache[$email] = $user;
                }

                foreach ($existingCommon as $email => $common) {
                    $existingCommonCache[$email] = $common;
                }

                //фильтруем только новые записи (которых нет в БД)
                $newUsers = array_filter($usersMtBatch, fn($user) => !isset($existingUsers[$user['email']]));

                //вставляем только новые записи
                if (!empty($newUsers)) {
                    $this->withTableLock('users_mt', function () use ($newUsers) {
                        UserMT::upsert($newUsers, ['email']);
                    });
                    $stats['users_mt']['created'] += count($newUsers);  //обновляем статистику
                }

                //получаем ID всех пользователей (новых и существующих)
                $allUsers = UserMT::query()
                    ->whereIn('email', $emails)
                    ->pluck('id', 'email')
                    ->toArray();

                //проставляем mt_user_id для связанных записей в common_database
                foreach ($commonDbBatch as &$record) {
                    if (isset($allUsers[$record['email']])) {
                        $record['mt_user_id'] = $allUsers[$record['email']];
                    }
                }
                unset($record);

                $usersMtBatch = [];
            }

            //обработка пакета для common_database
            if (!empty($commonDbBatch)) {
                //фильтруем только новые записи
                $newCommon = array_filter($commonDbBatch, fn($common) => !isset($existingCommonCache[$common['email']]));

                //вставляем только новые записи
                if (!empty($newCommon)) {
                    $this->withTableLock('common_database', function () use ($newCommon) {
                        CommonDatabase::upsert($newCommon, ['email']);
                    });
                    $stats['common_database']['created'] += count($newCommon);
                }

                $commonDbBatch = [];
            }
        });

        //чистим память
        $usersMtBatch = [];
        $commonDbBatch = [];
        $existingUsersCache = [];
        $existingCommonCache = [];
        gc_mem_caches(); //очищаем кэши памяти Zend Engine
    }
}
