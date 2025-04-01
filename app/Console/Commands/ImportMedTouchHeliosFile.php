<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Panther\Client;
use Throwable;

//todo: Перед использованием, установки на сервере для Panther
class ImportMedTouchHeliosFile extends Command
{
    /**
     * Пример: php artisan import:medtouch-helios --chunk=5 --timeout=60
     * @var string
     */
    protected $signature = 'import:medtouch-helios
                            {--chunk=5 : Размер чанка скачивания в MB}
                            {--timeout=120 : Таймаут ожидания в секундах}';

    /**
     * Описание команды.
     * @var string
     */
    protected $description = 'Скачиваем большой файл CSV (Мед-тач) из Bitrix24 в приватное хранилище';

    /**
     * Безопасный буфер памяти (в байтах), оставляемый свободным при работе с большими файлами.
     * Используется для предотвращения переполнения памяти.
     * Назначил: 50 MB
     */
    private const MEMORY_SAFETY_BUFFER = 50 * 1024 * 1024;

    /**
     * Максимально допустимая доля использования доступной памяти (от 0 до 1).
     * При превышении этого значения генерируется предупреждение.
     * Назначил: 0.8 (80% от доступной памяти)
     */
    private const MAX_MEMORY_USAGE = 0.8;

    /**
     * Имя csv файла для сохранения экспортированных данных.
     */
    private const TARGET_FILENAME = 'user-helios.csv';

    /**
     * Путь для хранения экспортированных файлов относительно корня storage.
     * Используем приватную директорию.
     */
    private const STORAGE_PATH = 'private/';

    /**
     * Путь для сохранения скриншотов ошибок относительно корня storage.
     * Используется для диагностики проблем при работе с браузером.
     */
    private const SCREENSHOT_PATH = 'logs/panther_screenshots/';

    /**
     * Путь для сохранения HTML-дампов страниц при ошибках относительно корня storage.
     * Используется для анализа структуры страниц при проблемах с парсингом.
     */
    private const HTML_DUMP_PATH = 'logs/panther_html/';

    /**
     * Путь к временному файлу
     * @var string|bool
     */
    private string|bool $tmpFilePath;


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
    }

    /**
     * Организуем процесс скачивания файла с страницы Медтач гелиос.
     * @return int
     */
    public function handle(): int
    {
        try {
            $this->logMemory('Начало выполнения');
            $fileUrl = $this->getTargetUrl();
            $this->info("Используется URL: " . $fileUrl);

            $client = $this->initBrowser();
            $downloadUrl = $this->extractDownloadUrl($client, $fileUrl);

            $this->info("URL для скачивания: " . $downloadUrl);
            $this->downloadAndSave($downloadUrl);

            $this->info("Файл успешно сохранён: " . $this->getFullStoragePath());
            $this->logMemory('Завершение выполнения');

            return CommandAlias::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('commands')->error(__CLASS__ . " Error: " . $e->getMessage());
            $this->error('Ошибка выполнения, смотрите логи');
            return CommandAlias::FAILURE;
        } finally {
            //убедимся, что временный файл удален даже в случае ошибки
            if (isset($this->tmpFilePath) && file_exists($this->tmpFilePath)) {
                unlink($this->tmpFilePath);
            }
        }
    }

    /**
     * Инициализируем headless-браузер для работы с Panther.
     * Настраиваем параметры Chrome и случайный User-Agent.
     * @return Client
     */
    private function initBrowser(): Client
    {
        $this->info("Инициализация headless браузера...");

        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ];

        return Client::createChromeClient(null, [
                '--headless=new',
                '--window-size=1200,800',
                '--disable-gpu',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--user-agent='.$userAgents[array_rand($userAgents)]
            ]
        );
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
            $client->waitFor('body', $this->option('timeout'));

            //проверяем на ошики
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
            $this->saveDebugInfo($client);
            throw new \Exception("Не удалось найти ссылку для скачивания: " . $e->getMessage());
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
    private function waitForDownloadLink(Client $client): string
    {
        $startTime = time();
        $timeout = $this->option('timeout');
        $selectors = [
            'a[download]',
            'a[href$=".csv"]',
        ];

        while (time() - $startTime < $timeout) {
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
     * Сохраняем отладочную информацию (скриншот, HTML) при ошибках.
     * @param Client $client
     */
    private function saveDebugInfo(Client $client): void
    {
        try {
            $timestamp = time();

            //скриншот
            $screenshot = self::SCREENSHOT_PATH . "error_{$timestamp}.png";
            $client->takeScreenshot(storage_path('app/' . $screenshot));

            //html
            $html = self::HTML_DUMP_PATH . "page_{$timestamp}.html";
            Storage::put($html, $client->getCrawler()->html());

            $this->error("Отладочная информация сохранена:");
            $this->error("- Скриншот: storage/app/{$screenshot}");
            $this->error("- HTML: storage/app/{$html}");

        } catch (\Exception $e) {
            $this->error("Не удалось сохранить отладочную информацию: " . $e->getMessage());
        }
    }

    /**
     * Скачиваем файл по частям и сохраняем во временное хранилище.
     * @param string $downloadUrl URL для скачивания файла
     * @throws \Exception
     */
    private function downloadAndSave(string $downloadUrl): void
    {
        $this->info("Начало скачивания файла...");
        $this->logMemory('Перед скачиванием');

        //создаем временный файл для потоковой записи
        $this->tmpFilePath = tempnam(sys_get_temp_dir(), 'medtouth_temp_');
        if ($this->tmpFilePath === false) {
            throw new \Exception('Не удалось создать временный файл');
        }

        $chunkSize = $this->option('chunk') * 1024 * 1024;

        try {
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

            $this->saveToStorage($this->tmpFilePath);

        } finally {
            if (file_exists($this->tmpFilePath)) {
                unlink($this->tmpFilePath);
            }
        }
    }

    /**
     * Сохраняем временный файл в постоянное хранилище.
     * @param string $tempFilePath Путь к временному файлу
     */
    private function saveToStorage(string $tempFilePath): void
    {
        $this->info("Сохранение файла в хранилище...");

        if (!Storage::exists(self::STORAGE_PATH)) {
            Storage::makeDirectory(self::STORAGE_PATH);
        }

        Storage::put(
            self::STORAGE_PATH . self::TARGET_FILENAME,
            fopen($tempFilePath, 'r')
        );
    }

    /**
     * Проверяем использование памяти и выводим предупреждения.
     */
    private function checkMemoryUsage(): void
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
    private function logMemory(string $message): void
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
     * Возвращаем полный путь к сохраненному файлу.
     * @return string
     */
    private function getFullStoragePath(): string
    {
        return storage_path('app/' . self::STORAGE_PATH . self::TARGET_FILENAME);
    }

    /**
     * Получаем лимит памяти в байтах.
     * @return int
     */
    private function getMemoryLimit(): int
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
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes >= 1024 && $i < 3; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
