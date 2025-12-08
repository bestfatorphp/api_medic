<?php

namespace App\Console\Commands;

use App\Logging\CustomLog;
use App\Models\Region;
use App\Traits\WriteLockTrait;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SetRegionCoordsFromFiles extends Command
{
    use WriteLockTrait;

    protected $signature = 'set:region-coords';


    protected $description = 'Заполнение таблицы regions (разовая команда) из файла, в ограниченной памяти';

    /**
     * Пути к файлу по которомым расставляем данные
     * @var string
     */
    private string $filePathRegionCoords = 'additional/region_coords.csv';

    /**
     * Размер пакета
     * @var int
     */
    private int $batchSize;


    public function handle(): int
    {
        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M');
        set_time_limit(0);
        DB::disableQueryLog();

        try {
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Начало расстановки');

            $this->fillRegionsTable();

            $this->info("\n[" . Carbon::now()->format('Y-m-d H:i:s') . "] Расстановка завершена");
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи: ' . $e->getMessage());
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Заполняем таблицу регионов
     * @throws \Exception
     */
    private function fillRegionsTable(): void
    {
        $this->info("Заполняем таблицу regions");

        $filePath = storage_path('app/' . $this->filePathRegionCoords);

        if (!file_exists($filePath)) {
            throw new \Exception("Файл не найден: {$filePath}");
        }

        $batchSize = 500;
        $batch = [];
        $rowCount = 0;
        $processedCount = 0;

        if (($handle = fopen($filePath, 'r')) !== false) {
            $delimiter = ';';

            $header = fgetcsv($handle, 0, $delimiter);

            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowCount++;

                $region = trim(mb_strtoupper($data[1]));
                $coords = $data[2];

                if ($region === 'region' || $region === 'регион') {
                    continue;
                }

                if (empty($region)) {
                    continue;
                }

                $batch[] = [
                    'name' => $region,
                    'coords' => $coords
                ];
                $processedCount++;

                if (count($batch) >= $batchSize) {
                    $this->insertRegionsBatch($batch);
                    $this->info("Пакетная вставка - {$batchSize} (всего обработано: {$rowCount})");
                }
            }

            fclose($handle);

            if (count($batch) > 0) {
                $this->insertRegionsBatch($batch);
                $this->info("Финальная пакетная вставка - " . count($batch) . " (всего обработано: {$rowCount})");
            }
        } else {
            throw new \Exception("Не удалось открыть файл: {$filePath}");
        }

        $this->info("Таблица regions заполнена. Всего строк в файле: {$rowCount}, обработано: {$processedCount}");
    }


    /**
     * Пакетная вставка
     * @param array $batch
     */
    private function insertRegionsBatch(array &$batch)
    {
        Region::upsert(
            $batch,
            ['name'],
            ['coords']
        );

        $batch = [];
        gc_mem_caches();
    }
}
