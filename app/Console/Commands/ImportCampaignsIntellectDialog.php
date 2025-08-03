<?php

namespace App\Console\Commands;

use App\Facades\IntellectDialog;
use App\Logging\CustomLog;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppParticipation;
use App\Traits\WriteLockTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as CommandAlias;

class ImportCampaignsIntellectDialog extends Command
{
    use WriteLockTrait;

    /**
     * @var string
     */
    protected $signature = 'import:id-campaigns
                            {--from= : Начальная дата в формате DD.MM.YYYY}';

    /**
     * Описание команды.
     * @var string
     */
    protected $description = 'Сбор детализированной статистики по WhatsApp рассылкам (IntellectDialog api)';

    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M'); //установка лимита памяти
        set_time_limit(0); //без ограничения времени выполнения
        DB::disableQueryLog(); //отключаем логирование запросов
    }

    /**
     * Лимит количества чатов за один запрос к API (пагинация)
     */
    private const LIMIT = 500;

    /**
     * @return int
     */
    public function handle(): int
    {
        $fromDate = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfDay()->format('Y-m-d H:i:s')
            : null;

        $this->info("Сбор статистики WhatsApp");

        try {
            $this->processAllCampaigns($fromDate);
            $this->info('Сбор статистики завершен успешно');
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error("Ошибка: " . $e->getMessage());
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Обрабатываем все кампании начиная с указанного периода
     * @param string|null $fromDate
     * @throws \Exception
     */
    private function processAllCampaigns(?string $fromDate): void
    {
        $offset = 0;
        $totalMessagesProcessed = 0; //общий счетчик обработанных сообщений

        /** @var WhatsAppParticipation $lastMessage */
        $lastMessage = WhatsAppParticipation::query()
            ->latest('send_date')
            ->select('send_date')
            ->first();

        $createdAfter = !empty($lastMessage)
            ? Carbon::parse($lastMessage->send_date)->format('Y-m-d H:i:s')
            : $fromDate;

        do {
            $messages = IntellectDialog::messages([
                'limit' => self::LIMIT,
                'offset' => $offset,
                'created_after' => $createdAfter,
            ]);

            $fetchedCount = count($messages['data'] ?? []);
            if ($fetchedCount === 0) {
                break;
            }

            $campaignsBatch = [];
            $contactsBatch = [];
            $participationBatch = [];

            //подсчитываем количество актуальных сообщений (типа "Исходящее")
            $validMessagesCount = count(array_filter($messages['data'], function ($message) {
                return $message['type_name'] === "Исходящее";
            }));

            //создаем прогресс-бар с правильным максимальным значением
            $progressBar = $this->output->createProgressBar($validMessagesCount);
            $progressBar->start();

            try {
                foreach ($messages['data'] as $message) {
                    if ($message['type_name'] !== "Исходящее") {
                        continue;
                    }

                    //обработка кампаний
                    $this->processCampaignBatch($message, $campaignsBatch);

                    //обработка контактов
                    $this->processContactsBatch($message, $contactsBatch);

                    //обработка участий
                    $this->processParticipationBatch($message, $participationBatch, $campaignsBatch);

                    //увеличиваем счетчики
                    $totalMessagesProcessed++;
                    $progressBar->advance(); //обновляем прогресс-бар
                }

                //пакетное сохранение данных
                $this->saveBatches($contactsBatch, $participationBatch);

            } finally {
                //завершаем прогресс-бар
                $progressBar->finish();
                $this->newLine();
            }

            $offset += self::LIMIT;
            unset($messages);
            gc_mem_caches(); //очищаем кэши памяти Zend Engine

        } while ($fetchedCount === self::LIMIT);

        $this->info("Всего обработано сообщений: {$totalMessagesProcessed}");
    }

    /**
     * Обрабатываем кампании, если нет создаём... формируем пакет кампаний
     * @param array $message
     * @param array $campaignsBatch
     */
    private function processCampaignBatch(array $message, array &$campaignsBatch): void
    {
        $text = $message['text'];
        if (!in_array($text, array_column($campaignsBatch, 'campaign_name'), true)) {
            /** @var WhatsAppCampaign $campaign */
            $campaign = WhatsAppCampaign::query()
                ->where('campaign_name', $text)
                ->first();

            if (empty($campaign)) {
                $campaign = $this->withTableLock('whatsapp_campaign', function () use ($message) {
                    return WhatsAppCampaign::create([
                        'campaign_name' => $message['text'],
                        'send_date' => Carbon::parse($message['created_at']),
                    ]);
                });
            }

            $campaignsBatch[] = [
                'id' => $campaign->id,
                'campaign_name' => $campaign->campaign_name,
            ];
        }
    }

    /**
     * Формируем пакет котактов
     * @param array $message
     * @param array $contactsBatch
     */
    private function processContactsBatch(array $message, array &$contactsBatch): void
    {
        $phone = $message['person_phone'];

        if (!in_array($phone, array_column($contactsBatch, 'phone'), true)) {
            $contactsBatch[] = [
                'phone' => $phone,
            ];
        }
    }

    /**
     * Формируем пакет участий
     * @param array $message
     * @param array $participationBatch
     * @param array $campaignsBatch
     */
    private function processParticipationBatch(array $message, array &$participationBatch, array $campaignsBatch): void
    {
        $campaignId = $this->findSomeKeyByCampaignName($campaignsBatch, $message['text']);
        $participationBatch[] = [
            'campaign_id' => $campaignId,
            'phone' => $message['person_phone'],
            'send_date' => $message['created_at'],
        ];
    }

    /**
     * Ищем id кампании в пакете кампаний
     * @param array $campaignsBatch
     * @param string $message
     * @return string|null
     */
    private function findSomeKeyByCampaignName(array $campaignsBatch, string $message): ?string
    {
        $index = array_search($message, array_column($campaignsBatch, 'campaign_name'));

        if ($index !== false) {
            return $campaignsBatch[$index]['id'] ?? null;
        }

        return null;
    }

    /**
     * Сохраняем в БД пакетами
     * @param array $contactsBatch
     * @param array $participationBatch
     * @throws \Exception
     */
    private function saveBatches(array $contactsBatch, array $participationBatch): void
    {
        DB::beginTransaction();
        try {
            if (!empty($contactsBatch)) {
                $this->withTableLock('whatsapp_contacts', function () use ($contactsBatch) {
                    WhatsAppContact::upsertWithMutators(
                        $contactsBatch,
                        ['phone'] //phone является уникальным ключом
                    );
                });
            }

            if (!empty($participationBatch)) {
                $this->withTableLock('whatsapp_participation', function () use ($participationBatch) {
                    WhatsAppParticipation::insertOrIgnoreWithMutators($participationBatch);
                });
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Ошибка при сохранении данных: " . $e->getMessage());
            throw $e;
        }
    }
}
