<?php

namespace App\Console\Commands;

use App\Facades\SendSay;
use App\Logging\CustomLog;
use App\Models\CommonDatabase;
use App\Models\SendsayContact;
use App\Models\SendsayIssue;
use App\Models\SendsayParticipation;
use App\Traits\WriteLockTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * Команда для импорта статистики рассылок из SendSay в базу данных
 */
class ImportCampaignsSandSay extends Command
{
    use WriteLockTrait;

    /**
     * @var string
     */
    protected $signature = 'import:sendsay-stats
                            {--from= : Начальная дата в формате DD.MM.YYYY}
                            {--to= : Конечная дата в формате DD.MM.YYYY}
                            {--limit=500 : Колличество записей за один запрос}
                            {--sleep=1 : Задержка между запросами}';

    /**
     * @var string
     */
    protected $description = 'Импорт статистики SendSay, в ограниченной памяти';

    /**
     * Размер пакета для вставки
     * @var int
     */
    private $batchSize = 500;

    /**
     * Поля статистики, получаемые из API SendSay
     * @var array
     */
    private array $statFields = [
        'issue.id',         // id выпуска
        'issue.name',       // тема выпуска
        'issue.dt',         // дата выпуска
        'issue.members',    // число получателей выпуска
        'issue.deliv_ok',   // количество успешно доставленных писем
        'issue.deliv_bad',  // количество писем с постоянной ошибкой доставки
        'issue.clicked',    // количество кликов
        'issue.u_clicked',  // количество уникальных кликов
        'issue.readed',     // количество чтений
        'issue.u_readed',   // количество уникальных чтений
        'issue.duration',   // среднее время чтений
        'issue.unsubed',    // количество отписок из выпуска
        'member.email',     // email подписчика
        'member.haslock',   // заблокирован ли подписчик
        'read.dt',          // дата и время чтения
    ];

