<?php

namespace App\Console\Commands;

use App\Logging\CustomLog;
use App\Models\CommonDatabase;
use App\Traits\WriteLockTrait;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportSpecialtiesForPdd extends Command
{
    use WriteLockTrait;

    protected $signature = 'set:pdd_specialty_common_db';

    protected $description = 'Расставновка PDD спецмальностей (разовая команда) из файла в common_database, с обработкой 1M+ записей в ограниченной памяти';

    /**
     * Путь к файлу
     * @var string
     */
    private string $filePath = 'additional/directory_of_specialties_for_pdd.xlsx';

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
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Начало импорта');

            $this->info("Расставляем pdd_specialty равное ДРУГОЕ");
            CommonDatabase::query()
                ->where(function ($query) {
                    $query->whereNull('specialty')
                        ->orWhere('specialty', '=', 'ДРУГОЕ');
                })
                ->update(['pdd_specialty' => 'ДРУГОЕ']);

            $this->info("Получаю ассоциативный массив специальностей...");
            $specialties = $this->importSpecialties();

            foreach ($specialties as $specialty => $pdd_specialty) {
                $this->info("Обновляю PDD специальность для специальности {$specialty}, выставляю значение - {$pdd_specialty}");
                CommonDatabase::query()
                    ->where('specialty', '=', $specialty)
                    ->update(['pdd_specialty' => $pdd_specialty]);
            }

            $this->info("\n[" . Carbon::now()->format('Y-m-d H:i:s') . "] Импорт завершен");
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи: ' . $e->getMessage());
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Формируем массив специальностей
     * @return array
     */
    public function importSpecialties(): array
    {
        $spreadsheet = IOFactory::load(storage_path('app/' . $this->filePath));
        $worksheet = $spreadsheet->getActiveSheet();

        $specialties = [];

        foreach ($worksheet->getRowIterator(2) as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = $cell->getValue();
            }

            //specialty — в столбце A (индекс 0), pdd_specialty — в столбце B (индекс 1)
            if (isset($cells[0]) && isset($cells[1])) {
                $specialty = trim($cells[0]);
                $pddSpecialty = trim($cells[1]);

                if (!empty($specialty) && !in_array($specialty, ["(пусто)", "ДРУГОЕ"])) {
                    $specialties[$specialty] = $pddSpecialty;
                }
            }
        }

        return $specialties;
    }
}
