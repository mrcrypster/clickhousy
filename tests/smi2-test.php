<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../clickhousy.php';

# 1. Clickhousy
$before = memory_get_usage();
$ts = microtime(true);

$rows = clickhousy::rows('SELECT * FROM number(100)');

$results['clickhousy']['mem'] = (memory_get_usage() - $before)/1024;
$results['clickhousy']['time'] = (microtime(true) - $ts) * 1000;

# 2. Smi2 Client
$before = memory_get_usage();
$ts = microtime(true);

$db = new ClickHouseDB\Client(['host' => '127.0.0.1', 'port' => 8123, 'username' => '', 'password' => '']);

$statement = $db->select('SELECT * FROM numbers(100)');
$rows = $statement->rows();

$results['smi2']['mem'] = (memory_get_usage() - $before)/1024;
$results['smi2']['time'] = (microtime(true) - $ts) * 1000;

echo json_encode($results);