    /**
     * @return int
     */
    public function handle(): int
    {
        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M'); //установка лимита памяти
        set_time_limit(0); //без ограничения времени выполнения
        DB::disableQueryLog(); //отключаем логирование запросов

        try {
            $options = $this->validateOptions();
            $issues = $this->getIssuesList($options['from'], $options['to']);

            if (empty($issues)) {
                $this->info('Нет рассылок за указанный период');
                return CommandAlias::SUCCESS;
            }

            $this->info("Сбор статистики с {$options['from']} по {$options['to']}");

            $progressBar = new ProgressBar($this->output, count($issues));
            $progressBar->start();

            foreach ($issues as $issue) {
                try {
                    $this->processIssue($issue, $options['limit']);
                    $progressBar->advance();

                    if ($options['sleep'] > 0) {
                        sleep($options['sleep']);
                    }
                } catch (\Exception $e) {
                    Log::channel('commands')->error("Ошибка обработки issue {$issue['id']}: " . $e->getMessage());
                    $this->warn(" [!] Ошибка обработки issue {$issue['id']}: " . $e->getMessage());
                }
            }

            $progressBar->finish();
            $this->newLine();

            $this->info("\nСбор статистики завершен успешно");
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка: " . $e->getMessage());
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Обрабатываем одну рассылку: получаем статистику и сохраняем в БД
     *
     * @param array $issue Данные рассылки
     * @param int $limit Лимит записей для получения
     */
    private function processIssue(array $issue, int $limit): void
    {
        $issueId = $issue['id'];
        $isIssueCreated = false;
        $batchContacts = [];
        $batchParticipations = [];
        $batchCommonDB = [];
        $processedEmails = [];
        $batchCount = 0;
        $skip = 0;
        $hasMore = true;

        while ($hasMore) {
            $stats = $this->getIssueStats($issueId, $limit, $skip);

            if (empty($stats)) {
                $hasMore = false;
                continue;
            }

            //создаем запись о рассылке при первом получении данных
            if (!$isIssueCreated) {
                $this->createIssueIfNotExists($stats[0]);
                $isIssueCreated = true;
            }

            foreach ($stats as $stat) {
                $email = $stat['member.email'];

                if (!in_array($email, $processedEmails)) {
                    $processedEmails[] = $email;
                    //подготавливаем общие данные
                    $batchCommonDB[] = [
                        'email' => $email
                    ];
                }

                //подготавливаем контакты
                $batchContacts[$email] = [
                    'email' => $email,
                    'email_status' => $stat['member.haslock'] == '0' ? 'active' : 'blocked',
                    'email_availability' => $stat['member.haslock'] == '0',
                ];

                //подготавливаем участия
                $batchParticipations[] = [
                    'issue_id' => $issueId,
                    'email' => $email,
                    'result' => $this->determineParticipationResult($stat),
                    'update_time' => isset($stat['read.dt']) ? Carbon::parse($stat['read.dt']) : null,
                ];

                $batchCount++;

                //сохраняем при достижении размера пакета
                if ($batchCount >= $this->batchSize) {
                    $this->saveBatchData($batchContacts, $batchParticipations, $batchCommonDB);
                    $batchCount = 0;
                }
            }

            $skip += $limit;
            $hasMore = count($stats) === $limit;
        }

        //сохраняем оставшиеся данные
        if ($batchCount > 0) {
            $this->saveBatchData($batchContacts, $batchParticipations, $batchCommonDB);
        }
    }

    /**
     * Пакетная вставка
     * @param array $batchContacts
     * @param array $batchParticipations
     * @param array $batchCommonDB
     */
    private function saveBatchData(array &$batchContacts, array &$batchParticipations, array &$batchCommonDB): void
    {
        if (!empty($batchContacts)) {
            $this->withTableLock('sendsay_contacts', function () use ($batchContacts) {
                SendsayContact::upsert(
                    array_values($batchContacts),
                    ['email'],
                    ['email_status', 'email_availability']
                );
            });
            $batchContacts = [];
        }

        if (!empty($batchParticipations)) {
            $this->withTableLock('sendsay_participation', function () use ($batchParticipations) {
                SendsayParticipation::insertOrIgnore($batchParticipations);
            });
            $batchParticipations = [];
        }

        if (!empty($batchCommonDB)) {
            $this->withTableLock('common_database', function () use ($batchCommonDB) {
                CommonDatabase::upsert($batchCommonDB, ['email']);
            });
            $batchCommonDB = [];
        }

        gc_mem_caches(); //очищаем кэши памяти Zend Engine
    }


    /**
     * Сохраняем расылку
     * @param array $statData
     */
    private function createIssueIfNotExists(array $statData): void
    {
        $issueId = $statData['issue.id'];

        if (SendsayIssue::where('id', $issueId)->exists()) {
            return;
        }

        $this->withTableLock('sendsay_issue', function () use ($issueId, $statData) {
            SendsayIssue::create([
                'id' => $issueId,
                'issue_name' => $statData['issue.name'],
                'send_date' => Carbon::parse($statData['issue.dt']),
                'sent' => $statData['issue.members'],
                'delivered' => $statData['issue.deliv_ok'],
                'opened' => $statData['issue.readed'],
                'open_per_unique' => $statData['issue.u_readed'],
                'clicked' => $statData['issue.clicked'],
                'clicks_per_unique' => $statData['issue.u_clicked'],
                'delivery_rate' => $this->calculateRate($statData['issue.members'], $statData['issue.deliv_ok']),
                'open_rate' => $this->calculateRate($statData['issue.deliv_ok'], $statData['issue.readed']),
                'ctr' => $this->calculateRate($statData['issue.deliv_ok'], $statData['issue.clicked']),
                'ctor' => $this->calculateRate($statData['issue.readed'], $statData['issue.clicked']),
            ]);
        });
    }

    /**
     * Получаем статистику согласно пагинации
     * @param string $issueId
     * @param int $limit
     * @param int $skip
     * @return array
     */
    private function getIssueStats(string $issueId, int $limit, int $skip = 0): array
    {
        $response = SendSay::statUni([
            'select' => $this->statFields,
            'filter' => [
                ['a' => 'issue.id', 'op' => '==', 'v' => $issueId]
            ],
            'first' => $limit,
            'skip' => $skip,
            'missing_too' => 1
        ]);

        return array_map(function ($item) {
            return array_combine($this->statFields, $item);
        }, $response['list'] ?? []);
    }

    /**
     * @param array $stat
     * @return string
     */
    private function determineParticipationResult(array $stat): string
    {
        if ($stat['issue.deliv_ok'] == 0) {
            return 'delivery_failed';
        }

        if (!empty($stat['read.dt'])) {
            return $stat['issue.u_clicked'] > 0 ? 'clicked' : 'opened';
        }

        return 'delivered';
    }

    /**
     * Обрабатываем параметры команды
     * @return array
     */
    private function validateOptions(): array
    {
        $fromDate = $this->option('from')
            ? Carbon::createFromFormat('d.m.Y', $this->option('from'))->startOfDay()->format('Y-m-d')
            : Carbon::now()->subDay()->startOfDay()->format('Y-m-d');

        $toDate = $this->option('to')
            ? Carbon::createFromFormat('d.m.Y', $this->option('to'))->endOfDay()->format('Y-m-d')
            : Carbon::now()->subDay()->endOfDay()->format('Y-m-d');

        $limit = (int)$this->option('limit');
        $sleep = (int)$this->option('sleep');

        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Лимит должен быть от 1 до 1000');
        }

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'limit' => $limit,
            'sleep' => $sleep
        ];
    }

    /**
     * Получаем все рассылки, согласно периоду
     * @param string $from
     * @param string $to
     * @return array
     */
    private function getIssuesList(string $from, string $to): array
    {
        $response = SendSay::issueList([
            'from' => $from,
            'upto' => $to
        ]);

        return $response['list'] ?? [];
    }

    /**
     * Рассчитывает процент
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

        $rate = ($countSuccess / $total) * 100;

        if ($rate > 100) {
            $rate = 100;
        }

        return round($rate, 2);
    }
}
