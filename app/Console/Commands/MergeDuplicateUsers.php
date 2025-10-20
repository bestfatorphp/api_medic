<?php

namespace App\Console\Commands;

use App\Models\Doctor;
use Illuminate\Console\Command;
use App\Models\CommonDatabase;
use App\Models\UserMT;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use JetBrains\PhpStorm\Pure;

class MergeDuplicateUsers extends Command
{
    /**
     * @var string
     */
    protected $signature = 'users:merge-duplicates
                            {--chunk=500 : Количество записей для обработки за раз}
                            {--dry-run : Показать что будет сделано без реальных изменений}';

    /**
     * @var string
     */
    protected $description = 'Объединить дублирующихся пользователей с одинаковым email но разным регистром';

    /**
     * @return int
     */
    public function handle()
    {
        ini_set('memory_limit', env('COMMANDS_MEMORY_LIMIT', '128') . 'M');
        set_time_limit(0);
        DB::disableQueryLog();

        $dryRun = $this->option('dry-run');
        $chunkSize = (int)$this->option('chunk');

        if ($dryRun) {
            $this->info('РЕЖИМ ПРЕДПРОСМОТРА: Изменения не будут сохранены');
        }

        $this->info('Начинаем объединение дублирующихся пользователей...');

        $this->processCommonDatabase($dryRun, $chunkSize);

        $this->processUserMT($dryRun, $chunkSize);

        $this->processDoctor($dryRun, $chunkSize);

        $this->info('Объединение дубликатов завершено!');

        return 0;
    }

