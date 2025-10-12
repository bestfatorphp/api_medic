<?php

namespace App\Console\Commands\ImportBitrixMt;

use App\Logging\CustomLog;
use App\Models\ActionMT;
use App\Models\ActivityMT;
use App\Models\CommonDatabase;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Panther\Client;

//todo: Перед использованием, установки на сервере для Panther
class ImportMTHeliosFile extends Common
{
    /**
     * Пример: php artisan import:medtouch-helios --chunk=5 --timeout=60
     * @var string
     */
    protected $signature = 'import:medtouch-helios
                            {--chunk=5 : Размер чанка скачивания в MB}
                            {--timeout=120 : Таймаут ожидания в секундах}
                            {--need-file=false : Сохранять ли CSV файл}';

    /**
     * Описание команды.
     * @var string
     */
    protected $description = 'Скачиваем большой файл CSV (Мед-тач) из Bitrix24 в приватное хранилище, действия пользователей и импортируем в БД';

    /**
     * Имя csv файла для сохранения.
     */
    protected string $TARGET_FILENAME = 'users-helios.csv';


    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Организуем процесс скачивания файла с страницы Медтач гелиос.
     * @return int
     */
    public function handle(): int
    {
        $client = $this->initBrowser();
        $this->timeout = $this->option('timeout');
        try {
            $this->logMemory('Начало выполнения');
            $fileUrl = $this->getTargetUrl();
            $this->info("Используется URL: " . $fileUrl);
            $downloadUrl = $this->extractDownloadUrl($client, $fileUrl);
            $this->info("URL для скачивания: " . $downloadUrl);

            $this->tmpFilePath = tempnam(sys_get_temp_dir(), 'medtouth_temp_');
            //скачиваем файл
            $this->downloadCsv($downloadUrl);
            //обрабатываем CSV
            $this->processCsv();

            //сохраняем файл если нужно
            if ($this->option('need-file') === 'true') {
                $this->saveToStorage();
                $this->info("Файл успешно сохранён: " . $this->getFullStoragePath());
            }

            $this->logMemory('Завершение выполнения');
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи');
            return CommandAlias::FAILURE;
        } finally {
            //убедимся, что временный файл удален даже в случае ошибки
            if (isset($this->tmpFilePath) && file_exists($this->tmpFilePath)) {
                unlink($this->tmpFilePath);
            }
            $client->quit();
        }
    }

    /**
     * Получаем URL для экспорта из переменных окружения.
     * @return string
     * @throws \Exception
     */
    private function getTargetUrl(): string
    {
        $url = env('BITRIX_MEDTOUCH_SCRIPT_URL');
        if (empty($url)) {
            throw new \Exception("BITRIX_MEDTOUCH_SCRIPT_URL не указан в .env");
        }
        return $url;
    }

    /**
     * Извлекаем URL для скачивания файла со страницы Bitrix.
     * Используем несколько стратегий поиска ссылки.
     * @param Client $client
     * @param string $pageUrl URL страницы экспорта
     * @return string
     * @throws \Exception
     */
    private function extractDownloadUrl(Client $client, string $pageUrl): string
    {
        $this->info("Загрузка страницы для получения ссылки...");
        $client->request('GET', $pageUrl);

        try {
            //ждём пока скрипт сформирует html с ссылкой
            $client->waitFor('body', $this->timeout);

            //проверяем на ошибки
            if ($client->getCrawler()->filter('.error, .exception')->count() > 0) {
                $error = $client->getCrawler()->filter('.error, .exception')->first()->text();
                throw new \Exception("Ошибка на странице: " . $error);
            }

            //получаем ссылку
            $link = $this->waitForDownloadLink($client);

            //делаем ссылку абсолютной
            if (!parse_url($link, PHP_URL_SCHEME)) {
                $baseUrl = parse_url($pageUrl, PHP_URL_SCHEME) . '://' . parse_url($pageUrl, PHP_URL_HOST);
                $link = rtrim($baseUrl, '/') . '/' . ltrim($link, '/');
            }

            return $link;

        } catch (\Exception $e) {
            $this->saveDebugInfo($client, 'error_helios');
            throw new \Exception("Не удалось найти ссылку для скачивания: " . $e->getMessage());
        }
    }

