<?php

namespace App\Console\Commands;

use App\Logging\CustomLog;
use App\Models\UserMT;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Symfony\Component\Console\Helper\ProgressBar;

class ImportTelegramUsers extends Command
{
    /**
     * Пример: php artisan import:telegram-users --chunk=1000 --memory=128
     * @var string
     */
    protected $signature = 'import:telegram-users
                          {--chunk=1000 : Количество записей за одну транзакцию}
                          {--memory=128 : Лимит памяти в MB}';

    /**
     * @var string
     */
    protected $description = 'Импорт пользователей с обработкой 1M+ записей в ограниченной памяти';

    /**
     * @return int
     */
    public function handle()
    {
        //установливаем ограничение по памяти
        ini_set('memory_limit', $this->option('memory') . 'M');
        set_time_limit(0); //без ограничения времени выполнения
        DB::disableQueryLog(); //отключаем логирование запросов

        try {
            $this->info('[' . date('Y-m-d H:i:s') . '] Начало импорта');

            //основной процесс обработки данных
            $this->processStream();

            $this->info(PHP_EOL . '[' . date('Y-m-d H:i:s') . '] Импорт завершен');
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи');
            return CommandAlias::FAILURE;
        } finally {
            //убедимся, что временный файл удален даже в случае ошибки
            if (isset($tmpFilePath) && file_exists($tmpFilePath)) {
                unlink($tmpFilePath);
            }
        }
    }

    /**
     * Потоковая обработка CSV данных
     * Используем временный файл для хранения данных, чтобы не загружать их в память
     * @throws \Exception
     */
    protected function processStream()
    {
        //создаем временный файл
        $tmpFilePath = tempnam(sys_get_temp_dir(), 'telegram_temp_');
        if ($tmpFilePath === false) {
            throw new \Exception('Не удалось создать временный файл');
        }

        //открываем файл для записи
        $tmpFile = fopen($tmpFilePath, 'w+');
        if ($tmpFile === false) {
            throw new \Exception('Не удалось открыть временный файл для записи');
        }

        //загружаем данные во временный файл (минуя память PHP)
        $this->info('Загрузка данных из API...');
        Http::timeout(3600) //таймаут 1 час
        ->withHeaders([
            'Authorization' => 'Bearer ' . env('TELEGRAM_BOT_API_TOKEN'),
            'Accept' => 'text/csv',
        ])
            ->sink($tmpFile) //пишем сразу в файл
            ->get(env('TELEGRAM_BOT_API_URL'));

        //перематываем файл для чтения
        rewind($tmpFile);

        //построчная обработка CSV
        $this->info('Обработка CSV данных...');
        $progressBar = $this->output->createProgressBar();
        $batch = [];
        $headers = null;

        while (!feof($tmpFile)) {
            //читаем строку (без загрузки всего файла в память)
            $line = fgets($tmpFile);
            if ($line === false) break;

            //пропускаем заголовки
            if ($headers === null) {
                $headers = str_getcsv($line);
                continue;
            }

            //парсим CSV строки
            $data = str_getcsv($line);
            if (count($data) !== count($headers)) continue;

            //формируем ассоциативный массив
            $record = array_combine($headers, $data);
            $batch[] = $record;

            //обработка пакета при достижении лимита
            if (count($batch) >= $this->option('chunk')) {
                $this->processBatch($batch, $progressBar);
                $batch = []; //чистим пакет
                gc_collect_cycles(); //принудительно очистим память
            }
        }

        //обработка последнего неполного пакета
        if (!empty($batch)) {
            $this->processBatch($batch, $progressBar);
        }

        //закрываем файл и прогресс-бар
        fclose($tmpFile);
        $progressBar->finish();

        //удаляем временный файл
        if (file_exists($tmpFilePath)) {
            unlink($tmpFilePath);
        }
    }

    /**
     * Обработка пакета записей
     * @param array $batch Пакет данных для обработки
     * @param ProgressBar $progressBar
     */
    protected function processBatch(array $batch, ProgressBar $progressBar)
    {
        DB::transaction(function () use ($batch) {
            foreach ($batch as $record) {
                $email = $record['email'] ?? null;
                if (!$email) {
                    continue; //пропускаем записи без email
                }

                $registrationDate = !empty($record['registration_date'])
                    ? Carbon::parse($record['registration_date'])
                    : null;
                $fullName = $record['full_name'] ?? null;

                /** @var UserMT $user */
                $user = UserMT::firstOrNew(['email' => $email]);
                if (!$user->exists) {
                    $user->fill([
                        'full_name' => $fullName,
                        'registration_date' => $registrationDate
                    ]);
                    $user->save();
                }

                $newSpecialization = $record['specialization'] ?? null;
                if (!$newSpecialization) {
                    continue; //пропускаем записи без specialization
                }

                if ($user->common_database) {
                    $current = $user->common_database->specialization ?? '';

                    $values = array_map('trim', explode(',', $current));

                    if (!in_array($newSpecialization, $values)) {
                        //через запятую, новые значения
                        $updated = $current === ''
                            ? $newSpecialization
                            : $current . ',' . $newSpecialization;

                        if ($user->common_database->specialization !== $updated) {
                            $user->common_database()->update(['specialization' => $updated]);
                        }
                    }
                } else {
                    $user->common_database()->create([
                        'mt_user_id' => $user->id,
                        'full_name' => $fullName,
                        'username' => $record['username'] ?? null,
                        'specialization' => $newSpecialization,
                        'acquisition_tool' => $record['channel'] ?? null,
                        'registration_date' => $registrationDate
                    ]);
                }
            }
        });

        //обновляем прогресс-бар
        $progressBar->advance(count($batch));
    }
}
