<?php
namespace Funseco\Rankings;

use Funseco\Core\SharedKernel\Events\PlayerTimesPurgeRequested;
use Funseco\Core\SharedKernel\Events\RecordsPruneRequested;

/** Deletes stored finish times for /admin delrec and /admin prunerecs. Ranks recompute at the next map end. */
class TimesMaintenance
{
    public function purgePlayer(PlayerTimesPurgeRequested $event): void
    {
        Time::where('map_id', $event->map->id)->where('player_id', $event->player->id)->delete();

        $best = Time::where('map_id', $event->map->id)
            ->whereNotIn('player_id', $event->excludePlayerIds)
            ->orderBy('score')->orderBy('id')
            ->first();

        if ($best) {
            $event->best = ['player_id' => $best->player_id, 'score' => (int) $best->score, 'checkpoints' => array_values((array) $best->checkpoints)];
        }
    }

    public function purgeMap(RecordsPruneRequested $event): void
    {
        Time::where('map_id', $event->map->id)->delete();
    }

    // ---- /dbtools ------------------------------------------------------------------------

    public function purgePlayerData(\Funseco\Core\SharedKernel\Events\PlayerDataPurgeRequested $event): void
    {
        Time::where('player_id', $event->player->id)->delete();
        Rank::where('player_id', $event->player->id)->delete();
    }

    public function purgeMapData(\Funseco\Core\SharedKernel\Events\MapDataPurgeRequested $event): void
    {
        Time::where('map_id', $event->map->id)->delete();
    }

    public function pruneAbove(\Funseco\Core\SharedKernel\Events\TimesPruneRequested $event): void
    {
        Time::where('map_id', $event->map->id)->where('score', '>=', $event->score)->delete();
    }

    public function wipe(\Funseco\Core\SharedKernel\Events\DatabaseWipeRequested $event): void
    {
        Time::query()->delete();
        Rank::query()->delete();
    }
}
