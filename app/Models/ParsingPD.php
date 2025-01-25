<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Парсинг PD
 * Class ParsingPD
 * @package App\Models
 *
 * @property integer        $id
 * @property integer        $mt_user_id                 ID полльзователя MT
 * @property string         $difference                 Различие
 * @property string         $pd_workplace               Место работы PD
 * @property string         $pd_address_workplace       Адрес места работы PD
 */
class ParsingPD extends Model
{
    use HasFactory;

    protected $table = 'parsing_pd';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * Общая база
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function common_database()
    {
        return $this->hasOne(CommonDatabase::class, 'mt_user_id', 'mt_user_id');
    }
}
