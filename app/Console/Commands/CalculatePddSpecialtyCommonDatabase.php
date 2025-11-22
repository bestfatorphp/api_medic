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
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;

class CalculatePddSpecialtyCommonDatabase extends Command
{
    use WriteLockTrait;

    protected $signature = 'calculate:pdd_specialty_common_db
                            {--only=all : Что заполняем. Варианты: all, pdd_specialties, verification_status и not_verified_verification_status.}';

    protected $description = 'Расставновка PDD спецмальностей (разовая команда) из файла в common_database, в ограниченной памяти';

    /**
     * Путь к файлу PDD специльностей
     * @var string
     */
    private string $filePathPddSpecialties = 'additional/directory_of_specialties_for_pdd.xlsx';

    /**
     * Путь к файлу по которому вычисляем статус верификации
     * @var string
     */
    private string $filePathDataForVerificationStatus = 'additional/pdd_specialties_for_verification_status.xlsx';

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

        $only = $this->option('only');

        if (!in_array($only, ['all', 'pdd_specialties', 'verification_status', 'not_verified_verification_status'])) {
            $this->error('Передано неверное знаыение only. Может принимать значения: all, pdd_specialties, verification_status');
            return CommandAlias::FAILURE;
        }

        try {
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] Начало расстановки');

            if (in_array($only, ['all', 'pdd_specialties'])) {
                $this->setPddSpecialties();
            }

            if (in_array($only, ['all', 'verification_status'])) {
                $this->setVerificationStatus();
            }

            if (in_array($only, ['all', 'not_verified_verification_status'])) {
                $this->setNotVerifiedVerificationsStatus();
            }

            $this->info("\n[" . Carbon::now()->format('Y-m-d H:i:s') . "] Расстановка завершена");
            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            CustomLog::errorLog(__CLASS__, 'commands', $e);
            $this->error('Ошибка выполнения, смотрите логи: ' . $e->getMessage());
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Заполняем pdd_specialty
     * @throws \Exception
     */
    private function setPddSpecialties(): void
    {
        $this->info("Расставляем pdd_specialty равное ДРУГОЕ");
        $this->withTableLock('common_database', function () {
            CommonDatabase::query()
                ->whereNull('pdd_specialty')
                ->where(function ($query) {
                    $query->whereNull('specialty')
                        ->orWhere('specialty', '=', 'ДРУГОЕ');
                })
                ->update(['pdd_specialty' => 'ДРУГОЕ']);
        });

        $this->info("Получаю ассоциативный массив специальностей...");
        $specialties = $this->importSpecialtiesToAssocArray();

        foreach ($specialties as $specialty => $pdd_specialty) {
            $this->info("Обновляю PDD специальность для специальности {$specialty}, выставляю значение - {$pdd_specialty}");
            $this->withTableLock('common_database', function () use ($specialty, $pdd_specialty) {
                CommonDatabase::query()
                    ->whereNull('pdd_specialty')
                    ->where('specialty', '=', $specialty)
                    ->update(['pdd_specialty' => $pdd_specialty]);
            });
        }

        $specialties = [];
        gc_mem_caches(); //очищаем кэши памяти Zend Engine
    }

