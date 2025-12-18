<?php

namespace App\Console\Commands\ImportSendSay;

use App\Facades\SendSay;
use App\Models\SendsayIssue;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ImportCampaignsSandSayDeliv extends Common
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:sendsay-stats-deliv {--hasIsSent=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Собираем данные о доставке писем SendSay';


    private bool $hasIsSent = false;

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     * @throws \Exception
     */
    public function handle()
    {
        $builder = SendsayIssue::query();

        if ($this->hasIsSent = (bool)$this->option('hasIsSent')) {
            $builder->whereHas('sendsay_participations', function ($q) {
                $q->where('result', 'is sent');
            })->orWhere(function($q) {
                $q->where('send_date', '>=', Carbon::now()->subDay()->startOfDay())
                    ->where('send_date', '<=', Carbon::now()->subDay()->endOfDay());
            });
        }

        $issues = $builder->orderBy('id')->get();

        $sendsayKey = 'deliv.issue';

        foreach ($issues as $issue) {
            $issueId = $issue->id;
            $this->info("Собираю deliv по рассылке с ID: $issueId");
            $skip = 0;
            $count = 0;
            $limit = 500;

            $delivered = 0;
            $notDelivered = 0;
            $isSent = 0;

            $batchContacts = [];
            $batchCommonDB = [];
            $batchParticipations = [];
            $processedEmails = [];

            do {
                $this->info("Собираю - $count");
                //получаем clicked и read
                $participations = $this->getParticipationsDelivByIssueId($issueId, $limit, $skip);

                foreach ($participations as $participation) {
                    $result = 'delivered';

                    if ($participation[3] === "1") {
                        ++$delivered;
                    }
                    if ($participation[3] === "0") {
                        $result = 'is sent';
                        ++$isSent;
                    }
                    if ($participation[3] === "-1") {
                        $result = 'not delivered';
                        ++$notDelivered;
                    }

                    $email = strtolower($participation[0]);
                    $this->setDataPackages($processedEmails, $email, $result, $batchContacts, $batchCommonDB);

                    $batchParticipations[] = $this->prepareParticipationResult($issueId, $email, $result, $sendsayKey, $participation[2]);

                    if (count($batchParticipations) % 500 == 0) {
                        $this->saveBatchDataParticipations($batchParticipations, $sendsayKey, $this->hasIsSent);
                        $this->saveBatchData($batchContacts, $batchCommonDB);
                        $processedEmails = [];
                    }
                }

                if (!empty($batchParticipations)) {
                    $this->saveBatchDataParticipations($batchParticipations, $sendsayKey, $this->hasIsSent);
                    $this->saveBatchData($batchContacts, $batchCommonDB);
                    $processedEmails = [];
                }

                $skip += $limit;
                ++$count;
            } while (!empty($participations));

            $this->info("Доставлено - $delivered");
            $this->info("В отправке - $isSent");
            $this->info("Не доставлено - $notDelivered");

            $this->info("Обновляем данные");
            //обновляем данные
            $stats = $this->getActualStats($issue->id, true);
            $this->updateIssueStats($issue, $stats);
        }

        $this->info("Сбор deliv завершён!");
        return self::SUCCESS;
    }

    private function getParticipationsDelivByIssueId(int $issueId, int $limit, int $skip = 0): array
    {
        $common = array_merge([
            'select' => [               //доставка
                "deliv.member.email",
                "deliv.issue.id",
                "deliv.issue.dt",
                "deliv.result",
            ],
            'filter' => [
                ['a' => "deliv.issue.id", 'op' => '==', 'v' => (string)$issueId]
            ],
        ], [
            'first' => $limit,
            'skip' => $skip,
            'missing_too' => 1
        ]);

        $response = SendSay::statUni($common);

        sleep(1);


        if(!empty($response['errors']) || !empty($response['error'])) {
            Log::channel('commands')->error('err:', $response);
        }

        return $response['list'] ?? [];
    }
}
