<?php
namespace Funseco\Rankings;

use Illuminate\Database\Eloquent\Model;
use Funseco\Core\Models\Player;

/** A player's average rank (lower is better). */
class Rank extends Model
{
    public const CREATED_AT = null;

    protected $table = 'ranks';
    protected $primaryKey = 'player_id';
    public $incrementing = false;
    protected $guarded = [];
    protected $casts = ['average' => 'float'];

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    /** Position (1-based) and number of ranked players, or null when unranked. */
    public static function positionOf(int $playerId): ?array
    {
        $mine = static::find($playerId);
        if (!$mine) {
            return null;
        }
        $better = static::where('average', '<', $mine->average)
            ->orWhere(fn ($q) => $q->where('average', $mine->average)->where('player_id', '<', $playerId))
            ->count();

        return [$better + 1, static::count(), $mine->average];
    }

    /** XAseco shows averages as sprintf('%4.1F'). */
    public static function format(float $average): string
    {
        return sprintf('%4.1F', $average);
    }
}
