<?php

namespace App\Console\Commands\ImportBitrixMt;

use App\Logging\CustomLog;
use App\Models\CommonDatabase;
use App\Models\UserMT;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Panther\Client;

//todo: Перед использованием, установки на сервере для Panther
class ImportMTRegisteredUsersFile extends Common
{
    /**
     * Пример: php artisan import:medtouch-helios --chunk=5 --timeout=60
     * @var string
     */
    protected $signature = 'import:medtouch-reg-users
                            {--chunk=5 : Размер чанка скачивания в MB}
                            {--timeout=120 : Таймаут ожидания в секундах}
                            {--need-file=false : Сохранять ли CSV файл}';

    /**
     * Описание команды.
     * @var string
     */
    protected $description = 'Скачиваем большой файл CSV (Мед-тач) из Bitrix24 в приватное хранилище, пользователей и импортируем в БД';

    /**
     * Имя csv файла для сохранения.
     */
    protected string $TARGET_FILENAME = 'registered-users.csv';


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

            $this->tmpFilePath = tempnam(sys_get_temp_dir(), 'medtouth_registered_users_');
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
        $url = env('BITRIX_MEDTOUCH_SCRIPT_URL_2');
        if (empty($url)) {
            throw new \Exception("BITRIX_MEDTOUCH_SCRIPT_URL_2 не указан в .env");
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

        $this->authenticateInBitrix($client);

        $this->info("Переходим на страницу скрипта...");
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
            $this->saveDebugInfo($client, 'error_ru');
            throw new \Exception("Не удалось найти ссылку для скачивания: " . $e->getMessage());
        }
    }

    /**
     * Аутентификация в Битрикс24 с обработкой всех модальных окон
     * @param Client $client
     * @throws \Exception
     */
    private function authenticateInBitrix(Client $client): void
    {
        $login = env('BITRIX_ADMIN_LOGIN');
        $password = env('BITRIX_ADMIN_PASSWORD');

        if (empty($login) || empty($password)) {
            throw new \Exception("Не указаны учетные данные в .env");
        }

        $this->info("Выполняем аутентификацию в Битрикс24...");

        //получаем URL для входа
        $loginUrl = parse_url(env('BITRIX_MEDTOUCH_SCRIPT_URL_2'), PHP_URL_SCHEME) . '://' .
            parse_url(env('BITRIX_MEDTOUCH_SCRIPT_URL_2'), PHP_URL_HOST) . '/auth/';

        $client->request('GET', $loginUrl);

        try {
            //обработка модального окна cookie
            $this->handleCookieBanner($client);

            //обработка второго модального окна
            $this->handleModalWindow($client);

            //заполнение формы аутентификации
            $this->fillAuthForm($client, $login, $password);

            //ожидание завершения аутентификации
            $this->waitForAuthCompletion($client);

            $this->info("Аутентификация успешно выполнена");

        } catch (\Exception $e) {
            $this->saveDebugInfo($client, 'error_ru');
            throw new \Exception("Ошибка аутентификации: " . $e->getMessage());
        }
    }

    /**
     * Обработка модального окна cookie
     * @param Client $client
     */
    private function handleCookieBanner(Client $client): void
    {
        try {
            $client->waitFor('#cookie-bg', 5);
            $this->info("Обнаружен cookie-баннер, принимаем соглашение...");

            //ищем кнопку "I agree"
            $agreeButton = $client->getCrawler()->filter('.cookie-accept, .btn-cookie-agree, .cookie-agree')->first();

            if ($agreeButton->count() > 0) {
                if ($this->isTest) {
                    $this->saveDebugInfo($client, 'cookie-banner', false);
                }
                $agreeButton->click();
                $client->waitForInvisibility('#cookie-bg', 5);
                $this->info("Cookie-соглашение принято");
            } else {
                $this->info("Кнопка согласия с cookies не найдена, продолжаем...");
            }
        } catch (\Exception $e) {
            $this->info("Cookie-баннер не появился: " . $e->getMessage());
        }
    }

    /**
     * Обработка второго модального окна
     * @param Client $client
     */
    private function handleModalWindow(Client $client): void
    {
        try {
            $client->waitFor('.modal-close', 5);
            if ($this->isTest) {
                $this->saveDebugInfo($client, 'modal-2', false);
            }
            $this->info("Обнаружено модальное окно, закрываем...");

            $client->getCrawler()->filter('.modal-close')->first()->click();
            $client->waitForInvisibility('.modal-close', 5);
            $this->info("Модальное окно закрыто");
        } catch (\Exception $e) {
            $this->info("Модальное окно не появилось: " . $e->getMessage());
        }
    }

    /**
     * Заполнение формы аутентификации
     * @param Client $client
     * @param string $login
     * @param string $password
     * @throws \Facebook\WebDriver\Exception\NoSuchElementException
     * @throws \Facebook\WebDriver\Exception\TimeoutException
     * @throws \Exception
     */
    private function fillAuthForm(Client $client, string $login, string $password): void
    {
        $client->waitFor('.modal_auth.no-popup.step-1', $this->timeout);

        //чистим форму
        $client->executeScript("
        document.querySelector('input[name=\"login\"]').value = '';
        document.querySelector('input[name=\"password\"]').value = '';
    ");
        $loginInput = $client->getCrawler()->filter('input[name="login"]')->first();
        $passwordInput = $client->getCrawler()->filter('input[name="password"]')->first();

        //очищаем поле логина
        $loginInput->sendKeys(str_repeat(\Facebook\WebDriver\WebDriverKeys::BACKSPACE, 100));
        //очищаем поле пароля
        $passwordInput->sendKeys(str_repeat(\Facebook\WebDriver\WebDriverKeys::BACKSPACE, 100));

        //заполняем поля с небольшими задержками
        $loginInput->sendKeys($login);
        usleep(200000); //задержка
        $passwordInput->sendKeys($password);
        usleep(200000); //задержка

        if ($this->isTest) {
            $this->saveDebugInfo($client, 'fill-auth-form', false);
        }

        //нажимаем кнопку входа
        $submitButton = $client->getCrawler()->filter('button.login_btn')->first();
        if ($submitButton->count() > 0) {
            $submitButton->click();
        } else {
            throw new \Exception("Не найдена кнопка входа");
        }
    }

    /**
     * Ожидание завершения аутентификации
     * @param Client $client
     * @throws \Facebook\WebDriver\Exception\NoSuchElementException
     * @throws \Facebook\WebDriver\Exception\TimeoutException
     */
    private function waitForAuthCompletion(Client $client): void
    {
        //ждем появления лоадера
        $client->waitFor('.auth-loading', $this->timeout);
        if ($this->isTest) {
            $this->saveDebugInfo($client, 'auth-loader', false);
        }
        $this->info("Лоадер аутентификации появился, ждем {$this->timeout} секунд...");

        sleep($this->timeout);
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

        $commonDBBatch = [];
        $EMAILS = [];
        $OLD_MT_IDS = [];

        try {
            while (($row = fgetcsv($handle, 0, ";")) !== FALSE) {
                if ($row[0] === "ID") {
                    continue;
                }

                if (empty($email = $row[7]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Log::channel('commands')->warning("Пропущена некорректная строка с невалидным email: {$email}");
                    continue;
                }

                if (in_array($email, $EMAILS) || in_array($oldMtId = $row[0], $OLD_MT_IDS)) {
                    Log::info("Дубликат - $email");
                    continue;
                }

                $EMAILS[] = $email;
                $OLD_MT_IDS[] = $oldMtId;

                list($userMTData, $commonDbData) = $this->prepareUsersMtAndCommonDb($row);

                $userMt = $this->withTableLock('users_mt', function () use ($userMTData) {
                    return UserMT::updateOrCreateWithMutators(
                        ['email' => $userMTData['email']],
                        [
                            'old_mt_id' => $userMTData['old_mt_id'], //если не заполнено
                            'full_name' => $userMTData['full_name'], //если >
                            'gender' => $userMTData['gender'], //если не заполнено и общий формат
                            'birth_date' => $userMTData['birth_date'], //если не заполнено
                            'specialty' => $userMTData['specialty'], //если не заполнено
                            'phone' => $userMTData['phone'], //если не заполнено
                            'place_of_employment' => $userMTData['place_of_employment'], //если не заполнено
                            'registration_date' => $userMTData['registration_date'], //берём со старого сайта
                            'country' => $userMTData['country'], //если >
                            'region' => $userMTData['region'], //если >
                            'city' => $userMTData['city'], //если >
                            'registration_website' => $userMTData['registration_website'], //если не заполнено
                            'uf_utm_term' => $userMTData['uf_utm_term'], //если не заполнено
                            'uf_utm_campaign' => $userMTData['uf_utm_campaign'], //если не заполнено
                            'uf_utm_content' => $userMTData['uf_utm_content'], //если не заполнено
                        ]
                    );
                });

                if (!$userMt) {
                    $this->error("Ошибка создания/обновления пользователя: {$userMTData['email']}");
                    Log::error("Ошибка создания/обновления пользователя", $userMTData);
                    continue;
                }

                $commonDbData['mt_user_id'] = $userMt->id;

                $commonDBBatch[] = $commonDbData;

                if (count($commonDBBatch) >= self::BATCH_SIZE) {
                    $this->upsertBatchCommonDb($commonDBBatch, $EMAILS, $OLD_MT_IDS);
                }

            }
            //вставляем оставшиеся записи
            if (!empty($commonDBBatch)) {
                $this->upsertBatchCommonDb($commonDBBatch, $EMAILS, $OLD_MT_IDS);
            }
        } finally {
            fclose($handle);
        }

        $this->info("Обработка CSV завершена.");
    }

    /**
     * Подготоваливаем данные
     * @param array $row
     * @return array[]
     */
    private function prepareUsersMtAndCommonDb(array $row): array
    {
        list(
            $old_mt_id,                 //ID
            $last_name,                 //Фамилия
            $first_name,                //Имя
            $middle_name,               //Отчество
            $date_registration,         //Дата регистрации
            $gender,                    //Пол
            $birth_date,                //Дата рождения
            $email,                     //EMAIL
            $phone,                     //Телефон
            $country,                   //Страна
            $city_work,                 //Город работы
            $city_live,                 //Город проживания
            $region,                    //Регион
            $medical_direction,         //Мед.направление
            $profession,                //Профессия
            $place_of_employment,       //Компания
            $department,                //Отдел
            $job_position,              //Должность
            $site_reg,                  //Сайт регистрации
            $UF_UTM_SOURCE,             //UF_UTM_SOURCE
            $UF_UTM_MEDIUM,             //UF_UTM_MEDIUM
            $UF_UTM_TERM,               //UF_UTM_TERM
            $UF_UTM_CAMPAIGN,           //UF_UTM_CAMPAIGN
            $UF_UTM_CONTENT,            //UF_UTM_CONTENT
            ) = $row;

        $fullName = trim(implode(' ', array_filter([
            $last_name ?? null,
            $first_name ?? null,
            $middle_name ?? null
        ])));

        if ($birth_date === "00.00.0000") {
            $birth_date = null;
        }

        if ($date_registration === "00.00.0000 00:00:00") {
            $date_registration = null;
        }

        if (!empty($old_mt_id)) {
            $userMt = UserMT::query()->where('email', $email)->first();
            /** @var UserMT $userMt */
            if ($userMt && $userMt->old_mt_id && $userMt->old_mt_id != $old_mt_id) {
                $old_mt_id = $userMt->old_mt_id;
            }
        }

        return [
            //данные users_mt
            [
                'old_mt_id' => $old_mt_id,
                'full_name' => $fullName,
                'email' => $email,
                'gender' => $gender,
                'birth_date' => $birth_date ? Carbon::parse($birth_date)->format('Y-m-d') : null,
                'specialty' => $medical_direction,
                'phone' => $phone,
                'place_of_employment' => $place_of_employment,
                'registration_date' => $date_registration ? Carbon::parse($date_registration)->format('Y-m-d') : null,
                'country' => $country,
                'region' => $region,
                'city' => $city_work ?? $city_live,
                'registration_website' => $site_reg,
                'uf_utm_term' => $UF_UTM_TERM,
                'uf_utm_campaign' => $UF_UTM_CAMPAIGN,
                'uf_utm_content' => $UF_UTM_CONTENT,
            ],
            //данные common_database
            [
                'old_mt_id' => $old_mt_id,
                'email' => $email,
                'full_name' => $fullName,
                'city' => $city_work ?? $city_live,
                'region' => $region,
                'country' => $country,
                'specialty' => $medical_direction,
                'phone' => $phone,
                'registration_date' => $date_registration ? Carbon::parse($date_registration)->format('Y-m-d H:i:s') : null,
                'gender' => $gender,
                'birth_date' => $birth_date  ? Carbon::parse($birth_date)->format('Y-m-d H:i:s') : null,
                'registration_website' => $site_reg,
            ]
        ];
    }

    private function upsertBatchCommonDb(array &$commonDBBatch, array &$EMAILS, array &$OLD_MT_IDS)
    {
        $this->info("Пакетная вставка - " . count($commonDBBatch));
        $this->withTableLock('common_database', function () use ($commonDBBatch) {
            CommonDatabase::upsertWithMutators(
                $commonDBBatch,
                ['email'],
                [
                    'old_mt_id', //если не заполнено
                    'full_name', //если >
                    'city', //если >
                    'region', //если >
                    'country', //если >
                    'specialty', //если не заполнено
                    'phone', //если не заполнено
                    'mt_user_id', //если не заполнено
                    'registration_date',
                    'gender', //если не заполнено, общий формат
                    'birth_date', //если не заполнено
                    'registration_website' //если не заполнено
                ]
            );
        });

        $commonDBBatch = [];
        $EMAILS = [];
        $OLD_MT_IDS = [];
        gc_mem_caches(); //очищаем кэши памяти Zend Engine
    }
}
