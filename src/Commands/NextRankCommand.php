<?php
namespace Funseco\Rankings\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Rankings\Messages;
use Funseco\Rankings\Rank;
use Funseco\Rankings\RankCalculator;

class NextRankCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'nextrank';
    protected string $description = 'Shows the next better ranked player';

    public function handle(Player $player, array $args): void
    {
        $mine = Rank::find($player->id);
        if (!$mine) {
            Messages::tell($player->login, Messages::get('rank_none', (int) config('rankings.ranks.min_records', 3)));
            return;
        }

        $next = Rank::with('player')->where('average', '<', $mine->average)->orderByDesc('average')->first();
        if (!$next) {
            Messages::tell($player->login, Messages::get('toprank'));
            return;
        }

        [$position, $total, $average] = Rank::positionOf($next->player_id);
        $message = Messages::get('nextrank', Messages::stripColors($next->player->nickname),
            format_colors("{#rank}$position{#record}/{#highlite}$total Avg: ".Rank::format($average)));
        if (config('rankings.ranks.next_rank_points', true)) {
            $points = ceil(($mine->average - $next->average) * (int) state()->get(RankCalculator::TRACK_COUNT, 0));
            $message .= Messages::get('nextrank_rp', (int) $points);
        }

        Messages::tell($player->login, $message);
    }
}
