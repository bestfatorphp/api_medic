<?php

namespace App\Console\Commands\ImportCampaigns;

use App\Facades\UniSender;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandAlias;

class ImportCampaignStatsCommand extends Common
{
    /**
     * @var string
     */
    protected $signature = 'import:campaigns-stats
                            {--from= : Начальная дата в формате DD.MM.YYYY}
                            {--to= : Конечная дата в формате DD.MM.YYYY}';

    /**
     * @var string
     */
    protected $description = 'Сбор детализированной статистики по email-рассылкам';

    /**
     * Лимит количества кампаний за один запрос к API (пагинация).
     */
    private const LIMIT = 1;

    /**
     * Путь к временному файлу
     * @var string|bool
     */
    protected string|bool $tmpFilePath = 'unisender_stats_temp_';

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
        //определение периода сбора данных
        $fromDate = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfDay()->format('Y-m-d H:i:s')
            : Carbon::now()->subDay()->startOfDay()->format('Y-m-d H:i:s');

        $toDate = $this->option('from')
            ? Carbon::parse($this->option('to'))->endOfDay()->format('Y-m-d H:i:s')
            : Carbon::now()->subDay()->endOfDay()->format('Y-m-d H:i:s');

        $this->info("Сбор статистики с {$fromDate} по {$toDate}");

        try {
            $this->processAllCampaigns($fromDate, $toDate);
            $this->info('Сбор статистики завершен успешно');
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

        return CommandAlias::SUCCESS;
    }

    /**
     * Обрабатываем все кампании за указанный период.
     *
     * @param string $fromDate Начальная дата периода.
     * @param string $toDate Конечная дата периода.
     * @throws \Exception
     */
    private function processAllCampaigns(string $fromDate, string $toDate): void
    {
        $offset = 0;

        do {
            $this->info("Загрузка кампаний (offset: {$offset})...");

            //получаем список кампаний с учетом пагинации
            $campaigns = UniSender::getCampaigns([
                'from' => $fromDate,
                'to' => $toDate,
                'limit' => self::LIMIT,
                'offset' => $offset
            ]);

            $fetchedCount = count($campaigns['result'] ?? []);
            if ($fetchedCount === 0) {
                break;
            }

            foreach ($campaigns['result'] as $campaign) {
                $this->processCompletedCampaign($campaign);
            }

            $offset += self::LIMIT;
            //освобождаем память
            unset($campaigns);
            gc_collect_cycles();

        } while ($fetchedCount === self::LIMIT);
    }
}
