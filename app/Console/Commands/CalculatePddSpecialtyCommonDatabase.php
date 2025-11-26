<?php

namespace App\Console\Commands;

use App\Logging\CustomLog;
use App\Models\CommonDatabase;
use App\Traits\WriteLockTrait;
use Box\Spout\Common\Exception\IOException;
use Box\Spout\Reader\Exception\ReaderNotOpenedException;
use Illuminate\Console\Command;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;

class CalculatePddSpecialtyCommonDatabase extends Command
{
    use WriteLockTrait;

    protected $signature = 'calculate:pdd_specialty_common_db
                            {--only=all : Что заполняем. Варианты: all, pdd_specialties, verification_status и not_verified_verification_status.}
                            {--createTempTableAndFill : Создать временную талицу и заполнить из файла для расстановки verification_status.}
                            {--fillTempTable : Только заполнить (без создания таблицы), временную таблицу из файла для расстановки verification_status.}
                            {--continueFurther : Продолжить выполнение, без обновления данных, во временной таблицы.}';


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

    /**
     * Название временной таблицы данных из файла
     * @var string
     */
    private string $tempTableName;

    /**
     * Создать временную талицу и заполнить из файла для расстановки verification_status
     * @var mixed
     */
    private mixed $createTempTableAndFill;

    /**
     * Заполнить, при локальной разработке, временную таблицу из файла для расстановки verification_status
     * @var mixed
     */
    private mixed $fillTempTable;

    /**
     * Продолжить выполнение, без обновления данных, во временной таблицы
     * @var mixed
     */
    private mixed $continueFurther;


    public function handle(): int
    {
        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M');
        set_time_limit(0);
        DB::disableQueryLog();

        $only = $this->option('only');
        $this->createTempTableAndFill = $this->option('createTempTableAndFill');
        $this->fillTempTable = $this->option('fillTempTable');
        $this->continueFurther = $this->option('continueFurther');

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
     * Обрабатываем файл через временную таблицу, с минимальным использованием памяти
     * @return void
     * @throws \Exception
     */
    private function setVerificationStatus(): void
    {
        $this->info("Расставляем verification_status");

        $isProd = config('app.env') === 'production';
        $this->tempTableName = 'verification_statuses_temp';

        $filePath = storage_path('app/' . $this->filePathDataForVerificationStatus);

        if (!file_exists($filePath)) {
            throw new \Exception("Файл не найден: {$filePath}");
        }

        $batchSize = 250;
        $commonDBBatch = [];
        $processed = 0;
        $notFound = 0;
        $statusStats = [
            '01_Верифицированный' => 0,
            '02_Полуверифицированный' => 0,
            '03_Неверифицированный' => 0
        ];

        $this->info("Начинаем обработку...");

        //создаём временную таблицу
        //todo: при первом запуске - createTempTableAndFill (убрать при следующем запуске команды!!!)
        //todo: если обновили файл - fillTempTable, после заполнения таблицы, убрать при следующем запуске команды!!!
        if ($this->createTempTableAndFill) {
            $this->createTempTable();
        }

        //заполняем временную таблицу данными из файла
        //todo: при первом старте - createTempTableAndFill, если заменили файл - fillTempTable !!!
        if($this->createTempTableAndFill || $this->fillTempTable) {
            $this->fillTempTableFromFile($filePath);
        }

        $this->info("Начинаем обновление статусов верификации...");

        //обрабатываем данные пока временная таблица не пуста
        while (true) {
            //получаем фио из первой, не обработанной, записи временной таблицы
            $firstRecord = DB::table($this->tempTableName)
                ->select('fio')
                ->orderBy('id')
                ->first();

            if (!$firstRecord) {
                break;
            }

            $fio = $firstRecord->fio;

            //находим все, не обработанные, записи, из временной таблицы, по фио первой записи
            $tempRecords = DB::table($this->tempTableName)
                ->where('fio', $fio)
                ->get()
                ->toArray();

            //и по этой же фио, находим пользователей из common_database
            $commonDbUsers = CommonDatabase::query()
                ->select(['id', 'email', 'full_name', 'city', 'pdd_specialty'])
                ->where('full_name', '=', $fio)
                ->get();

            if ($commonDbUsers->isEmpty()) {
                $notFound += count($tempRecords);
            } else {
                if ($commonDbUsers->count() > 1) {
                    //если пользователей, в коллекции из common_database, несколько
                    foreach ($commonDbUsers as $user) {
                        $this->addToCommonDBBatch($user, $tempRecords,$commonDBBatch,$statusStats,$processed);
                    }
                } else {
                    //если в коллекции из common_database один пользователь
                    $user = $commonDbUsers->first();
                    $this->addToCommonDBBatch($user, $tempRecords,$commonDBBatch,$statusStats,$processed);
                }
            }

            if (count($commonDBBatch) >= $batchSize) {
                $this->upsertBatchCommonDb($commonDBBatch);
                $memory = round(memory_get_usage(true) / 1024 / 1024, 2);
                $this->info("Найдено и обновлено: {$processed}, Память: {$memory}MB");
                $this->warn("Не найдено: {$notFound}");
            }

            //после всех действий, удаляем все записи временной таблицы, находящиеся в коллекции
            DB::table($this->tempTableName)->where('fio', $fio)->delete();
        }

        if (!empty($commonDBBatch)) {
            $this->upsertBatchCommonDb($commonDBBatch);
        }
//        if ($isProd) {
//            $this->dropTempTable();
//        }

        $this->outputFinalStats($statusStats, $processed, $notFound);
    }


    /**
     * Добавляем в пакет данные юзера + считаем статистику
     * @param CommonDatabase $user
     * @param array $tempRecords
     * @param $commonDBBatch
     * @param $statusStats
     * @param $processed
     */
    private function addToCommonDBBatch(CommonDatabase $user, array $tempRecords, &$commonDBBatch, &$statusStats, &$processed)
    {
        $bestMatchStatus = $this->findBestMatchStatus($user, $tempRecords);

        if ($bestMatchStatus) {
            $commonDBBatch[] = [
                'email' => $user->email,
                'verification_status' => $bestMatchStatus
            ];
            $statusStats[$bestMatchStatus]++;
            $processed++;
        }
    }

    /**
     * Создаем временную таблицу
     */
    private function createTempTable(): void
    {
        $this->dropTempTable(true);

        DB::statement("
            CREATE TABLE {$this->tempTableName} (
                id SERIAL PRIMARY KEY,
                fio VARCHAR(255) NOT NULL,
                city VARCHAR(255),
                pdd_specialty VARCHAR(255)
            )
        ");

        DB::statement("CREATE INDEX ON {$this->tempTableName} (fio)");

        $this->info("Создана временная таблица {$this->tempTableName}");
    }

    /**
     * Заполняем временную таблицу данными из файла
     * @param string $filePath
     * @throws IOException
     * @throws ReaderNotOpenedException
     */
    private function fillTempTableFromFile(string $filePath): void
    {
        $this->info("Заполняем временную таблицу данными из файла...");

        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->setShouldFormatDates(false);
        $reader->open($filePath);

        $batchData = [];
        $batchSize = 1000;

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

                $batchData[] = [
                    'fio' => $fio,
                    'city' => $city,
                    'pdd_specialty' => $pddSpecialty
                ];

                if (count($batchData) >= $batchSize) {
                    $this->insertBatchToTempTable($batchData);
                }
            }
        }

        if (!empty($batchData)) {
            $this->insertBatchToTempTable($batchData);
        }

        $reader->close();
        $this->info("Временная таблица заполнена данными из файла");
    }

