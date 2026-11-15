<?php
namespace Funseco\Rankings\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Rankings\Messages;
use Funseco\Rankings\Rank;

/** /ranks: every connected player by server-rank position, unranked last. */
class RanksCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'ranks';
    protected string $description = 'Displays list of online ranks/nicks';

    public function handle(Player $player, array $args): void
    {
        $online = CurrentPlayers::all()->values();
        $positions = Rank::orderBy('average')->orderBy('player_id')->pluck('player_id')
            ->flip()->map(fn ($i) => $i + 1)->all();

        // stable: unranked players keep join order at the end
        $sorted = $online->sortBy(fn (Player $p, int $i) => [$positions[$p->id] ?? PHP_INT_MAX, $i])->values();

        $colorNicks = (bool) config('funseco.lists_colornicks', true);
        $rows = $sorted->map(fn (Player $p) => [
            isset($positions[$p->id]) ? '{#login}'.$positions[$p->id] : '{#login}{#grey}<none>',
            '{#black}'.($colorNicks ? $p->nickname : Messages::stripColors($p->nickname)),
        ])->all();

        $widths = $colorNicks ? [0.8, 0.15, 0.65] : [0.6, 0.15, 0.45];
        Window::show($player, 'Online Ranks ({#login}rank $g/{#nick} nick$g):', $rows, $widths, ['Icons128x128_1', 'Buddies']);
    }
}
