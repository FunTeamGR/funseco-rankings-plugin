<?php
namespace Funseco\Rankings\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Carbon;
use Funseco\Core\Testing\FakesServer;
use Funseco\Rankings\Tests\TestCase;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Values\PlayerRankingsCollection;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\SharedKernel\Events\PlayerWon;
use Funseco\Core\SharedKernel\PlayerStats;
use Funseco\Rankings\PlayerStatsTracker;

class PlayerStatsTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private Player $a;
    private Player $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        CurrentPlayers::clear();
        $this->a = Player::factory()->create(['login' => 'a', 'nickname' => 'A']);
        $this->b = Player::factory()->create(['login' => 'b', 'nickname' => 'B']);
        CurrentPlayers::add($this->a);
        CurrentPlayers::add($this->b);
        ServerInfo::setGameMode(1);
    }

    private function ranking(array ...$rows): PlayerRankingsCollection
    {
        return new PlayerRankingsCollection(array_map(fn ($r) => (object) $r, $rows));
    }

    private function tracker(): PlayerStatsTracker
    {
        return app(PlayerStatsTracker::class);
    }

    public function test_first_of_the_final_ranking_wins_and_the_event_fires()
    {
        Event::fake([PlayerWon::class]);
        $this->tracker()->raceEnded($this->ranking(
            ['login' => 'a', 'rank' => 1, 'best_time' => 40000, 'score' => 0],
            ['login' => 'b', 'rank' => 2, 'best_time' => 41000, 'score' => 0],
        ), false);

        $this->assertSame(1, $this->a->fresh()->wins);
        Event::assertDispatched(PlayerWon::class, fn ($e) => $e->player->login === 'a' && $e->wins === 1);
    }

    public function test_no_win_on_a_restart()
    {
        Event::fake([PlayerWon::class]);
        $this->tracker()->raceEnded($this->ranking(
            ['login' => 'a', 'rank' => 1, 'best_time' => 40000, 'score' => 0],
            ['login' => 'b', 'rank' => 2, 'best_time' => 41000, 'score' => 0],
        ), false, true);

        Event::assertNotDispatched(PlayerWon::class);
    }

    public function test_no_win_for_a_lone_player_without_time_or_in_warmup()
    {
        Event::fake([PlayerWon::class]);
        $this->tracker()->raceEnded($this->ranking(['login' => 'a', 'rank' => 1, 'best_time' => 40000, 'score' => 0]), false);
        $this->tracker()->raceEnded($this->ranking(
            ['login' => 'a', 'rank' => 1, 'best_time' => 0, 'score' => 0],
            ['login' => 'b', 'rank' => 2, 'best_time' => 0, 'score' => 0],
        ), false);
        $this->tracker()->raceEnded($this->ranking(
            ['login' => 'a', 'rank' => 1, 'best_time' => 1, 'score' => 0],
            ['login' => 'b', 'rank' => 2, 'best_time' => 2, 'score' => 0],
        ), true);

        Event::assertNotDispatched(PlayerWon::class);
        $this->assertTrue(in_array($this->a->fresh()->wins, [0, null], true));
    }

    public function test_stunts_winner_needs_a_score()
    {
        ServerInfo::setGameMode(4);
        $this->tracker()->raceEnded($this->ranking(
            ['login' => 'a', 'rank' => 1, 'best_time' => 0, 'score' => 120],
            ['login' => 'b', 'rank' => 2, 'best_time' => 0, 'score' => 90],
        ), false);

        $this->assertSame(1, $this->a->fresh()->wins);
    }

    public function test_play_time_is_added_at_disconnect_and_at_map_end()
    {
        Carbon::setTestNow('2026-10-27 12:00:00');
        $this->tracker()->connected($this->a);
        $this->tracker()->connected($this->b);

        Carbon::setTestNow('2026-10-27 12:10:00');
        $this->tracker()->disconnected($this->a);
        $this->assertSame(600, (int) $this->a->fresh()->time_played);

        // b is flushed at the end of the map and keeps counting from there
        $this->tracker()->raceEnded($this->ranking(), false);
        $this->assertSame(600, (int) $this->b->fresh()->time_played);
        Carbon::setTestNow('2026-10-27 12:15:00');
        $this->tracker()->disconnected($this->b);
        $this->assertSame(900, (int) $this->b->fresh()->time_played);
        Carbon::setTestNow();
    }

    public function test_startup_starts_sessions_for_players_already_online()
    {
        Carbon::setTestNow('2026-10-27 12:00:00');
        $this->tracker()->startup();
        Carbon::setTestNow('2026-10-27 12:01:00');
        $this->tracker()->disconnected($this->a);

        $this->assertSame(60, (int) $this->a->fresh()->time_played);
        Carbon::setTestNow();
    }

    public function test_stats_contract_lists_best_first_without_zeros()
    {
        $this->a->update(['wins' => 2, 'time_played' => 7200]);
        $this->b->update(['wins' => 5, 'time_played' => 3000]);
        $stats = app(PlayerStats::class);

        $this->assertSame([$this->b->id => 5, $this->a->id => 2], $stats->topWins(10));
        $this->assertSame([$this->a->id => 7200], $stats->topPlaytime(10, 3600));
        $this->assertSame([], $stats->finishCounts(10));
        $this->assertSame([], $stats->topRanks(10));
    }
}
