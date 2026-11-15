<?php
namespace Funseco\Rankings\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Rankings\Messages;

class RankCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'rank';
    protected string $description = 'Shows your current server rank';

    public function handle(Player $player, array $args): void
    {
        Messages::showRank($player);
    }
}
