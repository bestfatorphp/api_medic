<?php

namespace App\Console\Commands\ImportCampaigns;

use App\Facades\UniSender;
use App\Models\CommonDatabase;
use App\Models\UnisenderCampaign;
use App\Models\UnisenderContact;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\NoReturn;

class Common extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:campaigns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Общий класс для команд импорта кампаний';

    /**
     * Статистика кампании не была собрана основной командой по какой-то причине.
     * @var bool
     */
    protected bool $isBad = false;

    /**
     * Путь к временному файлу
     * @var string|bool
     */
    protected string|bool $tmpFilePath;

    /**
     * Размер партии для пакетной вставки данных в базу.
     */
    private const BATCH_SIZE = 500;

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();

        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M'); //установка лимита памяти
        set_time_limit(0); //без ограничения времени выполнения
        DB::disableQueryLog(); //отключаем логирование запросов
    }

    /**
     * @param array $campaign
     * @throws \Exception
     */
    #[NoReturn] protected function processCompletedCampaign(array $campaign): void
    {
        $campaignId = (int)$campaign['id'];

        if (!$this->isBad && $campaign['status'] !== "completed") {
            $this->info("Кампания #{$campaignId} не завершена. Пропускаем.");
            return;
        }

        $this->info("Обработка кампании #{$campaignId}");

        $campaignDB = UnisenderCampaign::find($campaignId);

        if (!$this->isBad) {

            //пропускаем обработанные completed кампании
            if ($campaignDB && $campaignDB->statistics_received) {
                return;
            }

            if (!$campaignDB) {
                //получаем общую статистику кампании
                $campaignCommonStats = UniSender::getCampaignCommonStats($campaignId);

                $campaignCommonStats = $campaignCommonStats['result'];

                $campaignDB = UnisenderCampaign::create([
                    'id' => $campaignId,
                    'campaign_name' => $campaign['subject'] ?? 'Без названия',
                    'send_date' => Carbon::parse($campaign['start_time'])->format('Y-m-d H:i:s'),
                    'open_rate' => $this->calculateRate($campaignCommonStats['delivered'], $campaignCommonStats['read_all']),
                    'ctr' => $this->calculateRate($campaignCommonStats['clicked_all'], $campaignCommonStats['delivered']), //число переходов по ссылкам писем/число доставленных
                    'sent' => $campaignCommonStats['sent'],
                    'delivered' => $campaignCommonStats['delivered'],
                    'delivery_rate' => $this->calculateRate($campaignCommonStats['total'], $campaignCommonStats['delivered']),
                    'opened' => $campaignCommonStats['read_all'],
                    'open_per_unique' => $campaignCommonStats['read_unique'],
                    'clicked' => $campaignCommonStats['clicked_all'],
                    'clicks_per_unique' => $campaignCommonStats['clicked_unique'],
                    'ctor' => $this->calculateRate($campaignCommonStats['clicked_all'], $campaignCommonStats['read_all']), //число переходов по ссылкам из писем/число открытых
                ]);
            }
        }

        //запускаем экспорт данных о доставке
        $exportTask = UniSender::getCampaignDeliveryStats($campaign['id']);
        if (!isset($exportTask['result']['task_uuid'])) {
            $this->error("Не удалось запустить экспорт данных доставки для кампании #{$campaign['id']}");
            throw new \Exception("Не удалось запустить экспорт данных доставки");
        }

        $taskUuid = $exportTask['result']['task_uuid'];
        $this->info("Задача экспорта данных доставки запущена. Task UUID: {$taskUuid}");

        //ожидаем завершения задачи
        $fileUrl = $this->waitForExportCompletion($taskUuid);
        if (!$fileUrl) {
            $this->error("Не удалось получить данные доставки для кампании #{$campaign['id']}");
            throw new \Exception("Не удалось получить данные доставки");
        }

        $this->info("Экспорт данных доставки завершен. Ссылка на файл: {$fileUrl}");

        //обрабатываем CSV-файл
        try {
            DB::transaction(function () use ($fileUrl, $campaignDB) {
                $this->processCsvFile($fileUrl, $campaignDB->id);
                $campaignDB->update(['statistics_received' => true]);
            });
        } catch (\Exception $e) {
            $this->error("Ошибка при обработке CSV-файла для кампании #{$campaign['id']}");
            Log::channel('commands')->error(__CLASS__ . " Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Ожидает завершения задачи и возвращает ссылку на CSV-файл.
     * @param string $taskUuid UUID задачи.
     * @return string|null Ссылка на CSV-файл или null в случае ошибки.
     */
    private function waitForExportCompletion(string $taskUuid): ?string
    {
        $maxAttempts = 20; //максимальное количество попыток
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            sleep(5); //ждем 5 секунд перед проверкой статуса

            $taskStatus = UniSender::getTaskResult($taskUuid);
            if (!isset($taskStatus['result']['status'])) {
                $this->error("Ошибка при проверке статуса задачи (Task UUID: {$taskUuid})");
                return null;
            }
            if (isset($taskStatus['result']['task_type']) && $taskStatus['result']['task_type'] !== 'campaign_delivery_stats') {
                $this->error("Задача не относится к получению статистики по кампании (Task UUID: {$taskUuid})");
                continue;
            }

            $status = $taskStatus['result']['status'];
            $this->info("Статус задачи экспорта: {$status}");

            if ($status === 'completed') {
                return $taskStatus['result']['file_to_download'] ?? null;
            } elseif ($status === 'failed') {
                $this->error("Задача экспорта завершилась с ошибкой (Task UUID: {$taskUuid})");
                return null;
            }

            $attempts++;
        }

        $this->error("Превышено время ожидания завершения задачи (Task UUID: {$taskUuid})");
        return null;
    }

    /**
     * Обрабатываем CSV-файл с данными о доставке.
     * @param string $fileUrl Ссылка на CSV-файл.
     * @param int $campaignId
     * @throws \Exception
     */
    private function processCsvFile(string $fileUrl, int $campaignId): void
    {
        $this->tmpFilePath = tempnam(sys_get_temp_dir(), $this->tmpFilePath);
        file_put_contents($this->tmpFilePath, fopen($fileUrl, 'r'));

        $handle = fopen($this->tmpFilePath, 'r');
        if (!$handle) {
            $this->error('Не удалось открыть CSV-файл.');
            throw new \Exception("Не удалось открыть CSV-файл");
        }

        //пропускаем заголовки
        fgetcsv($handle);

        $emails = [];
        $batchParticipations = [];

        while (($row = fgetcsv($handle)) !== false) {
            list($email, $status, $updateTime) = $row;

            //подготавливаем данные для таблицы unisender_participation
            $batchParticipations[] = [
                'campaign_id' => $campaignId,
                'email' => $email,
                'result' => $status,
                'update_time' => Carbon::parse($updateTime)->format('Y-m-d H:i:s'),
            ];

            //если набралась партия, сохраняем данные
            if (count($batchParticipations) >= self::BATCH_SIZE) {
                DB::table('unisender_participation')->insertOrIgnore($batchParticipations);
                $batchParticipations = [];
            }

            //добавляем email в массив для дальнейшей обработки
            if (!in_array($email, $emails)) {
                $emails[] = $email;
            }
        }

        fclose($handle);
        unlink($this->tmpFilePath);

        //сохраняем оставшиеся данные
        if (!empty($batchParticipations)) {
            DB::table('unisender_participation')->insertOrIgnore($batchParticipations);
        }
        $this->info("Записи добавлены в таблицу unisender_participation.");

        //обрабатываем контакты
        $this->fetchContactsInBatches($emails);
    }

    /**
     * Обраатываем контакты
     * @throws \Exception
     */
    private function fetchContactsInBatches(array $emails): void
    {
        $batchSize = 50; //размер партии для обработки
        $totalEmails = count($emails);
        $batchContacts = [];
        $batchCommonDB = [];

        //создаем прогресс-бар
        $progressBar = $this->output->createProgressBar($totalEmails);
        $progressBar->start();

        foreach (array_chunk($emails, $batchSize) as $index => $emailBatch) {
            foreach ($emailBatch as $email) {
                try {
                    $contact = UniSender::getContact($email, [
                        'include_lists' => false, //убираем списки
                        'include_fields' => true,
                        'include_details' => true,
                    ]);

                    $contact = $contact['result'];

                    //формируем данные для таблицы unisender_contacts
                    $batchContacts[] = [
                        'email' => $email,
                        'contact_status' => $contact['email']['rating'],
                        'email_status' => $contact['email']['status'],
                        'email_availability' => $contact['email']['availability'] === "available",
                    ];

                    //формируем данные для таблицы common_database
                    $batchCommonDB[] = [
                        'email' => $email,
                        'full_name' => $contact['fields']['ФИО'] ?? null,
                        'city' => $contact['fields']['Город'] ?? null,
                        'specialty' => $contact['fields']['специальность'] ?? null,
                        'phone' => $contact['phone']['phone'] ?? null,
                        'registration_date' => null,
                        'email_status' => $contact['email']['status'],
                    ];
                } catch (\Exception $e) {
                    $this->error("Ошибка при получении данных для email {$email}");
                    Log::channel('commands')->error(__CLASS__ . " Error: " . $e->getMessage());
                    continue; //пропускаем email, если произошла ошибка
                }

                //обновляем прогресс-бар
                $progressBar->advance();
            }

            //массовая вставка только новых (если нужно можно добавить поля для обновления в существующих)
            if (!empty($batchContacts)) {
                UnisenderContact::upsert($batchContacts, ['email']);
                $batchContacts = [];
            }
            if (!empty($batchCommonDB)) {
                CommonDatabase::upsert($batchCommonDB, ['email']);
                $batchCommonDB = [];
            }
        }

        //завершаем прогресс-бар
        $progressBar->finish();
        $this->info("\n");
    }

    /**
     * Рассчитывает процент доставленных писем
     * @param int $total Общее количество
     * @param int $countSuccess Количество успешных
     *
     * @return float Процент доставки (0-100)
     */
    private function calculateRate(int $total, int $countSuccess): float
    {
        if ($total <= 0) {
            return 0.0;
        }
        return round(($countSuccess / $total) * 100, 2);
    }
}
