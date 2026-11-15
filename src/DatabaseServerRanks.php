<?php
namespace Funseco\Rankings;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\ServerRanks;

/** ServerRanks from the ranks table. */
class DatabaseServerRanks implements ServerRanks
{
    public function positionOf(Player $player): ?array
    {
        return $player->id ? Rank::positionOf($player->id) : null;
    }

    public function rankOf(Player $player): ?int
    {
        return $this->positionOf($player)[0] ?? null;
    }

    public function count(): int
    {
        return Rank::count();
    }
}
