<?php
namespace Funseco\Rankings;

use Illuminate\Database\Eloquent\Model;

/** One finish (XAseco rs_times). */
class Time extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'times';
    protected $guarded = ['id'];
    protected $casts = ['checkpoints' => 'array'];
}
