<?php

namespace App\Console\Commands\ImportSendSay;

use App\Models\CommonDatabase;
use App\Models\SendsayContact;
use App\Models\SendsayParticipation;
use App\Traits\WriteLockTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FillContactsFromParticipations extends Command
{
    use WriteLockTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:sendsay-fill-contacts
                            {--from= : Начальная дата m.d.Y}
                            {--to= : Конечная дата m.d.Y}
                            {--batch-size=1000 : Размер пакета для обработки}
                            {--insert-batch=500 : Размер пакета для вставки}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Заполняем SendsayContact и CommonDatabase из SendsayParticipation';

    /**
     * Размер пакета для обработки
     */
    protected int $batchSize;

    /**
     * Размер пакета для вставки
     */
    protected int $insertBatchSize;

    /**
     * Execute the console command.
     *
     * @return int
     * @throws \Exception
     */
    public function handle(): int
    {
        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M');
        set_time_limit(0);
        DB::disableQueryLog();

        $this->batchSize = (int)$this->option('batch-size');
        $this->insertBatchSize = (int)$this->option('insert-batch');

        // Получаем параметры фильтрации
        $from = $this->option('from');
        $to = $this->option('to');

        $this->info("Начинаем обработку участников...");
        $this->info("Размер пакета обработки: {$this->batchSize}");
        $this->info("Размер пакета вставки: {$this->insertBatchSize}");

        if ($from) {
            $this->info("Дата ОТ: {$from}");
        } else {
            $this->info("Дата ОТ: с самой первой записи");
        }

        if ($to) {
            $this->info("Дата ДО: {$to}");
        } else {
            $this->info("Дата ДО: до текущего момента");
        }

        // Обрабатываем данные
        $this->processParticipations($from, $to);

        $this->info("Обработка завершена успешно!");
        return self::SUCCESS;
    }

    /**
     * Обработка участников рассылки
     *
     * @param string|null $from
     * @param string|null $to
     * @throws \Exception
     */
    protected function processParticipations(?string $from, ?string $to): void
    {
        $query = SendsayParticipation::query()
            ->select(['id', 'email', 'result', 'update_time'])
            ->orderBy('id');

        if ($from) {
            $fromDate = Carbon::parse($from)->startOfDay();
            $query->where('update_time', '>=', $fromDate);
        }

        if ($to) {
            $toDate = Carbon::parse($to)->endOfDay();
            $query->where('update_time', '<=', $toDate);
        }

        $totalRecords = $query->count();
        $this->info("Всего записей для обработки: {$totalRecords}");

        $totalProcessed = 0;
        $lastId = 0;
        $allUniqueEmails = [];

        do {
            $batchQuery = clone $query;

            if ($lastId > 0) {
                $batchQuery->where('id', '>', $lastId);
            }

            $participations = $batchQuery->limit($this->batchSize)->get();

            if ($participations->isEmpty()) {
                break;
            }

            $batchContacts = [];
            $batchCommonDB = [];
            $processedEmailsInBatch = [];

            foreach ($participations as $participation) {
                $email = strtolower(trim($participation->email));
                $result = $participation->result;

                if (empty($email)) {
                    continue;
                }

                $availability = $result !== 'not delivered';
                $emailStatus = $availability ? 'active' : 'blocked';

                // Только если email еще не встречался в этой пачке
                if (!isset($batchContacts[$email])) {
                    $batchContacts[$email] = [
                        'email' => $email,
                        'email_status' => $emailStatus,
                        'email_availability' => $availability,
                    ];

                    if (!in_array($email, $allUniqueEmails)) {
                        $allUniqueEmails[] = $email;
                    }
                } else {
                    if ($result === 'not delivered') {
                        $batchContacts[$email]['email_status'] = 'blocked';
                        $batchContacts[$email]['email_availability'] = false;
                    }
                }

                if (!in_array($email, $processedEmailsInBatch)) {
                    $processedEmailsInBatch[] = $email;

                    $batchCommonDB[] = [
                        'email' => $email,
                        'email_status' => $emailStatus,
                    ];
                }

                $lastId = $participation->id;
                $totalProcessed++;

                if (count($batchContacts) >= $this->insertBatchSize) {
                    $this->saveBatchData($batchContacts, $batchCommonDB);
                    $batchContacts = [];
                    $batchCommonDB = [];
                    $processedEmailsInBatch = [];
                }
            }

            if (!empty($batchContacts) || !empty($batchCommonDB)) {
                $this->saveBatchData($batchContacts, $batchCommonDB);
            }

            $uniqueCount = count($allUniqueEmails);
            $this->info("Обработано записей: {$totalProcessed}/{$totalRecords}, уникальных email: {$uniqueCount}");

            if (count($allUniqueEmails) > 10000) {
                $allUniqueEmails = array_slice($allUniqueEmails, -10000);
            }

            unset($participations, $batchContacts, $batchCommonDB, $processedEmailsInBatch);
            gc_collect_cycles();

        } while (true);

        $finalUniqueCount = count($allUniqueEmails);
        $this->info("Итого обработано: {$totalProcessed} записей, {$finalUniqueCount} уникальных email");
    }

    /**
     * Пакетная вставка данных
     *
     * @param array $batchContacts
     * @param array $batchCommonDB
     * @throws \Exception
     */
    protected function saveBatchData(array &$batchContacts, array &$batchCommonDB): void
    {
        $startTime = microtime(true);

        if (!empty($batchContacts)) {
            $this->withTableLock('sendsay_contacts', function () use ($batchContacts) {
                SendsayContact::upsert(
                    array_values($batchContacts),
                    ['email'],
                    ['email_status', 'email_availability']
                );
            });
            $this->info("✓ Вставлено/обновлено " . count($batchContacts) . " записей в SendsayContact");
        }

        if (!empty($batchCommonDB)) {
            $this->withTableLock('common_database', function () use ($batchCommonDB) {
                CommonDatabase::upsert(
                    $batchCommonDB,
                    ['email'],
                    ['email_status']
                );
            });
            $this->info("✓ Вставлено/обновлено " . count($batchCommonDB) . " записей в CommonDatabase");
        }

        $executionTime = round(microtime(true) - $startTime, 2);
        $this->info("Время выполнения вставки: {$executionTime} сек.");

        gc_mem_caches();
    }
}
