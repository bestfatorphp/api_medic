<?php

namespace App\Console\Commands\ImportBitrixMt;

use App\Facades\UniSender;
use App\Models\UnisenderCampaign;
use App\Models\UnisenderContact;
use App\Models\UnisenderParticipation;
use App\Traits\WriteLockTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JetBrains\PhpStorm\NoReturn;
use Symfony\Component\Panther\Client;

class Common extends Command
{
    use WriteLockTrait;

    /**
     * @var string
     */
    protected $signature = 'import:bitrix-mt';

    /**
     * @var string
     */
    protected $description = 'Общий класс для команд импорта кампаний';

    /**
     * Безопасный буфер памяти (в байтах), оставляемый свободным при работе с большими файлами.
     * Используется для предотвращения переполнения памяти.
     * Назначил: 50 MB
     */
    protected const MEMORY_SAFETY_BUFFER = 50 * 1024 * 1024;

    /**
     * Максимально допустимая доля использования доступной памяти (от 0 до 1).
     * При превышении этого значения генерируется предупреждение.
     * Назначил: 0.8 (80% от доступной памяти)
     */
    protected const MAX_MEMORY_USAGE = 0.8;

    /**
     * Имя csv файла для сохранения.
     */
    protected string $TARGET_FILENAME = 'common.csv';

    /**
     * Путь для хранения экспортированных файлов относительно корня storage.
     * Используем приватную директорию.
     */
    protected const STORAGE_PATH = 'private/';

    /**
     * Путь для сохранения скриншотов ошибок относительно корня storage.
     * Используется для диагностики проблем при работе с браузером.
     */
    protected const SCREENSHOT_PATH = 'logs/panther_screenshots/';

    /**
     * Путь для сохранения HTML-дампов страниц при ошибках относительно корня storage.
     * Используется для анализа структуры страниц при проблемах с парсингом.
     */
    protected const HTML_DUMP_PATH = 'logs/panther_html/';

    /**
     * Колличество в пакете
     */
    protected const BATCH_SIZE = 500;

    /**
     * Путь к временному файлу
     * @var string|bool
     */
    protected string|bool $tmpFilePath;

    protected bool $isTest;
    protected bool $timeout;

    public function __construct()
    {
        parent::__construct();

        //создаем директории для логов, если их нет
        if (!Storage::exists(self::SCREENSHOT_PATH)) {
            Storage::makeDirectory(self::SCREENSHOT_PATH);
        }
        if (!Storage::exists(self::HTML_DUMP_PATH)) {
            Storage::makeDirectory(self::HTML_DUMP_PATH);
        }

        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M'); //установка лимита памяти
        set_time_limit(0); //без ограничения времени выполнения
        DB::disableQueryLog(); //отключаем логирование запросов

        $this->isTest = env('APP_ENV') !== 'production';
    }

    /**
     * Инициализируем headless-браузер для работы с Panther.
     * Настраиваем параметры Chrome и случайный User-Agent.
     * @return Client
     */
    protected function initBrowser(): Client
    {
        $this->info("Инициализация headless браузера...");

        $options = [
            '--headless=new',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--window-size=1200,800',
            '--disable-gpu',
        ];

        if (!$this->isTest) {
            $profileDir = '/tmp/chrome-profile-'.md5(microtime());
            if (!file_exists($profileDir)) {
                mkdir($profileDir, 0755, true);
            }

            $options = array_merge($options, [
                '--user-data-dir='.$profileDir,
                '--remote-debugging-port=9222',
                '--disable-build-check'
            ]);
        } else {
            $userAgents = [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ];

            $options = array_merge($options, [
                '--user-agent='.$userAgents[array_rand($userAgents)]
            ]);
        }
        try {
            return Client::createChromeClient(null, $options);
        }  finally {
            if (!$this->isTest) {
                array_map('unlink', glob("$profileDir/*"));
                @rmdir($profileDir);
            }
        }
    }

