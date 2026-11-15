<?php
namespace Funseco\Rankings;

use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;
use Funseco\Core\Models\Map;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerFinished;
use Funseco\Core\SharedKernel\FinishHistory;
use Funseco\Core\SharedKernel\ServerRanks;
use Funseco\Rankings\Commands\RanksCommand;
use Funseco\Rankings\Commands\TopClansCommand;
use Funseco\Rankings\Commands\NextRankCommand;
use Funseco\Rankings\Commands\RankCommand;
use Funseco\Rankings\Commands\TopRanksCommand;
use Funseco\Rankings\Console\RecalculateRanksCommand;

class RankingsServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    private const LAPS = 3;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rankings.php', 'rankings');
        $this->publishPluginConfig(__DIR__.'/..');
        // All read lazily through config() (the listeners read at event time): live.
        $this->app->make(SettingsRegistry::class)->declare('rankings', 'rankings', [
            Setting::int('ranks.max_records', 'Top N placements per map that count', min: 1),
            Setting::int('ranks.min_records', 'Local records needed for a rank', min: 0),
            Setting::bool('ranks.show_on_join', 'Tell rank on join'),
            Setting::bool('ranks.show_after_race', 'Tell rank after every race'),
            Setting::bool('ranks.next_rank_points', '/nextrank shows points needed'),
            Setting::int('times.average_of_last', 'Finishes /pb averages over', min: 1),
        ]);
        $this->app->singleton(FinishHistory::class, DatabaseFinishHistory::class);
        $this->app->singleton(\Funseco\Core\SharedKernel\PlayerStats::class, DatabasePlayerStats::class);
        $this->app->singleton(PlayerStatsTracker::class);
        $this->app->singleton(ServerRanks::class, DatabaseServerRanks::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->commands([RecalculateRanksCommand::class]);

        \Chat::register(RankCommand::class);
        \Chat::register(new TopRanksCommand(10));
        \Chat::register(new TopRanksCommand(100));
        \Chat::register(NextRankCommand::class);
        \Chat::register(RanksCommand::class);
        \Chat::register(TopClansCommand::class);

        // Every finish (XAseco rs_times); lap times in Laps mode are not whole runs.
        Event::listen(PlayerFinished::class, function (PlayerFinished $event) {
            if (ServerInfo::getGameMode() !== self::LAPS) {
                Time::create([
                    'map_id'      => $event->map->id,
                    'player_id'   => $event->player->id,
                    'score'       => $event->score,
                    'checkpoints' => $event->checkpoints->map(fn ($cp) => $cp->score)->values()->all(),
                    'created_at'  => now(),
                ]);
            }
        });

        // Wins and play time (players.wins / players.time_played).
        $stats = fn () => app(PlayerStatsTracker::class);
        Event::listen(StartupEvent::class, fn () => $stats()->startup());
        Event::listen(PlayerConnected::class, fn (PlayerConnected $e) => $stats()->connected($e->player));
        Event::listen(\Funseco\Core\SharedKernel\Events\PlayerDisconnected::class, fn ($e) => $stats()->disconnected($e->player));
        Event::listen(MapEnded::class, fn (MapEnded $e) => $stats()->raceEnded($e->rankings, $e->wasWarmup, $e->restart));
        // /admin delrec, /admin prunerecs
        Event::listen(\Funseco\Core\SharedKernel\Events\PlayerTimesPurgeRequested::class, [TimesMaintenance::class, 'purgePlayer']);
        Event::listen(\Funseco\Core\SharedKernel\Events\RecordsPruneRequested::class, [TimesMaintenance::class, 'purgeMap']);
        // /dbtools
        Event::listen(\Funseco\Core\SharedKernel\Events\PlayerDataPurgeRequested::class, [TimesMaintenance::class, 'purgePlayerData']);
        Event::listen(\Funseco\Core\SharedKernel\Events\MapDataPurgeRequested::class, [TimesMaintenance::class, 'purgeMapData']);
        Event::listen(\Funseco\Core\SharedKernel\Events\TimesPruneRequested::class, [TimesMaintenance::class, 'pruneAbove']);
        Event::listen(\Funseco\Core\SharedKernel\Events\DatabaseWipeRequested::class, [TimesMaintenance::class, 'wipe']);

        Event::listen(PlayerConnected::class, function (PlayerConnected $event) {
            if (config('rankings.ranks.show_on_join')) {
                Messages::showRank($event->player);
            }
        });

        // Ranks over the maps on the server, recomputed after every race.
        // /nextrank points need the track count before the first recompute (XAseco gameinfo->numchall).
        Event::listen(StartupEvent::class, fn () => app(RankCalculator::class)->loadTrackCount());

        Event::listen(MapEnded::class, fn () => \Server::query()->GetChallengeList(5000, 0, function ($result) {
            $uids = array_column($result[0] ?? [], 'UId');
            if (!$uids) {
                return;
            }
            $started = microtime(true);
            $ranked = app(RankCalculator::class)->recalculate(Map::whereIn('uid', $uids)->pluck('id')->all());
            Log::info(sprintf('[Rankings] %d players ranked in %.0fms', $ranked, (microtime(true) - $started) * 1000));

            if (config('rankings.ranks.show_after_race')) {
                foreach (CurrentPlayers::all() as $player) {
                    Messages::showRank($player);
                }
            }
        }));
    }
}
