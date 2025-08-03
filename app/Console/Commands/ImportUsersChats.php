<?php

namespace App\Console\Commands;

use App\Logging\CustomLog;
use App\Models\CommonDatabase;
use App\Models\UserChat;
use App\Traits\WriteLockTrait;
use Illuminate\Console\Command;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ImportUsersChats extends Command
{
    use WriteLockTrait;

    protected $signature = 'import:users-chats
                          {--chunk=500 : Количество записей за одну транзакцию}';

    protected $description = 'Импорт чатов пользователей (разовая команда) по апи и из файла, с обработкой 1M+ записей в ограниченной памяти';

    /**
     * Путь к файлу
     * @var string
     */
    private string $filePath = 'additional/tg_users.csv';

    /**
     * Размер пакета
     * @var int
     */
    private int $batchSize;

    public function handle(): int
    {
        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M');
        set_time_limit(0);
        DB::disableQueryLog();

        try {
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Начало импорта');
            $this->batchSize = $this->option('chunk');

            //обработка данных из API
            $this->processApiData();

            //обработка данных из файла
            $this->processFileData();

            //сохраняем данные в common_database
            $this->processCommonDbData();

            $this->info("\n[" . Carbon::now()->format('Y-m-d H:i:s') . "] Импорт завершен");
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи: ' . $e->getMessage());
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Запоняем данные в common_database
     */
    private function processCommonDbData()
    {
        $this->info('Загрузка данных в CommonDatabase...');

        /** @var UserChat $chat */
        foreach (UserChat::query()->get() as $chat) {
            $this->withTableLock('common_database', function () use ($chat) {
                $commonData = $chat->common_database ?? new CommonDatabase();

                $commonData->full_name = $chat->full_name;
                $commonData->username = $chat->username;
                $commonData->email = $chat->email;
                $commonData->specialization = $chat->specialization;
                $commonData->acquisition_tool = $chat->channel;

                $commonData->save();
            });
        }
    }

    /**
     * Импортируем по API
     * @throws \Exception
     */
    private function processApiData(): void
    {
        $this->info('Загрузка данных из API...');

        $tmpFilePath = tempnam(sys_get_temp_dir(), 'telegram_temp_');
        if ($tmpFilePath === false) {
            throw new \Exception('Не удалось создать временный файл');
        }

        try {
            $response = Http::timeout(3600)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('TELEGRAM_BOT_API_TOKEN'),
                    'Accept' => 'text/csv',
                ])
                ->sink($tmpFilePath)
                ->get(env('TELEGRAM_BOT_API_URL'));

            if (!$response->successful()) {
                throw new \Exception('Ошибка при запросе к API: ' . $response->status());
            }

            $this->processCsvFile($tmpFilePath, 'API');
        } finally {
            if (file_exists($tmpFilePath)) {
                unlink($tmpFilePath);
            }
        }
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
        $this->processCsvFile($filePath, 'File');
    }

    /**
     * Обрабатываем файл csv
     * @param string $filePath
     * @param string $source
     * @throws \Exception
     */
    private function processCsvFile(string $filePath, string $source): void
    {
        $file = fopen($filePath, 'r');
        if (!$file) {
            throw new \Exception("Ошибка открытия файла: {$filePath}");
        }

        try {
            $progressBar = $this->output->createProgressBar();
            $batch = [];
            $headers = null;

            while (($line = fgets($file)) !== false) {
                if (empty(trim($line))) continue;

                if ($headers === null) {
                    $headers = str_getcsv($line);
                    continue;
                }

                $data = str_getcsv($line);
                if (count($data) !== count($headers)) continue;

                $record = array_combine($headers, $data);
                $batch[] = $this->prepareRecord($record);

                if (count($batch) >= $this->batchSize) {
                    $this->insertBatch($batch);
                }
            }

            if (!empty($batch)) {
                $this->insertBatch($batch);
            }

            $progressBar->finish();
        } finally {
            fclose($file);
        }
    }

    /**
     * @param array $record
     * @return array
     */
    #[ArrayShape(['full_name' => "mixed", 'email' => "mixed", 'username' => "mixed|null", 'specialization' => "mixed", 'channel' => "mixed|null", 'registration_date' => "\Carbon\Carbon|null"])]
    private function prepareRecord(array $record): array
    {
        return [
            'full_name' => $record['full_name'],
            'email' => $record['email'],
            'username' => empty($record['username']) ? null : $record['username'],
            'specialization' => $record['specialization'],
            'channel' => !empty($record['channel']) ? $record['channel'] : null,
            'registration_date' => !empty($record['registration_date']) ? Carbon::parse($record['registration_date']) : null,
        ];
    }

    /**
     * Пакетная вставка чатов
     * @param array $batch
     */
    private function insertBatch(array &$batch): void
    {
        $this->withTableLock('users_chats', function () use ($batch) {
            UserChat::insertWithMutators($batch);
        });
        $batch = [];
        gc_mem_caches();
    }
}
