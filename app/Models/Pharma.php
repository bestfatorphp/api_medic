<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Фарма
 * Class Pharma
 * @package App\Models
 *
 * @property string        $domain      Домен
 * @property string        $name        Название Фарма
 */
class Pharma extends Model
{
    use HasFactory;

    protected $table = 'pharma';

    protected $primaryKey = 'domain';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'domain',
        'name'
    ];

    protected $with = [
//        'common_database'
    ];

    /**
     * Общая база
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function common_database()
    {
        return $this->hasOne(CommonDatabase::class, 'email', 'domain');
    }
}
