<?php

namespace App\Console\Commands\ImportNewMt;

use App\Facades\UniSender;
use App\Models\CommonDatabase;
use App\Models\UnisenderCampaign;
use App\Models\UnisenderContact;
use App\Models\UnisenderParticipation;
use App\Traits\WriteLockTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\NoReturn;

class Common extends Command
{
    use WriteLockTrait;

    /**
     * @var string
     */
    protected $signature = 'import:new-mt';

    /**
     * @var string
     */
    protected $description = 'Общий класс для команд импорта нового сайта MT';

    /**
     * Колличество записей за один запрос
     * @var int
     */
    protected int $pageSize;

    /**
     * Версия апи
     * @var int
     */
    protected int $apiVersion = 1;

    /**
     * Размер партии для пакетной вставки данных в базу.
     */
    protected const BATCH_SIZE = 1000;

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
     * Делаем запрос на сторонний сервис
     * @param string $endpoint  Эндпоинт
     * @param array $body       Body гет-запроса
     * @param int $page         Номер страницы пагинации
     * @return mixed
     * @throws \Exception
     */
    protected function getData(string $endpoint, array $body, int $page): mixed
    {
        try {
            $url = env("NEW_MT_URL_{$this->apiVersion}") . $endpoint;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env("NEW_MT_OUTER_TOKEN_{$this->apiVersion}"),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->send('GET', $url . "?page=$page", [
                'body' => json_encode($body), //повторяем запрос Postman
            ]);

            if (!$response->successful()) {
                throw new \Exception("API request, по [$url], завершился с ошибкой. Status: {$response->status()}. Response: " . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            $this->error("Ошибка в getData: " . $e->getMessage());
            throw $e;
        }
    }
}
