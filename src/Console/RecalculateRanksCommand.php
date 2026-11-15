<?php
namespace Funseco\Rankings\Console;

use Illuminate\Console\Command;
use Funseco\Core\Models\Map;
use Funseco\Rankings\RankCalculator;

/**
 * Recompute ranks offline (e.g. after an import). Without a server connection the map set is
 * every map in the database; the controller recomputes over the server's maps at each map end.
 */
class RecalculateRanksCommand extends Command
{
    protected $signature = 'funseco:rankings:recalculate';

    protected $description = 'Recalculate server ranks over all known maps';

    public function handle(RankCalculator $calculator): int
    {
        $ranked = $calculator->recalculate(Map::pluck('id')->all());
        $this->info("Ranked $ranked players.");

        return self::SUCCESS;
    }
}
