<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait MutatorsHelper
{

    /**
     * UpdateOrCreate с обработкой мутаторов
     * @param array $uniqueBy
     * @param array|null $updateFields
     * @return static|null
     */
    public static function updateOrCreateWithMutators(array $uniqueBy, ?array $updateFields = null): ?static
    {
        $processed = self::processDataWithMutators([$updateFields]);
        try {
            return static::updateOrCreate($uniqueBy, $processed[0]);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Database error: " . $e->getMessage());
            return null;
        } catch (\PDOException $e) {
            Log::error("PDO error: " . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            Log::error("General error: " . $e->getMessage());
            return null;
        }
    }
    /**
     * Insert с обработкой мутаторов
     * @param array $data
     * @return bool
     */
    public static function insertWithMutators(array $data): bool
    {
        $processed = self::processDataWithMutators($data);
        return static::insert($processed);
    }

    /**
     * InsertOrIgnore с обработкой мутаторов
     * @param array $data
     * @return int
     */
    public static function insertOrIgnoreWithMutators(array $data): int
    {
        $processed = self::processDataWithMutators($data);
        return static::insertOrIgnore($processed);
    }

    /**
     * Upsert с обработкой мутаторов
     * @param array $data
     * @param $uniqueBy
     * @param array|null $updateFields
     * @return int
     */
    public static function upsertWithMutators(array $data, $uniqueBy, ?array $updateFields = null): int {
        $processed = self::processDataWithMutators($data);
        $updateFields = $updateFields ?: array_keys(reset($data));

        return static::upsert($processed, $uniqueBy, $updateFields);
    }

    /**
     * Обрабатываем данные через мутаторы модели
     * @param array $data
     * @return array
     */
    protected static function processDataWithMutators(array $data): array
    {
        return collect($data)
            ->map(function ($item) {
                $model = new static;
                $fillable = $model->getFillable();

                //если fillable не определен, используем все ключи из item
                $fieldsToProcess = empty($fillable) ? array_keys($item) : $fillable;

                $attributes = [];

                foreach ($fieldsToProcess as $field) {
                    if (!array_key_exists($field, $item)) {
                        continue;
                    }

                    //устанавливаем значение через мутатор
                    $model->$field = $item[$field];

                    //получаем обработанное значение
                    $attributes[$field] = $model->getAttributeValue($field);
                }

                //обработка дат из $dates
                if (property_exists($model, 'dates')) {
                    foreach ($model->getDates() as $dateField) {
                        if (isset($attributes[$dateField])) {
                            $attributes[$dateField] = $model->asDateTime($attributes[$dateField]);
                        }
                    }
                }

                return $attributes;
            })
            ->filter() //удаляем полностью пустые записи
            ->values()
            ->toArray();
    }

    /**
     * Проверка нужно ли обновлять поле (проверяем на длину)
     * @param string|null $newValue
     * @param string|null $currentValue
     * @return bool
     */
    protected function shouldUpdateFieldByLength(?string $newValue, ?string $currentValue): bool
    {
        if (empty($newValue)) {
            return false;
        }

        if ($currentValue === null) {
            return true;
        }

        return strlen($newValue) > strlen($currentValue);
    }

    /**
     * Поле будет обновлено, если содержит null значение
     * @param mixed $newValue
     * @param mixed $currentValue
     * @return bool
     */
    protected function shouldUpdateFieldIfNull(mixed $newValue, mixed $currentValue): bool
    {
        if (empty($newValue)) {
            return false;
        }

        if ($currentValue === null) {
            return true;
        }

        return false;
    }

    /**
     * Объединяем значения через запятую с проверкой на уникальность
     * @param string|null $newValue
     * @param string|null $currentValue
     * @return string|null
     */
    protected function mergeCommaSeparatedValues(?string $newValue, ?string $currentValue): ?string
    {
        if (!$newValue) {
            return $currentValue;
        }

        $current = $currentValue ?? '';
        $values = array_map('trim', explode(',', $current));

        if (!in_array($newValue, $values)) {
            $updated = $current === ''
                ? $newValue
                : $current . ',' . $newValue;

            if ($currentValue !== $updated) {
                return $updated;
            }
        }

        return $currentValue;
    }

    /**
     * Строку в верхний регистр (кирилица)
     * @param string|null $newValue
     * @param string|null $currentValue
     * @param bool $update
     * @return string|null
     */
    protected function toUpperCase(?string $newValue, ?string $currentValue, bool $update = true): ?string
    {
        if (!$update && $currentValue) {
            return $currentValue;
        }

        $trimmedValue = trim($newValue ?? '');

        return $trimmedValue !== ''
            ? mb_strtoupper($trimmedValue, 'UTF-8')
            : null;
    }

    /**
     * Общий формат пола
     * @param string|null $newValue
     * @return string|null
     */
    protected function genderCommon(?string $newValue): ?string
    {
        if (!$newValue) {
            return null;
        }

        if (in_array($newValue, ['m', 'M', 'male'])) {
            return 'M';
        }

        if (in_array($newValue, ['f', 'F', 'female'])) {
            return 'F';
        }

        return null;
    }

    /**
     * Приводим номер телефона к формату 7XXXXXXXXXX (11 цифр)
     * @param string|null $newValue
     * @param string|null $currentValue
     * @return string|null
     */
    protected function parsePhone(?string $newValue, ?string $currentValue): ?string
    {
        if (empty($newValue) && empty($currentValue)) {
            return null;
        }

        if ($currentValue && preg_match('/^7\d{10}$/', $currentValue)) {
            return $currentValue;
        }

        $value = preg_replace('/[^\d]/', '', $newValue);

        if (empty($value)) {
            return null;
        }

        if (strlen($value) === 11) {
            if (str_starts_with($value, '8')) {
                return '7' . substr($value, 1);
            } elseif (str_starts_with($value, '7')) {
                return $value;
            }
        } elseif (strlen($value) === 10) {
            return '7' . $value;
        }

        return null;
    }
}
