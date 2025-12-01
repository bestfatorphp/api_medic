<?php

namespace App\Console\Commands;

use App\Models\UserMT;
use Illuminate\Console\Command;

class CheckUsersFromCsv extends Command
{
    protected $signature = 'users:check-csv
                            {--search-by=medtouch_uuid : Поле для поиска (email или medtouch_uuid)}';

    protected $description = 'Проверка пользователей из CSV файла';

    private string $filePath = 'additional/users.csv';

    public function handle()
    {
        $searchBy = $this->option('search-by');

        if (!in_array($searchBy, ['email', 'medtouch_uuid'])) {
            $this->error("Недопустимое значение для --search-by. Допустимые значения: email, medtouch_uuid");
            return self::FAILURE;
        }

        $this->filePath = storage_path('app/' . $this->filePath);

        if (!file_exists($this->filePath)) {
            $this->error("Файл не найден: {$this->filePath}");
            return self::FAILURE;
        }


        $file = fopen($this->filePath, 'r');
        if (!$file) {
            $this->error("Не удалось открыть файл {$this->filePath}");
            return self::FAILURE;
        }

        //пропускаем заголовок
        fgetcsv($file);

        $existingUsers = 0;
        $nonExistingUsers = 0;
        $invalidEmails = 0;
        $processed = 0;
        $missingSearchField = 0;

        $this->info("Начинаем обработку CSV файла...");

        while (($data = fgetcsv($file)) !== false) {
            $processed++;

            $id = $data[0] ?? null;
            $name = $data[1] ?? null;
            $phone = $data[2] ?? null;
            $email = $data[3] ? strtolower($data[3]) : null;
            $medtouchUuid = $data[11] ?? null;

            //проверяем валидность email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalidEmails++;
                $this->warn("Невалидный email: {$email} (ID: {$id})");
            }

            //определяем значение для поиска в зависимости от выбранного поля
            if ($searchBy === 'email') {
                $searchValue = $email;
                $searchFieldName = 'email';
            } else {
                $searchValue = $medtouchUuid;
                $searchFieldName = 'medtouch_uuids';
            }


            //проверяем существование пользователя
            if ($searchFieldName === 'email') {
                $user = UserMT::query()->where($searchFieldName, $searchValue)->first();
            } else {
                $user = UserMT::query()->whereJsonContains($searchFieldName, $searchValue)->first();
            }

            if ($user) {
                $existingUsers++;
            } else {
                $nonExistingUsers++;
                $this->warn("Пользователь не найден: {$id} {$email} {$name} ({$searchFieldName}: {$searchValue})");
            }

            if ($processed % 100 === 0) {
                $this->info("Обработано: {$processed} записей...");
            }
        }

        fclose($file);

        $this->newLine();
        $this->info("=== РЕЗУЛЬТАТЫ ПРОВЕРКИ ===");
        $this->info("Поле поиска: {$searchBy}");
        $this->info("Всего обработано записей: {$processed}");
        $this->info("Существующие пользователи: {$existingUsers}");
        $this->info("Несуществующие пользователи: {$nonExistingUsers}");
        $this->info("Отсутствует поле для поиска: {$missingSearchField}");
        $this->info("Невалидные email: {$invalidEmails}");

        return self::SUCCESS;
    }
}
