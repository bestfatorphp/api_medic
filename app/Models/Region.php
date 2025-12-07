<?php

namespace App\Models;

use App\Traits\MutatorsHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Регионы
 * Class Region
 * @package App\Models
 *
 * @property integer        $id
 * @property string         $name           Наименование региона
 * @property string         $coords         Координаты
 */
class Region extends Model
{
    use HasFactory, MutatorsHelper;

    protected $table = 'regions';

    public $timestamps = false;

    protected $guarded = ['id'];

}
