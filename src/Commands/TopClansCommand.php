<?php
namespace Funseco\Rankings\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Rankings\Messages;
use Funseco\Rankings\Rank;

/**
 * /topclans: clans of ranked players (any known player) with at least topclans_minplayers
 * ranked members, by the average of their members' averages. Players without a clan are
 * left out (XAseco grouped them into a blank clan).
 */
class TopClansCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'topclans';
    protected string $description = 'Displays top 10 best ranked clans';

    public function handle(Player $player, array $args): void
    {
        $min = max(1, (int) config('funseco.topclans_minplayers', 2));

        $clans = Rank::query()
            ->join('players', 'players.id', '=', 'ranks.player_id')
            ->whereNotNull('players.team_name')
            ->where('players.team_name', '!=', '')
            ->get(['players.team_name', 'ranks.average'])
            ->groupBy('team_name')
            ->filter(fn ($members) => $members->count() >= $min)
            ->map(fn ($members, $clan) => ['clan' => (string) $clan, 'count' => $members->count(), 'average' => $members->avg('average')])
            ->sortBy([['average', 'asc'], ['clan', 'asc']])
            ->take(10)
            ->values();

        if ($clans->isEmpty()) {
            Messages::tell($player->login, Messages::get('no_clans'));
            return;
        }

        $rows = $clans->map(fn ($c, $i) => [
            ($i + 1).'.',
            '{#black}'.$c['clan'].'$z $n('.$c['count'].')$m',
            Rank::format((float) $c['average']),
        ])->all();

        Window::show($player, 'Current TOP 10 Clans $n(min. '.$min.' players)$m:', $rows, [0.95, 0.1, 0.7, 0.15],
            ['BgRaceScore2', 'Podium'], card: true);
    }
}
