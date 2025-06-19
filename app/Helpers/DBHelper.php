<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class DBHelper
{
    /**
     * Свой upsert для обновления полей только не null значениями или созданием новой записи
     * @param $model
     * @param array $data
     * @param array $uniqueBy
     * @param array $updateColumns
     */
    public static function upsertWithNullCheck(
        $model,
        array $data,
        array $uniqueBy,
        array $updateColumns
    )
    {
        if (empty($data)) {
            return;
        }
        $table = $model->getTable();
        $columns = array_keys(reset($data));

        //разбиваем на чанки и обрабатываем каждый
        array_map(function ($chunk) use ($table, $columns, $uniqueBy, $updateColumns) {
            //подготавливаем bindings и values
            $bindings = array_merge(...array_map(
                fn($item) => array_map(fn($col) => $item[$col], $columns),
                $chunk
            ));

            $values = implode(', ', array_fill(0, count($chunk),
                '(' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            ));

            //формируем часть UPDATE
            $update = implode(', ', array_map(
                fn($col) => "{$col} = COALESCE(EXCLUDED.{$col}, {$table}.{$col})",
                $updateColumns
            ));

            DB::statement(
                "INSERT INTO {$table} (" . implode(', ', $columns) . ")
             VALUES {$values}
             ON CONFLICT (" . implode(', ', $uniqueBy) . ")
             DO UPDATE SET {$update}",
                $bindings
            );
        }, array_chunk($data, 500));
    }
}
