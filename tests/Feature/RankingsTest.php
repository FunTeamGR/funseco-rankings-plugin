<?php
namespace Funseco\Rankings\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Funseco\Core\Testing\FakesServer;
use Funseco\Rankings\Tests\TestCase;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Values\Checkpoint;
use Funseco\Core\Servers\TmForever\Values\CheckpointCollection;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\SharedKernel\Events\PlayerFinished;
use Funseco\Core\SharedKernel\FinishHistory;
use Funseco\Core\SharedKernel\RecordBook;
use Funseco\Rankings\RankCalculator;
use Funseco\Rankings\Time;

class RankingsTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private array $players = [];
    private array $maps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        CurrentPlayers::clear();
        foreach (['a' => 'Alice', 'b' => '$f00Bob', 'c' => 'Carol', 'd' => 'Dave'] as $login => $nick) {
            $this->players[$login] = Player::factory()->create(['login' => $login, 'nickname' => $nick]);
            CurrentPlayers::add($this->players[$login]);
        }
        $this->maps = Map::factory()->count(3)->create()->all();
    }

    private function cps(int ...$scores): CheckpointCollection
    {
        return new CheckpointCollection(array_map(fn ($s, $i) => new Checkpoint($s, $i), $scores, array_keys($scores)));
    }

    /** Fake RecordBook: player login => [map index => rank]; counts = number of ranks unless given. */
    private function records(array $ranks, array $counts = []): void
    {
        $byId = [];
        foreach ($ranks as $login => $perMap) {
            foreach ($perMap as $i => $rank) {
                $byId[$this->players[$login]->id][$this->maps[$i]->id] = $rank;
            }
        }
        $countsById = [];
        foreach ($ranks as $login => $perMap) {
            $countsById[$this->players[$login]->id] = $counts[$login] ?? count($perMap);
        }
        $this->app->instance(RecordBook::class, new class($byId, $countsById) implements RecordBook {
            public function __construct(private array $ranks, private array $counts) {}
            public function ranksFor(Player $player): array { return $this->ranks[$player->id] ?? []; }
            public function checkpointsFor(Player $player, Map $map): ?CheckpointCollection { return null; }
            public function checkpointsOfRank(Map $map, int $rank): ?CheckpointCollection { return null; }
            public function recordCounts(int $maxRank = PHP_INT_MAX): array { return $this->counts; }
            public function countOn(Map $map): int { return 0; }
            public function topRanks(array $mapIds, int $limit): array
            {
                return array_filter(array_map(fn ($perMap) => array_filter($perMap, fn ($r, $m) => $r <= $limit && in_array($m, $mapIds), ARRAY_FILTER_USE_BOTH), $this->ranks));
            }
        });
    }

    private function mapIds(): array
    {
        return array_map(fn ($m) => $m->id, $this->maps);
    }

    private function toLogin(string $login): array
    {
        return array_values(array_map(fn ($a) => $a[0], array_filter($this->sent('ChatSendServerMessageToLogin'), fn ($a) => $a[1] === $login)));
    }

    public function test_every_finish_is_stored()
    {
        $map = $this->maps[0];
        event(new PlayerFinished($this->players['a'], $map, 30000, $this->cps(15000, 30000)));
        event(new PlayerFinished($this->players['a'], $map, 29000, $this->cps(14000, 29000)));

        $this->assertSame(2, Time::count());
        $this->assertSame([14000, 29000], Time::orderBy('id', 'desc')->first()->checkpoints);
    }

    public function test_laps_mode_finishes_are_not_stored()
    {
        ServerInfo::setGameMode(3);

        event(new PlayerFinished($this->players['a'], $this->maps[0], 30000, $this->cps(30000)));

        $this->assertSame(0, Time::count());
    }

    public function test_average_rank_like_xaseco()
    {
        config(['rankings.ranks.max_records' => 10, 'rankings.ranks.min_records' => 2]);
        // a: ranks 1, 2, 3 on all three maps        -> (1+2+3)/3             = 2.0
        // b: rank 1 on maps 2 and 3, none on map 1   -> (1+1 + 1×10)/3        = 4.0
        // c: rank 4 on map 1 only, 2 records overall -> (4 + 2×10)/3          = 8.0
        // d: one record                              -> below min_records: unranked
        $this->records(['a' => [1, 2, 3], 'b' => [1 => 1, 2 => 1], 'c' => [0 => 4], 'd' => [0 => 5]], ['c' => 2]);

        app(RankCalculator::class)->recalculate($this->mapIds());

        $this->assertEqualsWithDelta(
            [$this->players['a']->id => 2.0, $this->players['b']->id => 4.0, $this->players['c']->id => 8.0],
            \Funseco\Rankings\Rank::pluck('average', 'player_id')->map(fn ($v) => (float) $v)->all(),
            0.0001,
        );
    }

    public function test_rank_command_and_unranked_message()
    {
        config(['rankings.ranks.max_records' => 10, 'rankings.ranks.min_records' => 2]);
        $this->records(['a' => [1, 2, 3], 'b' => [1 => 1, 2 => 1]]);
        app(RankCalculator::class)->recalculate($this->mapIds());

        Chat::resolve('/rank', 'b');
        Chat::resolve('/rank', 'd');

        $this->assertSame([format_colors('{#server}> {#record}Your server rank is {#rank}2{#record}/{#highlite}2{#record} Average: {#highlite} 4.0')], $this->toLogin('b'));
        $this->assertSame([format_colors('{#server}> {#record}You must have {#highlite}2{#record} Local Records on this server before recieving a rank...')], $this->toLogin('d'));
    }

    public function test_nextrank_shows_the_next_better_player_and_points()
    {
        config(['rankings.ranks.max_records' => 10, 'rankings.ranks.min_records' => 2]);
        $this->records(['a' => [1, 2, 3], 'b' => [1 => 1, 2 => 1]]);
        app(RankCalculator::class)->recalculate($this->mapIds());

        Chat::resolve('/nextrank', 'b');
        Chat::resolve('/nextrank', 'a');

        // diff in averages (4.0 - 2.0) × 3 tracks = 6 rank points
        $this->assertSame([format_colors('{#server}> {#record}The next better ranked player is {#highlite}Alice{#record}: {#highlite}{#rank}1{#record}/{#highlite}2 Avg:  2.0 $n{#record}[{#highlite}-6{#record} RP]')], $this->toLogin('b'));
        $this->assertSame([format_colors('{#server}> {#record}No better ranked player :-)')], $this->toLogin('a'));
    }

    public function test_top10_window_and_no_ranks()
    {
        Chat::resolve('/top10', 'a');
        $this->assertSame([format_colors('{#server}> {#error}No ranked players found!')], $this->toLogin('a'));

        config(['rankings.ranks.max_records' => 10, 'rankings.ranks.min_records' => 2]);
        $this->records(['a' => [1, 2, 3], 'b' => [1 => 1, 2 => 1]]);
        app(RankCalculator::class)->recalculate($this->mapIds());

        Chat::resolve('/top10', 'a');
        $this->assertStringContainsString('Current TOP 10 Players:', $this->window());
        $this->assertStringContainsString('$f00Bob', $this->window());
        $this->assertLessThan(strpos($this->window(), 'Bob'), strpos($this->window(), 'Alice'), 'better average first');

        Chat::resolve('/top100', 'a');
        $this->assertStringContainsString('Current TOP 100 Players:', $this->window());
    }

    public function test_finish_history()
    {
        [$m1, $m2] = $this->maps;
        $a = $this->players['a'];
        foreach ([[$m1, 30000], [$m1, 28000], [$m1, 29000], [$m2, 50000]] as [$map, $score]) {
            event(new PlayerFinished($a, $map, $score, $this->cps($score)));
        }

        $history = app(FinishHistory::class);

        $this->assertEqualsCanonicalizing([$m1->id, $m2->id], $history->finishedMapIds($a));
        $this->assertSame([$m1->id => 28000, $m2->id => 50000], $history->bestScores($a));
        $this->assertSame(28500, $history->averageScore($a, $m1, 2), 'last two: 28000, 29000');
        $this->assertSame(3, $history->finishCount($a, $m1));
        $this->assertSame(0, $history->finishCount($this->players['b'], $m1));
        $this->assertNull($history->averageScore($this->players['b'], $m1, 10));
    }

    public function test_pb_uses_stored_finishes_for_average_and_unranked_best()
    {
        $map = $this->maps[0];
        \Funseco\Core\Services\Cache\CurrentMap::set($map);
        foreach ([31000, 30000] as $score) {
            event(new PlayerFinished($this->players['c'], $map, $score, $this->cps($score)));
        }
        \Funseco\Records\Infrastructure\LocalRecords::set(new \Funseco\Core\SharedKernel\Values\RecordCollection());

        Chat::resolve('/pb', 'c');

        $this->assertSame([format_colors('{#server}> {#record}Personal Best: {#highlite}'.format_score(30000).'{#record}({#rank}$nUNRANKED$m{#record}) Average: {#highlite}'.format_score(30500))], $this->toLogin('c'));
    }

    public function test_track_count_is_known_right_after_startup()   // [RF 4]
    {
        $this->responses['GetChallengeList'] = [['UId' => 'a'], ['UId' => 'b'], ['UId' => 'c']];

        app(\Funseco\Rankings\RankCalculator::class)->loadTrackCount();   // what the StartupEvent listener runs

        $this->assertSame(3, state()->get(\Funseco\Rankings\RankCalculator::TRACK_COUNT));
    }
}
