<?php
namespace Funseco\Rankings;

use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\FinishHistory;

class DatabaseFinishHistory implements FinishHistory
{
    public function finishedMapIds(Player $player): array
    {
        return Time::where('player_id', $player->id)->distinct()->pluck('map_id')->map(fn ($id) => (int) $id)->all();
    }

    public function bestScores(Player $player): array
    {
        return Time::where('player_id', $player->id)->groupBy('map_id')->selectRaw('map_id, MIN(score) as best')
            ->pluck('best', 'map_id')->map(fn ($s) => (int) $s)->all();
    }

    public function finishCount(Player $player, Map $map): int
    {
        return Time::where('player_id', $player->id)->where('map_id', $map->id)->count();
    }

    public function averageScore(Player $player, Map $map, int $last): ?int
    {
        $scores = Time::where('player_id', $player->id)->where('map_id', $map->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(max(1, $last))->pluck('score');

        return $scores->isEmpty() ? null : (int) floor($scores->avg());
    }
}
