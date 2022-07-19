<?php

$results = [
  'smi2' => ['mem' => [], 'time' => []],
  'clickhousy' => ['mem' => [], 'time' => []],
];


# Run tests
for ( $i = 0; $i < 100; $i++ ) {
  echo '.';
  test($results);
}

echo "\n";

# Print results
$compare = [];
foreach ( $results as $name => $metrics ) {
  echo ucfirst($name) . ': ' . "\n";

  foreach ( $metrics as $m => $vals ) {
    $median = median($vals);
    echo str_pad($m . ':', 20) . str_pad(round($median, 1), 10);
    if ( isset($compare[$m]) ) {
      echo round($compare[$m]/$median, 1) . 'x' . "\t" . 'better';
    }
    echo "\n";
    $compare[$m] = $median;
  }

  echo "\n";
}


function median($numbers) {
  sort($numbers);
  $count = sizeof($numbers);
  $index = floor($count/2);

  if ($count & 1) {
    return $numbers[$index];
  } else {
    return ($numbers[$index-1] + $numbers[$index]) / 2;
  }
}

function test(&$results) {
  $output = shell_exec('php ' . __DIR__ . '/smi2-test.php');
  $data = json_decode(trim($output), 1);
  foreach ( $data as $name => $metrics ) {
    foreach ( $metrics as $m => $v ) {
      $results[$name][$m][] = $v;
    }
  }
}