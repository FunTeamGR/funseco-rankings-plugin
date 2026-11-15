<?php

return [
    'ranks' => [
        // Placements per map that count: being in a map's top N local records.
        'max_records' => 50,
        // Local records a player needs (on any map) before getting a rank.
        'min_records' => 3,
        // Tell players their rank when they join and after every race.
        'show_on_join'    => true,
        'show_after_race' => true,
        // /nextrank also shows the rank points needed to pass the next player.
        'next_rank_points' => true,
    ],

    'times' => [
        // Number of recent finishes /pb averages over.
        'average_of_last' => 10,
    ],

    'messages' => [
        'rank'        => '{#server}> {#record}Your server rank is {#rank}{1}{#record}/{#highlite}{2}{#record} Average: {#highlite}{3}',
        'rank_none'   => '{#server}> {#record}You must have {#highlite}{1}{#record} Local Records on this server before recieving a rank...',
        'nextrank'    => '{#server}> {#record}The next better ranked player is {#highlite}{1}{#record}: {#highlite}{2}',
        'nextrank_rp' => ' $n{#record}[{#highlite}-{1}{#record} RP]',
        'toprank'     => '{#server}> {#record}No better ranked player :-)',
        'no_ranked'   => '{#server}> {#error}No ranked players found!',
        'no_clans'    => '{#server}> {#error}No clan(s) found!',
    ],
];
