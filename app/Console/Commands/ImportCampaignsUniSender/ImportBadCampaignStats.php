<?php

namespace App\Console\Commands\ImportCampaignsUniSender;

use App\Models\UnisenderCampaign;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\NoReturn;
use Symfony\Component\Console\Command\Command as CommandAlias;

class ImportBadCampaignStats extends Common
{
    /**
     * @var string
     */
    protected $signature = 'import:bad-campaigns-stats';

    /**
     * @var string
     */
    protected $description = 'Сбор детализированной статистики по email-рассылкам, которые не смогли собрать основной командой';

    /**
     * Статистика кампании не была собрана основной командой по какой-то причине.
     * @var bool
     */
    protected bool $isBad = true;

    /**
     * Путь к временному файлу
     * @var string|bool
     */
    protected string|bool $tmpFilePath = 'unisender_bad_stats_temp_';

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @return int
     */
    public function handle(): int
    {
        try {
            $this->processAllCampaigns();
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            Log::channel('commands')->error(__CLASS__ . " Error: " . $e->getMessage());
            $this->error("Ошибка: " . $e->getMessage());
            return CommandAlias::FAILURE;
        } finally {
            //убедимся, что временный файл удален даже в случае ошибки
            if (isset($this->tmpFilePath) && file_exists($this->tmpFilePath)) {
                unlink($this->tmpFilePath);
            }
        }
    }

    /**
     * Обрабатываем все кампании за указанный период
     * @throws \Exception
     */
    #[NoReturn] protected function processAllCampaigns(): void
    {
        $campaign = UnisenderCampaign::query()->where('statistics_received', false)->first();
        if (!$campaign) {
            $this->info("Нет кампаний по которым не получена статистика");
            return;
        }
        $this->info("Сбор статистики по кампании #{$campaign->id}");
        $this->processCompletedCampaign($campaign->toArray());
    }
}
