<?php

namespace Stanford\EMA;
/** @var EMA $module */

echo "Hello";

# https://en.wikipedia.org/wiki/Exponential_distribution#Computational_methods

echo "<pre>";
$lambda = 1.0;
$output = [];
$mean = 1/$lambda;
$median = log(2) / $lambda;

$t_start = 8*60;                // 8:00 am
$t_end = 12*60 + 9*60 + 40;     // 9:40 pm

$t_offset = 120;    // 2 hours from previous
$t_offset_max = $t_offset + 240;    // maximum of 4 hours between survey windows

echo "Lambda: $lambda, Mean: $mean, Median: $median" . " \n" ;

function genExpDist($lambda) {
    $r = rand(0,9999999999) / 10000000000;
    $t = (-1 * log($r)) / $lambda;
    return $t;
}

// $l = 100;
// do {
//     $t = genExpDist($lambda);
//     $output[] = $t;
//     $l--;
// } while ($l > 0);

$p = 0;

$maxStartDelayMin = 20;
$deltas = [];
$times = [];

do {
    $o = [];    // output for this person
    $p++;
    $sample = 1;


    // Do first sample
    $o['sample_' . $sample] = $t_start;

    $t_next = $t_start + $t_offset + $maxStartDelayMin;

    do {
        $sample++;
        $rand = genExpDist($lambda);
        $randMinute = min( $rand * 60, $t_offset_max );
        $deltas[] = $randMinute;

        $o['sample_' . $sample . '_rand'] = $randMinute;
        $o['sample_' . $sample] = $t_next + $randMinute;

        $times[] = $t_next + $randMinute;
        $t_next = $t_next + $randMinute + $t_offset + $maxStartDelayMin;
    } while ($t_next < $t_end);

    $output[] = $o;
} while ($p < 100);


echo "</pre><hr>";

// echo "<pre>" . print_r($output,true) . "</pre>";
// echo "<pre>" . arrayToCsv($output) . "</pre>";
// echo "<pre>DELTAS\n" . implode("\n", $deltas) . "</pre>";
// echo "<pre>TIMES\n" . implode("\n",$times) . "</pre>";



// exit();

// echo "<pre>" . implode("\n",$output) . "</pre>";

$windowStart = 8;   //hours
$windowLengthMin = 120; //min
$d2 = [];
$t2 = [];

$windows = [
    [
        "start" => 8,
        "durationMin" => 120,
        "reminderDeltaMin" => [ 5, 10 ],   //min
        "maxStartDelayMin" => 20
    ],
    [
        "start" => 12,
        "durationMin" => 120,
        "reminderDeltaMin" => [ 5, 10 ],
        "maxStartDelayMin" => 20
    ],
    [
        "start" => 16,
        "durationMin" => 120,
        "reminderDeltaMin" => [ 5, 10 ],   //min
        "maxStartDelayMin" => 20
    ],
    [
        "start" => 20,
        "durationMin" => 120,
        "reminderDeltaMin" => [ 5, 10 ],   //min
        "maxStartDelayMin" => 20
    ]
];

$schedule = [];

// Get a window time -- this is a number
$p = 0;
do {
    $p++;
    $i = 0;
    foreach ($windows as $w) {
        $i++;
        $r = rounddown(rand(0, $w['durationMin']));
        $startMin = $w['start'] * 60;
        $start = $startMin + $r;
        $startMaxMin = $start + $w['maxStartDelayMin'];

        $t2[] = $start;
        $d2[] = $r;

        $s = [
            "p" => $p,
            "i" => $i,
            "window" => $w['start'],
            "start_min" => $startMin,
            "rand" => $r,
            "start_time" => $start
        ];

        $reminder = 0;
        foreach ($w['reminderDeltaMin'] as $d) {
            $reminder++;
            $s['reminder_' . $reminder] = $start + $d;
        }

        $s["close_time"] = $startMaxMin;

        $schedule[] = $s;


    }
} while ($p < 100);


echo "<pre>" . print_r($schedule,true) . "</pre>";
echo "<pre>" . arrayToCsv($schedule) . "</pre>";
echo "<pre>DELTAS2\n" . implode("\n", $d2) . "</pre>";
echo "<pre>TIMES2\n" . implode("\n",$t2) . "</pre>";

