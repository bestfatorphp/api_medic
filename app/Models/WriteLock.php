<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Блокировка/разрешение пакетной вставки
 * Class WriteLock
 * @package App\Models
 *
 * @property string         $table_name             Название таблицы БД
 * @property bool           $is_writing             Флаг активности записи
 * @property Carbon         $locked_at              Время захвата блокировки
 * @property Carbon         $created_at             Время создания
 * @property Carbon         $updated_at             Время обновления
 */
class WriteLock extends Model
{
    protected $table = 'write_locks';

    protected $primaryKey = 'table_name';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'table_name',
        'is_writing',
        'locked_at'
    ];

    protected $casts = [
        'is_writing' => 'boolean',
        'locked_at' => 'datetime'
    ];
}
