<?php

namespace App\Console\Commands;

use App\Facades\SendSay;
use App\Logging\CustomLog;
use App\Models\CommonDatabase;
use App\Models\SendsayContact;
use App\Models\SendsayIssue;
use App\Models\SendsayParticipation;
use App\Models\SendsayParticipationDeliv;
use App\Traits\WriteLockTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * Команда для импорта статистики рассылок из SendSay в базу данных
 *
 * !!! Забирать данные now - 2 days, иначе баг выборки по датам
 */
class ImportCampaignsSandSay extends Command
{
    use WriteLockTrait;

    /**
     * @var string
     */
    protected $signature = 'import:sendsay-stats
                            {--from= : Начальная дата сбора clicks и read в формате DD.MM.YYYY}
                            {--fromLastDeliv=0}
                            {--onlyDeliv=0}
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
    private int $batchSize = 500;

    /**
     * Собирать с даты
     * @var string
     */
    private string $fromDate;

    /**
     * Собирать до даты
     * @var string
     */
    private string $toDate;

    /**
     * Обновлять ли участия доставки писем пользователям
     * @var bool
     */
    private bool $fromLastDeliv;

    /**
     * Статусы для получения участий
     * @var array|string[]
     */
    private array $statuses = ['click', 'read'/*, 'deliv.issue'*/];

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
            $issues = $this->getIssuesList();

            if (empty($issues)) {
                $this->info('Нет рассылок за указанный период');
                return CommandAlias::SUCCESS;
            }

            $this->info("Сбор статистики с {$this->fromDate} по {$this->toDate}");

            $progressBar = new ProgressBar($this->output, count($issues));
            $progressBar->start();

            $onlyDeliv = (bool)$this->option('onlyDeliv');
            $limit = $options['limit'];

            if (!$onlyDeliv) {
                foreach ($issues as $issue) {
                    try {
                        $this->processIssue($issue, $limit);
                        $progressBar->advance();
                        if ($options['sleep'] > 0) {
                            sleep($options['sleep']);
                        }
                    } catch (\Exception $e) {
                        Log::channel('commands')->error("Ошибка обработки issue {$issue['id']}: " . $e->getMessage());
                        $this->warn(" [!] Ошибка обработки issue {$issue['id']}: " . $e->getMessage());
                    }
                }
            } else {
                $this->statuses = ['deliv.issue'];
            }

            $this->newLine();

            //собираем участия отдельно за дату от и до
            $this->processParticipations($limit);

            $progressBar->finish();

            if (!$onlyDeliv) {
                $this->newLine();
                $this->info("Обновляем рассылки, данными по {$this->toDate}");
                //обновляем данные
                $this->getActualStatsIssues();
            }

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
        $batchCommonDB = [];
        $batchContacts = [];
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

            //создаем/обновляем запись о рассылке
            $this->createOrUpdateIssue($stats[0]);


            foreach ($stats as $stat) {
                $email = $stat['member.email'];

                $email_status = $stat['member.haslock'] == '0' ? 'active' : 'blocked';

                if (!in_array($email, $processedEmails)) {
                    $processedEmails[] = $email;

                    //подготавливаем общие данные
                    $batchCommonDB[] = [
                        'email' => $email,
                        'email_status' => $email_status
                    ];
                }

                //подготавливаем контакты
                $batchContacts[$email] = [
                    'email' => $email,
                    'email_status' => $email_status,
                    'email_availability' => $stat['member.haslock'] == '0',
                ];

                $batchCount++;

                //сохраняем при достижении размера пакета
                if ($batchCount >= $this->batchSize) {
                    $this->saveBatchData($batchContacts, $batchCommonDB);
                    $batchCount = 0;
                }
            }

