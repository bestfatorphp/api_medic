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

class SetRegionByCityAndRegionCoordsFromFiles extends Command
{
    use WriteLockTrait;

    protected $signature = 'set:regions-by-city-and-region-coords';


    protected $description = 'Заполнение таблицы regions (разовая команда) из файла, в ограниченной памяти';

    /**
     * Пути к файлу по которомым расставляем данные
     * @var string
     */
    private string $filePathRegionCoords = 'additional/region_coords.xlsx';

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
    private function fillRegionsTable()
    {
        $this->info("Заполняем таблицу regions");

        $filePath = storage_path('app/' . $this->filePathRegionCoords);

        if (!file_exists($filePath)) {
            throw new \Exception("Файл не найден: {$filePath}");
        }

        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        $batchSize = 500;
        $batch = [];

        foreach ($worksheet->getRowIterator(2) as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = $cell->getValue();
            }

            if (isset($cells[0]) && isset($cells[1])) {
                $region = trim($cells[0]);

                if ($region === 'region') {
                    continue;
                }

                $coords = trim($cells[1]);

                $batch[] = [
                    'name' => $region,
                    'coords' => $coords
                ];
            }

            if (count($batch) >= $batchSize) {
                $this->insertRegionsBatch($batch);
                $this->info("Пакетная вставка - {$batchSize}");
            }
        }

        $countBatch = count($batch);

        if ($countBatch > 0) {
            $this->insertRegionsBatch($batch);
            $this->info("Пакетная вставка - {$countBatch}");
        }

        $this->info("Таблица regions заполнены");
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