    /**
     * Формируем массив специальностей
     * @return array
     */
    private function importSpecialtiesToAssocArray(): array
    {
        $spreadsheet = IOFactory::load(storage_path('app/' . $this->filePathPddSpecialties));
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

    /**
     * Обрабатываем файл напрямую с минимальным использованием памяти
     * @return void
     * @throws \Exception
     */
    private function setVerificationStatus(): void
    {
        $this->info("Расставляем verification_status");

        $filePath = storage_path('app/' . $this->filePathDataForVerificationStatus);

        if (!file_exists($filePath)) {
            throw new \Exception("Файл не найден: {$filePath}");
        }

        $batchSize = 500;
        $commonDBBatch = [];
        $processed = 0;
        $notFound = 0;

        $statusStats = [
            '01_Верифицированный' => 0,
            '02_Полуверифицированный' => 0,
            '03_Неверифицированный' => 0
        ];

        $this->info("Начинаем обработку...");

        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->setShouldFormatDates(false);
        $reader->open($filePath);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                if ($rowIndex === 1) { //пропускаем заголовок
                    continue;
                }

                $cells = $row->getCells();

                if (count($cells) < 3) {
                    continue;
                }

                $fio = trim($cells[0]->getValue() ?? '');
                $city = trim($cells[1]->getValue() ?? '');
                $pddSpecialty = trim($cells[2]->getValue() ?? '');

                if (empty($fio)) {
                    continue;
                }

                /** @var CommonDatabase $item */
                $item = CommonDatabase::query()
                    ->select(['id', 'email', 'full_name', 'city', 'pdd_specialty'])
                    ->where('full_name', '=', $fio)
                    ->first();

                if (is_null($item)) {
                    $notFound++;
                    continue;
                }

                $verificationStatus = $this->calculateVerificationStatus($item, $city, $pddSpecialty);

                $statusStats[$verificationStatus]++;

                $commonDBBatch[] = [
                    'email' => $item->email,
                    'verification_status' => $verificationStatus,
                ];

                $processed++;

                if (count($commonDBBatch) >= $batchSize) {
                    $this->upsertBatchCommonDb($commonDBBatch);
                }

                if ($processed % 500 === 0) {
                    $memory = round(memory_get_usage(true) / 1024 / 1024, 2);
                    $this->info("Найдено и обновлено: {$processed}, Память: {$memory}MB");
                    $this->warn("Не найдено: {$notFound}");
                }
            }
        }

        $reader->close();

        if (!empty($commonDBBatch)) {
            $this->upsertBatchCommonDb($commonDBBatch);
        }

        $this->outputFinalStats($statusStats, $processed, $notFound);
    }

    /**
     * Вычисляет статус верификации
     * @param CommonDatabase $item
     * @param string $fileCity
     * @param string $filePddSpecialty
     * @return string
     */
    private function calculateVerificationStatus(CommonDatabase $item, string $fileCity, string $filePddSpecialty): string
    {
        $itemCity = trim($item->city ?? '');
        $itemPddSpecialty = trim($item->pdd_specialty ?? '');
        $fileCity = trim($fileCity);
        $filePddSpecialty = trim($filePddSpecialty);

        if ($itemCity === $fileCity && $itemPddSpecialty === $filePddSpecialty) {
            return "01_Верифицированный";
        } else if (($itemCity !== $fileCity && $itemPddSpecialty === $filePddSpecialty) ||
            ($itemCity === $fileCity && $itemPddSpecialty !== $filePddSpecialty)) {
            return "02_Полуверифицированный";
        } else {
            return "03_Неверифицированный";
        }
    }

    /**
     * Пакетная вставка статусов верификаций
     * @throws \Exception
     */
    private function upsertBatchCommonDb(array &$commonDBBatch)
    {
        $this->withTableLock('common_database', function () use ($commonDBBatch) {
            CommonDatabase::upsertWithMutators(
                $commonDBBatch,
                ['email'],
                ['verification_status']
            );
        }, true);

        $commonDBBatch = [];
        gc_mem_caches(); //очищаем кэши памяти Zend Engine
    }

    private function outputFinalStats(array $statusStats, int $processed, int $notFound): void
    {
        $totalInFile = $processed + $notFound;

        $this->info("");
        $this->info("ФНИАЛЬНАЯ СТАТИСТИКА:");
        $this->info("Всего записей в файле: {$totalInFile}");
        $this->info("Обработано успешно: {$processed}");
        $this->info("Не найдено: {$notFound}");
        $this->info("");
        $this->info("Статусы верификации:");
        $this->info("- Верфицированные: {$statusStats['01_Верифицированный']}");
        $this->info("- Полуверифицированные: {$statusStats['02_Полуверифицированный']}");
        $this->info("- Неверифицированные: {$statusStats['03_Неверифицированный']}");
    }

    /**
     * Устанавиваем в verification_status для всех оставшихся - 03_Неверифицированный
     * @throws \Exception
     */
    private function setNotVerifiedVerificationsStatus(): void
    {
        $this->warn("Обновляем оставшиеся verification_status как '03_Неверифицированный'");
        $this->withTableLock('common_database', function () {
            CommonDatabase::query()
                ->where(function ($query) {
                    $query->whereNull('verification_status')
                        ->orWhereIn('verification_status', ['verified', 'not_verified']);
                })
                ->update(['verification_status' => '03_Неверифицированный']);
        });
    }
}
