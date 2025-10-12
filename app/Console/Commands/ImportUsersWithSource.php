<?php

namespace App\Console\Commands;

use App\Logging\CustomLog;
use App\Models\CommonDatabase;
use App\Traits\WriteLockTrait;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use OpenSpout\Reader\XLSX\Reader;

class ImportUsersWithSource extends Command
{
    use WriteLockTrait;

    protected $signature = 'import:users-source
                          {--chunk=500 : Количество записей за одну транзакцию}';

    protected $description = 'Импорт пользователей c источником (разовая команда) из файла, с обработкой 1M+ записей в ограниченной памяти';

    /**
     * Путь к файлу
     * @var string
     */
    private string $filePath = 'additional/contacts_with_source.xlsx';

    /**
     * Размер пакета
     * @var int
     */
    private int $batchSize;

    /**
     * Все возможные поля таблицы
     * @var array
     */
    private array $allFields = [
//        'new_mt_id',
//        'old_mt_id',
        'email',
        'full_name',
        'city',
        'region',
        'country',
        'specialty',
        'interests',
        'phone',
//        'mt_user_id',
        'registration_date',
        'gender',
        'birth_date',
        'registration_website',
        'acquisition_tool',
        'acquisition_method',
        'username',
        'specialization',
        'planned_actions',
        'resulting_actions',
        'verification_status',
        'pharma',
        'email_status',
        'source',
        'category'
    ];