    /**
     * Обработка дубликатов в CommonDatabase
     * @param bool $dryRun
     * @param int $chunkSize
     */
    private function processCommonDatabase(bool $dryRun, int $chunkSize): void
    {
        $this->info('Обрабатываем дубликаты в CommonDatabase...');

        $totalMerged = 0;

        $duplicateEmails = DB::table('common_database')
            ->select(DB::raw('LOWER(email) as lower_email'))
            ->whereNotNull('email')
            ->groupBy(DB::raw('LOWER(email)'))
            ->havingRaw('COUNT(*) > 1')
            ->pluck('lower_email');

        $this->info("Найдено " . $duplicateEmails->count() . " групп дублирующихся email в CommonDatabase");

        $progressBar = $this->output->createProgressBar($duplicateEmails->count());
        $progressBar->start();

        foreach ($duplicateEmails->chunk($chunkSize) as $chunk) {
            foreach ($chunk as $lowerEmail) {
                $mergedCount = $this->mergeCommonDatabaseDuplicates($lowerEmail, $dryRun);
                $totalMerged += $mergedCount;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine();
        $this->info("CommonDatabase: Всего объединено {$totalMerged} дубликатов");
    }

    /**
     * Обработка дубликатов в UserMT
     * @param bool $dryRun
     * @param int $chunkSize
     */
    private function processUserMT(bool $dryRun, int $chunkSize): void
    {
        $this->info('Обрабатываем дубликаты в UserMT...');

        $totalMerged = 0;

        $duplicateEmails = DB::table('users_mt')
            ->select(DB::raw('LOWER(email) as lower_email'))
            ->whereNotNull('email')
            ->groupBy(DB::raw('LOWER(email)'))
            ->havingRaw('COUNT(*) > 1')
            ->pluck('lower_email');

        $this->info("Найдено " . $duplicateEmails->count() . " групп дублирующихся email в UserMT");

        $progressBar = $this->output->createProgressBar($duplicateEmails->count());
        $progressBar->start();

        foreach ($duplicateEmails->chunk($chunkSize) as $chunk) {
            foreach ($chunk as $lowerEmail) {
                $mergedCount = $this->mergeUserMTDuplicates($lowerEmail, $dryRun);
                $totalMerged += $mergedCount;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine();
        $this->info("UserMT: Всего объединено {$totalMerged} дубликатов");
    }

    /**
     * Обработка дубликатов в Doctor
     * @param bool $dryRun
     * @param int $chunkSize
     */
    private function processDoctor(bool $dryRun, int $chunkSize): void
    {
        $this->info('Обрабатываем дубликаты в Doctor...');

        $totalMerged = 0;

        $duplicateEmails = DB::table('doctors')
            ->select(DB::raw('LOWER(email) as lower_email'))
            ->whereNotNull('email')
            ->groupBy(DB::raw('LOWER(email)'))
            ->havingRaw('COUNT(*) > 1')
            ->pluck('lower_email');

        $this->info("Найдено " . $duplicateEmails->count() . " групп дублирующихся email в Doctor");

        $progressBar = $this->output->createProgressBar($duplicateEmails->count());
        $progressBar->start();

        foreach ($duplicateEmails->chunk($chunkSize) as $chunk) {
            foreach ($chunk as $lowerEmail) {
                $mergedCount = $this->mergeDoctorDuplicates($lowerEmail, $dryRun);
                $totalMerged += $mergedCount;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine();
        $this->info("Doctor: Всего объединено {$totalMerged} дубликатов");
    }

    /**
     * Объединение дубликатов в CommonDatabase для конкретного email
     * @param string $lowerEmail
     * @param bool $dryRun
     * @return int
     */
    private function mergeCommonDatabaseDuplicates(string $lowerEmail, bool $dryRun): int
    {
        $duplicates = CommonDatabase::query()
            ->whereRaw('LOWER(email) = ?', [$lowerEmail])
            ->get();

        if ($duplicates->count() <= 1) {
            return 0;
        }

        $masterData = $this->createMasterCommonDatabaseData($duplicates);

        if (!$dryRun) {
            return DB::transaction(function () use ($duplicates, $masterData, $lowerEmail) {
                $deletedCount = CommonDatabase::whereRaw('LOWER(email) = ?', [$lowerEmail])->delete();

                CommonDatabase::create($masterData);

                return $deletedCount - 1; // -1 потому что создаем одну новую запись вместо нескольких
            });
        } else {
            $this->info("РЕЖИМ ПРЕДПРОСМОТРА: Будет объединено {$duplicates->count()} записей для email: {$lowerEmail}");
            $this->table(
                ['Поле', 'Значение'],
                $this->getMasterRecordPreview($masterData)
            );
            return $duplicates->count() - 1;
        }
    }

    /**
     * Объединение дубликатов в UserMT для конкретного email
     * @param string $lowerEmail
     * @param bool $dryRun
     * @return int
     */
    private function mergeUserMTDuplicates(string $lowerEmail, bool $dryRun): int
    {
        $duplicates = UserMT::query()
            ->whereRaw('LOWER(email) = ?', [$lowerEmail])
            ->get();

        if ($duplicates->count() <= 1) {
            return 0;
        }

        $masterData = $this->createMasterUserMTData($duplicates);

        if (!$dryRun) {
            return DB::transaction(function () use ($duplicates, $masterData, $lowerEmail) {
                $deletedCount = UserMT::whereRaw('LOWER(email) = ?', [$lowerEmail])->delete();

                UserMT::create($masterData);

                return $deletedCount - 1; // -1 потому что создаем одну новую запись вместо нескольких
            });
        } else {
            $this->info("РЕЖИМ ПРЕДПРОСМОТРА: Будет объединено {$duplicates->count()} записей для email: {$lowerEmail}");
            $this->table(
                ['Поле', 'Значение'],
                $this->getMasterRecordPreview($masterData)
            );
            return $duplicates->count() - 1;
        }
    }

    /**
     * Объединение дубликатов в Doctor для конкретного email
     * @param string $lowerEmail
     * @param bool $dryRun
     * @return int
     */
    private function mergeDoctorDuplicates(string $lowerEmail, bool $dryRun): int
    {
        $duplicates = Doctor::query()
            ->whereRaw('LOWER(email) = ?', [$lowerEmail])
            ->get();

        if ($duplicates->count() <= 1) {
            return 0;
        }

        $masterData = $this->createMasterDoctorData($duplicates);

        if (!$dryRun) {
            return DB::transaction(function () use ($duplicates, $masterData, $lowerEmail) {
                $deletedCount = Doctor::whereRaw('LOWER(email) = ?', [$lowerEmail])->delete();

                Doctor::create($masterData);

                return $deletedCount - 1; // -1 потому что создаем одну новую запись вместо нескольких
            });
        } else {
            $this->info("РЕЖИМ ПРЕДПРОСМОТРА: Будет объединено {$duplicates->count()} записей для email: {$lowerEmail}");
            $this->table(
                ['Поле', 'Значение'],
                $this->getMasterRecordPreview($masterData)
            );
            return $duplicates->count() - 1;
        }
    }

    /**
     * Создание данных основной записи из дубликатов для CommonDatabase
     * @param Collection $duplicates
     * @return array
     */
    private function createMasterCommonDatabaseData(Collection $duplicates): array
    {
        $masterData = [
            'email' => strtolower($duplicates->first()->email),
        ];

        $fields = [
            'new_mt_id', 'old_mt_id', 'full_name', 'city', 'region', 'country',
            'specialty', 'interests', 'phone', 'mt_user_id', 'registration_date',
            'gender', 'birth_date', 'registration_website', 'acquisition_tool',
            'acquisition_method', 'username', 'specialization', 'planned_actions',
            'resulting_actions', 'verification_status', 'pharma', 'email_status',
            'category', 'source', 'last_login'
        ];

        foreach ($fields as $field) {
            $masterData[$field] = $this->mergeFieldValue($duplicates, $field);
        }

        return $masterData;
    }

    /**
     * Создание данных основной записи из дубликатов для UserMT
     * @param Collection $duplicates
     * @return array
     */
    private function createMasterUserMTData(Collection $duplicates): array
    {
        $masterData = [
            'email' => strtolower($duplicates->first()->email),
        ];

        $fields = [
            'new_mt_id', 'old_mt_id', 'full_name', 'gender', 'birth_date',
            'specialty', 'interests', 'phone', 'place_of_employment', 'registration_date',
            'country', 'region', 'city', 'registration_website', 'acquisition_tool',
            'acquisition_method', 'uf_utm_term', 'uf_utm_campaign', 'uf_utm_content',
            'last_login'
        ];

        foreach ($fields as $field) {
            $masterData[$field] = $this->mergeFieldValue($duplicates, $field);
        }

        return $masterData;
    }

    /**
     * Создание данных основной записи из дубликатов для Doctor
     * @param Collection $duplicates
     * @return array
     */
    private function createMasterDoctorData(Collection $duplicates): array
    {
        $masterData = [
            'email' => strtolower($duplicates->first()->email),
        ];

        $fields = [
            'full_name', 'city', 'region', 'country', 'specialty', 'interests', 'phone'
        ];

        foreach ($fields as $field) {
            $masterData[$field] = $this->mergeFieldValue($duplicates, $field);
        }

        return $masterData;
    }

    /**
     * Объединение значений полей из дубликатов - берем первое непустое значение
     * @param Collection $duplicates
     * @param string $field
     * @return mixed
     */
    #[Pure]
    private function mergeFieldValue(Collection $duplicates, string $field): mixed
    {
        foreach ($duplicates as $duplicate) {
            $value = $duplicate->$field;

            if ($this->isValidValue($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Проверка валидности значения (не пустое, не null)
     * @param mixed $value
     * @return bool
     */
    private function isValidValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_numeric($value) && $value == 0) {
            return true;
        }

        return true;
    }

    /**
     * Получение предпросмотра основной записи для режима предпросмотра
     * @param array $recordData
     * @return array
     */
    #[Pure]
    private function getMasterRecordPreview(array $recordData): array
    {
        $preview = [];
        foreach ($recordData as $field => $value) {
            if ($value !== null && $value !== '') {
                $preview[] = [$field, $this->truncateValue($value)];
            }
        }
        return $preview;
    }

    /**
     * Обрезка длинных значений для отображения
     * @param $value
     * @param int $length
     * @return string
     */
    private function truncateValue($value, $length = 50): string
    {
        $stringValue = (string)$value;
        if (strlen($stringValue) > $length) {
            return substr($stringValue, 0, $length) . '...';
        }
        return $stringValue;
    }
}
