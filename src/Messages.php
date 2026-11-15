<?php
namespace Funseco\Rankings;

use Funseco\Core\Models\Player;

/** Rank texts as XAseco shows them. */
final class Messages
{
    public static function get(string $key, string|int ...$values): string
    {
        $text = format_colors((string) config("rankings.messages.$key", ''));   // values must not expand {#..}
        $map = [];
        foreach ($values as $i => $value) {
            $map['{'.($i + 1).'}'] = (string) $value;
        }

        return strtr($text, $map);
    }

    public static function tell(string $login, string $message): void
    {
        \Server::query()->ChatSendServerMessageToLogin($message, $login);
    }

    /** /rank (and the join / after-race notice). */
    public static function showRank(Player $player): void
    {
        $position = Rank::positionOf($player->id);
        self::tell($player->login, $position
            ? self::get('rank', $position[0], $position[1], Rank::format($position[2]))
            : self::get('rank_none', (int) config('rankings.ranks.min_records', 3)));
    }

    public static function stripColors(?string $text): string
    {
        $text = str_replace('$$', "\0", (string) $text);
        $text = preg_replace('/\$(?:[0-9a-f][^$]{0,2}|[lh]\[[^\]]*\]|[^$]?)/i', '', $text);

        return str_replace("\0", '$', $text);
    }
}