    /**
     * Вставляем пакет, из файла, во временную таблицу
     * @param array $batchData
     */
    private function insertBatchToTempTable(array &$batchData): void
    {
        $placeholders = [];
        $bindings = [];

        foreach ($batchData as $data) {
            $placeholders[] = "(?, ?, ?)";
            $bindings[] = $data['fio'];
            $bindings[] = $data['city'];
            $bindings[] = $data['pdd_specialty'];
        }

        $sql = "INSERT INTO {$this->tempTableName} (fio, city, pdd_specialty) VALUES " . implode(', ', $placeholders);
        DB::insert($sql, $bindings);
        $batchData = [];
        gc_mem_caches(); //очищаем кэши памяти Zend Engine
    }

    /**
     * Находим наилучший совпадающий статус для пользователя
     * @param CommonDatabase $user
     * @param array $tempRecords
     * @return string|null
     */
    #[Pure]
    private function findBestMatchStatus(CommonDatabase $user, array $tempRecords): ?string
    {
        if (empty($tempRecords)) {
            return null;
        }

        //собираем все возможные статусы
        $allStatuses = [];
        foreach ($tempRecords as $tempRecord) {
            $allStatuses[] = $this->calculateVerificationStatus($user, $tempRecord->city, $tempRecord->pdd_specialty);
        }

        $bestStatus = '03_Неверифицированный';

        if (in_array('01_Верифицированный', $allStatuses)) {
            $bestStatus = '01_Верифицированный';
        } elseif (in_array('02_Полуверифицированный', $allStatuses)) {
            $bestStatus = '02_Полуверифицированный';
        }

        return $bestStatus;
    }

    /**
     * Вычисляем статус верификации
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
        } elseif (($itemCity !== $fileCity && $itemPddSpecialty === $filePddSpecialty) ||
            ($itemCity === $fileCity && $itemPddSpecialty !== $filePddSpecialty)) {
            return "02_Полуверифицированный";
        } else {
            return "03_Неверифицированный";
        }
    }

    /**
     * Пакетная вставка статусов верификаций
     * @param array $commonDBBatch
     * @throws \Exception
     */
    private function upsertBatchCommonDb(array &$commonDBBatch)
    {
        $result = $this->withTableLock('common_database', function () use ($commonDBBatch) {
            return CommonDatabase::upsert(
                $commonDBBatch,
                ['email'],
                ['verification_status']
            );
        }, true);

        if ($result instanceof \Exception) {
            $this->error("Ошибка записи в common_database: " . $result->getMessage());
        }

        $commonDBBatch = [];
        gc_mem_caches(); //очищаем кэши памяти Zend Engine
    }

    /**
     * Удаляем временную таблицу
     * @param bool $isCreateTable
     */
    private function dropTempTable(bool $isCreateTable = false): void
    {
        DB::statement("DROP TABLE IF EXISTS {$this->tempTableName}");

        if (!$isCreateTable) $this->info("Временная таблица удалена");
    }

    /**
     * Общая статистика
     * @param array $statusStats
     * @param int $processed
     * @param int $notFound
     */
    private function outputFinalStats(array $statusStats, int $processed, int $notFound): void
    {
        $totalInFile = $processed + $notFound;

        $this->info("");
        $this->info("ФИНАЛЬНАЯ СТАТИСТИКА:");
        $this->info("Всего записей в файле: {$totalInFile}");
        $this->info("Обработано успешно: {$processed}");
        $this->info("Не найдено: {$notFound}");
        $this->info("");
        $this->info("Статусы верификации:");
        $this->info("- Верифицированные: {$statusStats['01_Верифицированный']}");
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
