<?php
namespace Funseco\Rankings;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\PlayerStats;

/** Lifetime per-player figures: wins and play time (players table), finishes (times), ranks. */
class DatabasePlayerStats implements PlayerStats
{
    public function topWins(int $limit): array
    {
        return Player::where('wins', '>', 0)->orderByDesc('wins')->orderBy('id')->limit($limit)
            ->pluck('wins', 'id')->map(fn ($n) => (int) $n)->all();
    }

    public function topPlaytime(int $limit, int $minSeconds = 0): array
    {
        return Player::where('time_played', '>', max(0, $minSeconds))->orderByDesc('time_played')->orderBy('id')->limit($limit)
            ->pluck('time_played', 'id')->map(fn ($n) => (int) $n)->all();
    }

    public function finishCounts(int $limit): array
    {
        return Time::groupBy('player_id')->selectRaw('player_id, COUNT(*) as finishes')
            ->orderByDesc('finishes')->orderBy('player_id')->limit($limit)
            ->pluck('finishes', 'player_id')->map(fn ($n) => (int) $n)->all();
    }

    public function topRanks(int $limit): array
    {
        return Rank::where('average', '!=', 0)->orderBy('average')->orderBy('player_id')->limit($limit)
            ->pluck('average', 'player_id')->map(fn ($a) => (float) $a)->all();
    }
}
