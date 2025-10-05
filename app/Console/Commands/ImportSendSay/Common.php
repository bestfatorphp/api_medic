<?php

namespace App\Console\Commands\ImportSendSay;

use App\Models\SendsayIssue;
use App\Models\SendsayParticipation;
use App\Traits\WriteLockTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JetBrains\PhpStorm\ArrayShape;

class Common extends Command
{
    use WriteLockTrait;

    /**
     * @var string
     */
    protected $signature = 'import:sendsay';

    /**
     * @var string
     */
    protected $description = 'Общий класс для команд импорта SendSay';

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
     * Пакетная вставка участий
     * @param array $batchParticipations
     * @param string $status
     * @param bool $withUpdate
     */
    protected function saveBatchDataParticipations(array &$batchParticipations, string $status, bool $withUpdate = false): void
    {
        if (!empty($batchParticipations)) {
            $this->withTableLock('sendsay_participation', function () use ($batchParticipations, $status, $withUpdate) {
                if ($status === 'deliv.issue' && $withUpdate) {
                    collect($batchParticipations)->chunk(100)->each(function ($chunk) {
                        foreach ($chunk as $participation) {
                            SendsayParticipation::query()->updateOrCreate(
                                [
                                    'issue_id' => $participation['issue_id'],
                                    'email' => $participation['email'],
                                    'sendsay_key' => $participation['sendsay_key'],
                                ],
                                $participation
                            );
                        }
                    });

                } else {
                    SendsayParticipation::insert($batchParticipations);
                }
            });

            $batchParticipations = [];

            gc_mem_caches(); //очищаем кэши памяти Zend Engine
        }
    }

    /**
     * Подготавливаем данные участия
     * @param int $issueId
     * @param string $email
     * @param string $result
     * @param string $sendsayKey
     * @param string|null $time
     * @return array
     */
    #[ArrayShape(['issue_id' => "int", 'email' => "string", 'result' => "string", 'sendsay_key' => "string", 'update_time' => "\Carbon\Carbon|null"])]
    protected function prepareParticipationResult(int $issueId, string $email, string $result, string $sendsayKey, string $time = null): array
    {
        return [
            'issue_id' => $issueId,
            'email' => $email,
            'result' => $result,
            'update_time' => !empty($time) ? Carbon::parse($time) : null,
            'sendsay_key' => $sendsayKey
        ];
    }

    /**
     * Считаем delivered, click и read
     * @param int $issueId
     * @param bool $withCountDeliv
     * @return array
     */
    #[ArrayShape(['delivered' => "int|null", 'clicked' => "int", 'read' => "int"])]
    protected function getActualStats(int $issueId, bool $withCountDeliv = false): array
    {
        return [
            'delivered' => $withCountDeliv ? SendsayParticipation::query()
                ->where('issue_id', $issueId)
                ->where('result', 'delivered')
                ->count() : null,

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
    protected function updateIssueStats(SendsayIssue $issue, array $stats): void
    {
        $delivered = $stats['delivered'] ?? $issue->delivered;
        $clicked = $stats['clicked'];
        $read = $stats['read'];

        $issue->update([
            'delivered' => $delivered,
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
    protected function calculateRate(int $total, int $countSuccess): float
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
