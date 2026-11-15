<?php
namespace Funseco\Rankings\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Rankings\Messages;
use Funseco\Rankings\Rank;

/** /top10 and /top100: one instance each. */
class TopRanksCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name;

    public function __construct(private int $top)
    {
        $this->name = "top$top";
        $this->description = "Displays top $top best ranked players";
    }

    protected string $description;

    public function handle(Player $player, array $args): void
    {
        $ranks = Rank::with('player')->orderBy('average')->orderBy('player_id')->limit($this->top)->get();
        if ($ranks->isEmpty()) {
            Messages::tell($player->login, Messages::get('no_ranked'));
            return;
        }

        $rows = $ranks->values()->map(fn (Rank $rank, int $i) => [
            ($i + 1).'.',
            '{#black}'.($rank->player->nickname ?? $rank->player->login ?? '?'),
            Rank::format($rank->average),
        ])->all();

        Window::show($player, "Current TOP {$this->top} Players:", $rows, [0.9, 0.1, 0.65, 0.15], ['BgRaceScore2', 'LadderRank']);
    }
}