            $skip += $limit;
            $hasMore = count($stats) === $limit;
        }

        //сохраняем оставшиеся данные
        if ($batchCount > 0) {
            $this->saveBatchData($batchContacts, $batchCommonDB);
        }
    }

    /**
     * Получаем статистику по кликам, чтению и доставке писем
     * @param int $limit
     */
    private function processParticipations(int $limit)
    {

        $batchParticipations = [];
        foreach ($this->statuses as $status) {
            $this->getParticipationsAndSave($status, $limit, $batchParticipations);
        }
    }

    /**
     * Получаем участия
     * @param string $status
     * @param int $limit
     * @param array $batchParticipations
     */
    private function getParticipationsAndSave(string $status, int $limit, array &$batchParticipations)
    {
        $skip = 0;
        $count = 0;

        //забираем последнюю дату deliv, чтобы собрать от неё (статстика по deliv только до 22.07.2025)
        if ($status === 'deliv.issue' && $this->fromLastDeliv) {
            $lastDeliv = SendsayParticipationDeliv::query()->latest('update_time')
                ->value('update_time');

            $this->fromDate = Carbon::parse($lastDeliv)->subDays(2)->format('Y-m-d');

            $this->info("Дата от, для deliv.issue изменена на {$this->fromDate}");
        }

        do {
            $this->info("Собираю $status - $count");
            //получаем clicked и read
            $participations = $this->getParticipationsByDates($status, $limit, $skip);

            $statusesData = [
                'click' => 'clicked',
                'read' => 'read',
                'deliv.issue' => 'delivered',
            ];

            foreach ($participations as $participation) {
                if ($status === 'deliv.issue' && (int)$participation[3] < 1) {
                    $statusesData[$status] = 'not delivered';
                }

                $result = $statusesData[$status];
                $email = $participation[0];
                $issueId = (int)$participation[1];
                $batchParticipations[] = $this->prepareParticipationResult($issueId, $email, $result, $participation[$status === 'click' ? 3 : 2]);

                // Принудительно сохраняем каждые N записей
                if (count($batchParticipations) % 100 == 0) {
                    $this->saveBatchDataParticipations($batchParticipations, $status);
                }
            }

            // Сохраняем остатки после каждого запроса к API
            if (!empty($batchParticipations)) {
                $this->saveBatchDataParticipations($batchParticipations, $status);
            }

            $skip += $limit;
            ++$count;
        } while (!empty($participations));
    }

    /**
     * Пакетная вставка
     * @param array $batchContacts
     * @param array $batchCommonDB
     */
    private function saveBatchData(array &$batchContacts, array &$batchCommonDB): void
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

        if (!empty($batchCommonDB)) {
            $this->withTableLock('common_database', function () use ($batchCommonDB) {
                CommonDatabase::upsert(
                    $batchCommonDB,
                    ['email'],
                    ['email_status']
                );
            });
            $batchCommonDB = [];
        }

        gc_mem_caches(); //очищаем кэши памяти Zend Engine
    }

    /**
     * Пакетная вставка участий
     * @param array $batchParticipations
     * @param string $status
     */
    private function saveBatchDataParticipations(array &$batchParticipations, string $status): void
    {
        if (!empty($batchParticipations)) {
            $this->withTableLock('sendsay_participation', function () use ($batchParticipations, $status) {
                if ($status === 'deliv.issue') {
                    SendsayParticipationDeliv::insertOrIgnore($batchParticipations);
                } else {
                    SendsayParticipation::insert($batchParticipations);
                }
            });

            $batchParticipations = [];

            gc_mem_caches(); //очищаем кэши памяти Zend Engine
        }
    }


    /**
     * Сохраняем/обновляем рассылку
     * @param array $statData
     */
    private function createOrUpdateIssue(array $statData): void
    {
        $issueId = $statData['issue.id'];

        $this->withTableLock('sendsay_issue', function () use ($issueId, $statData) {
            SendsayIssue::updateOrCreate(
                ['id' => $issueId],
                [
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
     * Получаем данные по кликам, доставке, чтению, по конкретной рассылке для конкретного email
     * @param string $status
     * @param int $limit
     * @param int $skip
     * @return array
     */
    private function getParticipationsByDates(string $status, int $limit, int $skip = 0): array
    {
        $from = $this->fromDate . ' 00:00:00';
        $to = $this->toDate . ' 23:59:59';

        $filter = [
            ['a' => "{$status}.dt", 'op' => '>=', 'v' => $from],
            ['a' => "{$status}.dt", 'op' => '<=', 'v' => $to]
        ];

        $dataRequest = [
            'click' => [
                'select' => [               //клики по ссылкам в письме
                    "click.member.email",
                    "click.issue.id",
                    "click.link.url",
                    "click.dt",
                ],
                'filter' => $filter
            ],
            'deliv.issue' => [
                'select' => [               //доставка
                    "deliv.member.email",
                    "deliv.issue.id",
                    "deliv.issue.dt",
                    "deliv.result"
                ],
                'filter' => $filter
            ],
            'read' => [
                'select' => [               //чтения письма
                    "read.member.email",
                    "read.issue.id",
                    "read.dt",
                ],
                'filter' => $filter
            ]
        ];

        $common = array_merge($dataRequest[$status], [
            'first' => $limit,
            'skip' => $skip,
            'missing_too' => 1
        ]);

        $response = SendSay::statUni($common);


        if(!empty($response['errors']) || !empty($response['error'])) {
            Log::channel('commands')->error('err:', $response);
        }

        return $response['list'] ?? [];

//                        click resp:
//                        [
//                            0 => [
//                                0 => "alexnat1@yandex.ru"
//                                1 => "356"
//                                2 => "https://medtouch.oragen.ru/calendar/199"
//                                4 => "2025-07-28 19:01:05"
//                              ],
//                              ....
//                        ]

//                        deliv resp:
//                        [
//                           [
//                                0 => "a.grabarnik@mail.ru"
//                                1 => "356"
//                                2 => "2025-07-28 19:01:05"
//                                3 => "1"
//                            ],
//                            ....
//                        ]

//                        read resp:
//                        [
//                            [
//                                0 => "a.grabarnik@mail.ru"
//                                1 => "356"
//                                2 => "2025-07-28 19:01:05"
//                            ],
//                            ....
//                        ]
    }

    /**
     * Подготавливаем данные участия
     * @param int $issueId
     * @param string $email
     * @param string $result
     * @param string|null $time
     * @return array
     */
    #[ArrayShape(['issue_id' => "int", 'email' => "string", 'result' => "string", 'update_time' => "\Carbon\Carbon|null"])]
    private function prepareParticipationResult(int $issueId, string $email, string $result, string $time = null): array
    {
        return [
            'issue_id' => $issueId,
            'email' => $email,
            'result' => $result,
            'update_time' => !empty($time) ? Carbon::parse($time) : null,
        ];
    }

    /**
     * Обрабатываем параметры команды
     * @return array
     */
    #[ArrayShape(['limit' => "int", 'sleep' => "int"])]
    private function validateOptions(): array
    {
        $this->fromDate = $this->option('from')
            ? Carbon::createFromFormat('d.m.Y', $this->option('from'))->startOfDay()->format('Y-m-d')
            : Carbon::now()->subDay()->format('Y-m-d');

        $this->toDate = Carbon::now()->subDay()->format('Y-m-d');

        $this->fromLastDeliv = (bool)$this->option('fromLastDeliv');

        $limit = (int)$this->option('limit');
        $sleep = (int)$this->option('sleep');

        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Лимит должен быть от 1 до 1000');
        }

        return [
            'limit' => $limit,
            'sleep' => $sleep
        ];
    }

    /**
     * Получаем все рассылки, согласно периоду
     * @return array
     */
    private function getIssuesList(): array
    {
        //получать только обновленные в суточной команде
        $response = SendSay::issueList([
            'from' => '2025-05-01',
            'upto' => Carbon::now()->subDay()->format('Y-m-d')
        ]);

        return $response['list'] ?? [];
    }

    /**
     * Актуализируем согласно собранным click и read
     */
    private function getActualStatsIssues()
    {
        $issues = SendsayIssue::query()
            ->select(['id', 'delivered'])
            ->get();
        collect($issues)->chunk(100)->each(function ($chunk) {
            foreach ($chunk as $issue) {
                $stats = $this->getActualStats($issue->id);
                $this->updateIssueStats($issue, $stats);
            }
        });
    }

    /**
     * Считаем click и read
     * @param int $issueId
     * @return array
     */
    #[ArrayShape(['clicked' => "int", 'read' => "int"])]
    private function getActualStats(int $issueId): array
    {
        return [
            'clicked' => SendsayParticipation::query()
                ->where('issue_id', $issueId)
                ->where('result', 'clicked')
                ->count(),

            'read' => SendsayParticipation::query()
                ->where('issue_id', $issueId)
                ->where('result', 'read')
                ->count(),
        ];
    }

    /**
     * Обновляем запись рассылки
     * @param SendsayIssue $issue
     * @param array $stats
     */
    private function updateIssueStats(SendsayIssue $issue, array $stats): void
    {
        $delivered = $issue->delivered;
        $clicked = $stats['clicked'];
        $read = $stats['read'];

        $issue->update([
            'clicked' => $clicked,
            'opened' => $read,
            'open_rate' => $this->calculateRate($delivered, $read),
            'ctr' => $this->calculateRate($delivered, $clicked),
            'ctor' => $this->calculateRate($read, $clicked),
        ]);
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