    /**
     * Орабатываем данные и запичываем в БД активность пользователей
     * @throws \Exception
     */
    private function processCsv(): void
    {
        $handle = fopen($this->tmpFilePath, 'r');
        if (!$handle) {
            throw new \Exception("Не удалось открыть CSV файл");
        }

        $userBitrixId = null;
        $email = null;
        $validActivityTypes = ['Лонгрид', 'Мероприятие', 'Видеовизит', 'Квиз'];
        $actionsToInsert = [];
        $countBatchInsert = 0;

        try {
            while (($row = fgetcsv($handle, 0, ";")) !== FALSE) {
                //проверяем строку с данными пользователя
                if (strpos($row[0], 'ID пользователя: ') === 0) {
                    if (preg_match('/ID пользователя:\s*(\d+)\s*,\s*e-mail пользователя:\s*(.+)/', $row[0], $matches)) {
                        $userBitrixId = $matches[1];

                        if (empty($userBitrixId) || empty($userBitrixId = trim($userBitrixId))) {
                            $this->warn("Пропущена некорректная строка с пустым ID пользователя: " . $row[0]);
                            continue;
                        }

                        $email = trim($matches[2]);

                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $userBitrixId = null;
                            $this->warn("Пропущена некорректная строка с невалидным email: {$email}");
                            continue;
                        }

                        $commonDb = $this->withTableLock('common_database', function () use ($email, $userBitrixId) {
                            return CommonDatabase::updateOrCreateWithMutators(
                                ['email' => $email],
                                ['email' => $email, 'old_mt_id' => $userBitrixId]
                            );
                        });

                        if ($userBitrixId) {
                            if (!$commonDb) {
                                $commonDb = CommonDatabase::query()->where('old_mt_id', $userBitrixId)->select(['id', 'email'])->first();
                            }

                            /** @var CommonDatabase $commonDb */
                            if ($commonDb && $commonDb->old_mt_id != (int)$userBitrixId) {
                                $this->warn("В строке нет равенста id-ков: {$commonDb->old_mt_id} (для email {$commonDb->email}) != {$userBitrixId} (для email {$email})");
                                $userBitrixId = $commonDb->old_mt_id; //переопределяю id
                            }
                        }
                    } else {
                        $this->warn("Пропущена некорректная строка с пользователем: " . $row[0]);
                    }
                    continue;
                }

                if (empty($userBitrixId)) {
                    $this->warn("Пропущена некорректная строка с пустым пользователем: " . $row[0]);
                    continue;
                }

                if (empty($row[0])) {
                    continue; //пустая строка
                }

                //проверяем допустимый тип активности
                $activityType = $row[0];
                if (!in_array($activityType, $validActivityTypes)) {
                    $this->warn("Пропущена строка с неизвестным типом активности: {$activityType}");
                    continue;
                }

                $dateTime = $this->parseDateTime($row[2] ?? '');

                //парсим активность
                $activityData = [
                    'type' => $activityType,
                    'name' => $row[1] ?? '',
                    'date_time' => $dateTime,
                    'is_online' => true
                ];

                /** @var ActivityMT $activity */
                $activity = $this->withTableLock('activities_mt', function () use ($activityData) {
                    return ActivityMT::firstOrCreate(
                        [
                            'type' => $activityData['type'],
                            'name' => $activityData['name']
                        ],
                        $activityData
                    );
                });

                //парсим duration (продолжительность)
                $durationInSeconds = floatval(str_replace(',', '.', str_replace('не передаются данные по продолжительности', '0', $row[3] ?? '')));
                $durationInMinutes = round($durationInSeconds / 60, 2); //преобразуем в минуты с округлением до 2-х знаков

                //парсим результат
                $result = 0;
                if ($activityType !== 'Квиз') {
                    $result = floatval(str_replace(',', '.', str_replace(['%', 'Просмотрено ', 'процентов'], '', $row[4] ?? '')));
                }

                $actionsToInsert[] = [
                    'email' => $email,
                    'old_mt_id' => $userBitrixId,
                    'activity_id' => $activity->id,
                    'date_time' => $activity->date_time,
                    'duration' => $durationInMinutes,
                    'result' => $result,
                ];

                if (count($actionsToInsert) >= self::BATCH_SIZE) {
                    ++$countBatchInsert;
                    $this->info("Пакетная вставка - $countBatchInsert");
                    $this->insertActions($actionsToInsert);
                    $actionsToInsert = [];
                    gc_mem_caches(); //очищаем кэши памяти Zend Engine
                }
            }

            //вставляем оставшиеся записи
            if (!empty($actionsToInsert)) {
                ++$countBatchInsert;
                $this->info("Пакетная вставка - $countBatchInsert");
                $this->insertActions($actionsToInsert);
            }
        } finally {
            fclose($handle);
        }

        $this->info("Обработка CSV завершена.");
    }

    /**
     * @param array $actionsToInsert
     * @throws \Exception
     */
    private function insertActions(array $actionsToInsert)
    {
        $this->withTableLock('actions_mt', function () use ($actionsToInsert) {
            ActionMT::insertOrIgnore($actionsToInsert);
        });
    }
}