    /**
     * Ожидаем появления ссылки для скачивания на странице скрипта.
     * Пробуем несколько селекторов и выполняем прокрутку.
     * Получем ссылку для получения контента файла
     * @param Client $client
     * @return string
     * @throws \Exception
     */
    protected function waitForDownloadLink(Client $client): string
    {
        $startTime = time();
        $selectors = [
            'a[download]',
            'a[href$=".csv"]',
        ];

        while (time() - $startTime < $this->timeout) {
            foreach ($selectors as $selector) {
                try {
                    $client->waitForVisibility($selector, 5);
                    $element = $client->getCrawler()->filter($selector)->first();

                    if ($element->count() > 0) {
                        $link = $element->attr('href') ?? $element->attr('data-url');

                        if (!empty($link)) {
                            $this->info("Найдена ссылка через селектор: $selector");
                            return $link;
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            //прокрутка и повторная попытка
            $client->executeScript('window.scrollBy(0, 200);');
            sleep(2);
        }

        throw new \Exception("Ни один из селекторов не сработал");
    }

    /**
     * Сохраняем отладочную информацию (скриншот, HTML)
     * @param Client $client
     * @param string $nameFile
     * @param bool $withHtml
     */
    protected function saveDebugInfo(Client $client, string $nameFile, bool $withHtml = true): void
    {
        try {
            $timestamp = time();

            //скриншот
            $screenshot = self::SCREENSHOT_PATH . "{$nameFile}_{$timestamp}.png";
            $client->takeScreenshot(storage_path('app/' . $screenshot));

            if ($withHtml) {
                //html
                $html = self::HTML_DUMP_PATH . "page_ru_{$timestamp}.html";
                Storage::put($html, $client->getCrawler()->html());
            }

            $this->error("Отладочная информация сохранена:");
            $this->error("- Скриншот: storage/app/{$screenshot}");

            if ($withHtml) {
                $this->error("- HTML: storage/app/{$html}");
            }

        } catch (\Exception $e) {
            $this->error("Не удалось сохранить отладочную информацию: " . $e->getMessage());
        }
    }

    /**
     * @param string $downloadUrl
     * @throws \Exception
     */
    protected function downloadCsv(string $downloadUrl): void
    {
        $this->info("Начало скачивания файла...");
        $this->logMemory('Перед скачиванием');

        if ($this->tmpFilePath === false) {
            throw new \Exception('Не удалось создать временный файл');
        }
        $chunkSize = $this->option('chunk') * 1024 * 1024;
        $source = fopen($downloadUrl, 'r');
        if (!$source) {
            throw new \Exception("Не удалось открыть поток для скачивания");
        }
        $dest = fopen($this->tmpFilePath, 'w');
        if (!$dest) {
            throw new \Exception("Не удалось создать временный файл");
        }
        $downloaded = 0;
        while (!feof($source)) {
            $this->checkMemoryUsage();
            $chunk = fread($source, $chunkSize);
            fwrite($dest, $chunk);
            $downloaded += strlen($chunk);
            if ($downloaded % (5 * 1024 * 1024) == 0) {
                $this->info("Скачано: " . $this->formatBytes($downloaded));
            }
        }
        $this->info("Всего скачано: " . $this->formatBytes($downloaded));
        fclose($source);
        fclose($dest);
    }

    /**
     * Парсит дату из строки в формате 'd.m.Y H:i:s'.
     * Если дата некорректна, возвращает значение по умолчанию ('1970-01-01 00:00:00').
     * Значение по умолчанию - заглушка, чтобы при пакетном сохранении общий уникальный индекс не менял значение (такое происходит, если будет присвоено null)
     * @param string|null $dateString
     * @return string
     */
    protected function parseDateTime(?string $dateString): string
    {
        $default = '1970-01-01 00:00:00';
        if (empty($dateString)) {
            return $default;
        }

        try {
            return Carbon::createFromFormat('d.m.Y H:i:s', $dateString)->toDateTimeString();
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * Проверяем использование памяти и выводим предупреждения.
     */
    protected function checkMemoryUsage(): void
    {
        $usedMemory = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimit();
        $safeLimit = $memoryLimit - self::MEMORY_SAFETY_BUFFER;
        $usagePercent = $usedMemory / $memoryLimit;

        if ($usedMemory > $safeLimit || $usagePercent > self::MAX_MEMORY_USAGE) {
            $message = sprintf(
                "Использование памяти: %s/%s (%.1f%%)",
                $this->formatBytes($usedMemory),
                $this->formatBytes($memoryLimit),
                $usagePercent * 100
            );
            Log::channel('commands')->warning(__CLASS__ . " Warning: " . $message);
            $this->warn($message);
        }
    }

    /**
     * Логируем текущее использование памяти.
     * @param string $message
     */
    protected function logMemory(string $message): void
    {
        $used = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = $this->getMemoryLimit();

        $this->line(sprintf(
            "[%s] Память: %s (пик: %s) из %s (%.1f%%)",
            $message,
            $this->formatBytes($used),
            $this->formatBytes($peak),
            $this->formatBytes($limit),
            ($used / $limit) * 100
        ), 'comment');
    }

    /**
     * Получаем лимит памяти в байтах.
     * @return int
     */
    protected function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if (preg_match('/^(\d+)(.)$/', $limit, $matches)) {
            if ($matches[2] == 'G') {
                return $matches[1] * 1024 * 1024 * 1024;
            } elseif ($matches[2] == 'M') {
                return $matches[1] * 1024 * 1024;
            } elseif ($matches[2] == 'K') {
                return $matches[1] * 1024;
            }
        }
        return (int)$limit;
    }

    /**
     * Форматируем размер в байтах в читаемый вид.
     * @param int $bytes Размер в байтах
     * @return string
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes >= 1024 && $i < 3; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Сохраняем временный файл в постоянное хранилище.
     */
    protected function saveToStorage(): void
    {
        $this->info("Сохранение файла в хранилище...");

        if (!Storage::exists(self::STORAGE_PATH)) {
            Storage::makeDirectory(self::STORAGE_PATH);
        }

        Storage::put(
            self::STORAGE_PATH . $this->TARGET_FILENAME,
            fopen($this->tmpFilePath, 'r')
        );
    }

    /**
     * Возвращаем полный путь к сохраненному файлу.
     * @return string
     */
    protected function getFullStoragePath(): string
    {
        return storage_path('app/' . self::STORAGE_PATH . $this->TARGET_FILENAME);
    }
}
