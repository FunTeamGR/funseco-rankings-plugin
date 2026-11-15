<?php
namespace Funseco\Rankings;

use Illuminate\Support\Facades\DB;
use Funseco\Core\SharedKernel\RecordBook;

/**
 * XAseco's server rank: for players with at least min_records local records,
 *   average = (sum of their placements in the top max_records of each map
 *              + max_records for every map where they are not in that top)
 *             / number of maps
 * over the maps on the server. Lower is better.
 */
class RankCalculator
{
    public const TRACK_COUNT = 'rankings:trackCount';

    public function __construct(private RecordBook $records)
    {
    }

    /** Number of tracks on the server, for /nextrank before the first recompute. */
    public function loadTrackCount(): void
    {
        \Server::query()->GetChallengeList(5000, 0, function ($result) {
            state()->forever(self::TRACK_COUNT, count($result[0] ?? []));
        });
    }

    /** @param int[] $mapIds the maps on the server */
    public function recalculate(array $mapIds): int
    {
        $total = count($mapIds);
        $max   = (int) config('rankings.ranks.max_records', 50);
        $min   = (int) config('rankings.ranks.min_records', 3);
        state()->forever(self::TRACK_COUNT, $total);

        // XAseco: min_records records within the top max_records of any map
        $eligible = array_keys(array_filter($this->records->recordCounts($max), fn ($count) => $count >= $min));
        $top      = $total ? $this->records->topRanks($mapIds, $max) : [];
        $now      = now();

        $rows = [];
        foreach ($eligible as $playerId) {
            $placements = $top[$playerId] ?? [];
            $average = $total ? (array_sum($placements) + ($total - count($placements)) * $max) / $total : 0;
            $rows[] = ['player_id' => $playerId, 'average' => $average, 'updated_at' => $now];
        }

        DB::transaction(function () use ($rows) {
            Rank::query()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                Rank::insert($chunk);
            }
        });

        return count($rows);
    }
}