    public function handle(): int
    {
        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M');
        set_time_limit(0);
        DB::disableQueryLog();

        try {
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Начало импорта');
            $this->batchSize = $this->option('chunk');

            //обработка данных из файла
            $this->processFileData();

            $this->info("\n[" . Carbon::now()->format('Y-m-d H:i:s') . "] Импорт завершен");
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи: ' . $e->getMessage());
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Заполняем данные в common_database
     * @throws \Exception
     */
    private function insertBatch(&$batch)
    {
        $this->withTableLock('common_database', function () use ($batch) {
            //получаем emails из текущего батча для проверки существующих записей
            $emails = array_column($batch, 'email');
            $emails = array_filter($emails);

            if (empty($emails)) {
                return;
            }

            //находим существующие записи по emails
            $existingUsers = CommonDatabase::whereIn('email', $emails)
                ->select($this->allFields)
                ->get()
                ->keyBy('email')
                ->toArray();

            //подготавливаем данные для upsert
            $preparedData = [];
            foreach ($batch as $record) {
                if (empty($record['email'])) {
                    continue;
                }

                $email = $record['email'];

                if (isset($existingUsers[$email])) {
                    //если пользователь существует - объединяем данные
                    $existingData = $existingUsers[$email];
                    $mergedData = $this->mergeUserData($existingData, $record);
                    $preparedData[] = $this->normalizeRecord($mergedData);
                } else {
                    //новый пользователь
                    $preparedData[] = $this->normalizeRecord($record);
                }
            }

            if (!empty($preparedData)) {
                CommonDatabase::upsertWithMutators($preparedData, ['email'], $this->allFields);
            }
        });
    }

    /**
     * Нормализует запись - гарантирует наличие всех полей
     * @param array $record
     * @return array
     */
    private function normalizeRecord(array $record): array
    {
        $normalized = [];

        foreach ($this->allFields as $field) {
            if ($field === 'source') {
                $normalized[$field] = $record['источник'] ?? $record['source'] ?? null;
            } elseif ($field === 'pharma' && is_string($record[$field] ?? null)) {
                $result[$field] = $record[$field] === 't';
            } else {
                $normalized[$field] = $record[$field] ?? null;
            }
        }

        return $normalized;
    }

    /**
     * Объединяет данные существующего пользователя с новыми данными из файла
     * @param array $existingData
     * @param array $newData
     * @return array
     */
    private function mergeUserData(array $existingData, array $newData): array
    {
        $result = $existingData;

        //обновляем только те поля, которые не null в новых данных
        foreach ($newData as $field => $value) {
            if (empty($existingData[$field]) && $value !== null && $value !== '' && $value !== '\\N') {
                if (in_array($field, ['planned_actions', 'resulting_actions']) && $value === '0') {
                    $result[$field] = 0;
                }
                elseif ($field == 'pharma') {
                    $result[$field] = $value;
                }
                if ($field === 'источник') {
                    $result['source'] = $value;
                }
                elseif (!in_array($field, ['pharma', 'planned_actions', 'resulting_actions'])) {
                    $result[$field] = $value;
                }
                else {
                    $result[$field] = $value;
                }
            }
        }

        //гарантируем, что pharma не будет null
        if ($result['pharma'] === null) {
            $result['pharma'] = false;
        }

        return $result;
    }

    /**
     * Импортируем из файла
     * @throws \Exception
     */
    private function processFileData(): void
    {
        $filePath = storage_path('app/' . $this->filePath);

        if (!file_exists($filePath)) {
            $this->warn("Файл не найден: {$filePath}");
            return;
        }

        $this->info('Обработка данных из файла...');
        $this->processExcelFile($filePath);
    }

    /**
     * Обрабатываем Excel файл с помощью OpenSpout (потоковое чтение)
     * @param string $filePath
     * @throws \Exception
     */
    private function processExcelFile(string $filePath): void
    {
        $this->info('Чтение Excel файла...');

        $reader = new Reader();

        try {
            $reader->open($filePath);

            $sheetIterator = $reader->getSheetIterator();
            $firstSheet = $sheetIterator->current();

            $rowIterator = $firstSheet->getRowIterator();
            $rowIterator->rewind();

            $progressBar = $this->output->createProgressBar();
            $progressBar->setFormat(' %current% строк [%bar%] %elapsed:6s%');

            $batch = [];
            $headers = [];
            $processed = 0;
            $currentRow = 0;
            $seenEmailsInBatch = []; //отслеживаем дубликаты записе по email в текущем батче

            foreach ($rowIterator as $row) {
                $currentRow++;
                $rowData = $row->toArray();

                //пропускаем полностью пустые строки
                if (empty(array_filter($rowData, function($value) {
                    return $value !== null && $value !== '';
                }))) {
                    $progressBar->advance();
                    continue;
                }

                //первая строка - заголовки
                if ($currentRow === 1) {
                    $headers = array_map(function($header) {
                        return is_string($header) ? trim(mb_strtolower($header)) : $header;
                    }, $rowData);
                    $progressBar->advance();
                    continue;
                }

                //дополняем массив до количества заголовков
                while (count($rowData) < count($headers)) {
                    $rowData[] = null;
                }

                //обрезаем если данных больше чем заголовков
                if (count($rowData) > count($headers)) {
                    $rowData = array_slice($rowData, 0, count($headers));
                }

                //объединяем с заголовками
                $record = array_combine($headers, $rowData);
                $preparedRecord = $this->prepareRecord($record);

                if ($preparedRecord && !empty($preparedRecord['email'])) {
                    $email = $preparedRecord['email'];

                    //проверяем, нет ли уже этого email в текущем батче
                    if (!isset($seenEmailsInBatch[$email])) {
                        $batch[] = $this->normalizeRecord($preparedRecord);
                        $seenEmailsInBatch[$email] = true;
                        $processed++;
                    } else {
                        $this->warn("Пропущен дубликат в пакете: {$email}");
                    }
                }

                if (count($batch) >= $this->batchSize) {
                    $this->insertBatch($batch);
                    $batch = [];
                    $seenEmailsInBatch = [];
                    gc_mem_caches();
                }

                $progressBar->advance();
            }

            if (!empty($batch)) {
                $this->insertBatch($batch);
            }

            $progressBar->finish();

            $this->info("\nОбработано записей: " . $processed);

        } catch (\Exception $e) {
            throw new \Exception("Ошибка чтения Excel файла: " . $e->getMessage());
        } finally {
            $reader->close();
        }
    }

    /**
     * Подготавливает запись для вставки/обновления
     * @param array $record
     * @return array|null
     */
    private function prepareRecord(array $record): ?array
    {
        //пропускаем записи без email
        if (empty($record['email']) || $record['email'] === '\\N') {
            return null;
        }

        //преобразуем \N в null и очищаем данные
        $prepared = [];
        foreach ($record as $key => $value) {
            if ($value === '\\N' || $value === '' || $value === null) {
                $prepared[$key] = null;
            } else {
                if (is_string($value)) {
                    $prepared[$key] = trim($value);
                } else {
                    $prepared[$key] = $value;
                }
            }
        }

        if (!empty($prepared['registration_date'])) {
            try {
                $prepared['registration_date'] = $this->parseDate($prepared['registration_date']);
            } catch (\Exception $e) {
                $prepared['registration_date'] = null;
            }
        }

        if (!empty($prepared['birth_date'])) {
            try {
                $prepared['birth_date'] = $this->parseDate($prepared['birth_date'], true);
            } catch (\Exception $e) {
                $prepared['birth_date'] = null;
            }
        }

        if (isset($prepared['pharma'])) {
            if ($prepared['pharma'] === 't' || $prepared['pharma'] === true || $prepared['pharma'] === 1) {
                $prepared['pharma'] = true;
            } elseif ($prepared['pharma'] === 'f' || $prepared['pharma'] === false || $prepared['pharma'] === 0) {
                $prepared['pharma'] = false;
            } else {
                $prepared['pharma'] = false;
            }
        } else {
            $prepared['pharma'] = false;
        }

        $numericFields = ['planned_actions', 'resulting_actions', 'mt_user_id', 'new_mt_id', 'old_mt_id', 'id'];
        foreach ($numericFields as $field) {
            if (isset($prepared[$field]) && is_numeric($prepared[$field])) {
                $prepared[$field] = (int)$prepared[$field];
            }
        }

        return $prepared;
    }

    /**
     * Парсит дату из различных форматов
     * @param mixed $date
     * @param bool $dateOnly Только дата (без времени)
     * @return string|null
     */
    private function parseDate($date, bool $dateOnly = false): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            //если это Excel timestamp (число)
            if (is_numeric($date)) {
                $carbon = Carbon::createFromTimestamp((($date - 25569) * 86400));
                return $dateOnly ? $carbon->format('Y-m-d') : $carbon->format('Y-m-d H:i:s');
            }

            //если это строка
            if (is_string($date)) {
                $carbon = Carbon::parse($date);
                return $dateOnly ? $carbon->format('Y-m-d') : $carbon->format('Y-m-d H:i:s');
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
