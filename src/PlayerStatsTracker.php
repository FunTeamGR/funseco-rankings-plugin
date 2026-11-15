<?php
namespace Funseco\Rankings;

use Illuminate\Support\Facades\DB;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Servers\TmForever\Values\PlayerRankingsCollection;
use Funseco\Core\SharedKernel\Events\PlayerWon;

/**
 * Keeps players.wins and players.time_played up to date (XAseco did both in its core).
 *
 * Win: at the end of a race, the first of the final ranking wins when he is connected, has
 * rank 1 with a finish (best time > 0; Stunts: score > 0) and more than one player is ranked.
 *
 * Play time: each online player has a session start; the elapsed seconds are added at
 * disconnect and at every map end (so a controller stop loses at most one map).
 */
class PlayerStatsTracker
{
    private const STUNTS = 4;

    public function raceEnded(PlayerRankingsCollection $rankings, bool $warmup, bool $restart = false): void
    {
        $this->flushPlaytime();

        if ($warmup || $restart || $rankings->count() < 2) {
            return;
        }
        $first = $rankings->sortBy(fn ($r) => $r->rank)->first();
        $finished = ServerInfo::getGameMode() === self::STUNTS ? $first->score > 0 : $first->best_time > 0;
        $winner = CurrentPlayers::get((string) $first->login);
        if ($first->rank !== 1 || !$finished || !$winner?->exists) {
            return;
        }

        // wins is nullable (XAseco import): COALESCE so NULL counts as 0
        Player::whereKey($winner->id)->update(['wins' => DB::raw('COALESCE(wins, 0) + 1')]);
        $winner->wins = (int) Player::whereKey($winner->id)->value('wins');
        event(new PlayerWon($winner, $winner->wins));
    }

    /** Session starts now; "last seen" is touched (XAseco UpdatedAt at connect). */
    public function connected(Player $player): void
    {
        $this->setStart($player->login, now()->getTimestamp());
        if ($player->exists) {
            $player->touch();
        }
    }

    /** Players already online when the controller starts: their session starts now. */
    public function startup(): void
    {
        foreach (CurrentPlayers::all() as $player) {
            if ($this->startOf($player->login) === null) {
                $this->setStart($player->login, now()->getTimestamp());
            }
        }
    }

    public function disconnected(Player $player): void
    {
        $this->addPlaytime($player);
        state()->forget($this->key($player->login));
    }

    /** Adds the time since the session start of every online player and restarts the session. */
    public function flushPlaytime(): void
    {
        foreach (CurrentPlayers::all() as $player) {
            $this->addPlaytime($player);
            $this->setStart($player->login, now()->getTimestamp());
        }
    }

    private function addPlaytime(Player $player): void
    {
        $start = $this->startOf($player->login);
        $seconds = $start === null ? 0 : now()->getTimestamp() - $start;
        if ($seconds > 0 && $player->exists) {
            Player::whereKey($player->id)->update(['time_played' => DB::raw('COALESCE(time_played, 0) + '.$seconds)]);
            $player->time_played = (int) $player->time_played + $seconds;
        }
    }

    private function startOf(string $login): ?int
    {
        return state()->get($this->key($login));
    }

    private function setStart(string $login, int $time): void
    {
        state()->forever($this->key($login), $time);
    }

    private function key(string $login): string
    {
        return 'rankings:session:'.$login;
    }
}
