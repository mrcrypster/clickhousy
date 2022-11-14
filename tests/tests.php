<?php

require __DIR__ . '/../../testy/testy.php';
require __DIR__ . '/../clickhousy.php';


clickhousy::query('DROP TABLE _tests');
clickhousy::query('CREATE TABLE _tests (a String) ENGINE = MergeTree ORDER BY a');


class my_clickhousy_exception extends Exception {};
class my_clickhousy extends clickhousy {
  public static function error($msg, $request_info) {
    throw new my_clickhousy_exception($msg);
  }
}


class tests extends testy {
  public static function test_ping() {
    self::assert(
      date('Y-m-d H:i:s'),
      clickhousy::col('SELECT NOW()'),
      'Ping health'
    );
  }

  public static function test_custom_exceptions() {
    $error = false;
    try {
      my_clickhousy::query('SELECT bad_query');
    }
    catch ( my_clickhousy_exception $e ) {
      $error = true;
    }

    self::assert(true, $error, 'Raised exception');
  }

  public static function test_rows() {
    $rows = clickhousy::rows('SELECT number, \'hi\' as hi FROM numbers(5)');

    self::assert(
      '3',
      $rows[3]['number'],
      'Rows fields'
    );

    self::assert(
      'hi',
      $rows[1]['hi'],
      'Rows fields'
    );

    self::assert(
      5,
      count($rows),
      'Rows count'
    );
  }

  public static function test_cols() {
    $cols = clickhousy::cols('SELECT number, \'hi\' as hi FROM numbers(5)');

    self::assert(
      '1',
      $cols[1],
      'Rows fields'
    );

    self::assert(
      5,
      count($cols),
      'Rows count'
    );
  }

  public static function test_row() {
    $row = clickhousy::row('SELECT number, today() as today FROM numbers(1)');
    self::assert(
      2,
      count($row),
      'Row size'
    );

    self::assert(
      date('Y-m-d'),
      $row['today'],
      'Row data'
    );
  }

  public static function test_col() {
    $col = clickhousy::col('SELECT count(*) FROM numbers(10)');
    self::assert(
      '10',
      $col,
      'Col value'
    );
  }

  public static function test_error() {
    $res = clickhousy::query('SELECT FROM');
    self::assert(
      true,
      array_key_exists('error', $res),
      'Error returned'
    );
  }

  public static function test_compression() {
    $data = clickhousy::rows('SELECT * FROM numbers(1000000)');
    $downloaded = clickhousy::last_info()['size_download'];

    $ratio = floor(strlen(json_encode($data)) / $downloaded);

    self::assert(true, $ratio > 5, 'Compressed response');
  }

  public static function test_summary() {
    clickhousy::rows('SELECT * FROM numbers(100000)');
    self::assert('100000', clickhousy::last_summary()['total_rows_to_read'], 'Total read rows');
  }

  public static function test_params() {
    $count = clickhousy::col('SELECT count(*) FROM numbers(100000) WHERE number > {num:UInt32}', ['num' => 50000]);
    self::assert('49999', $count, 'Int param test');

    $val = clickhousy::col('SELECT lower(hex(MD5(toString(number)))) FROM numbers(100000) WHERE lower(hex(MD5(toString(number)))) = {val:String}', ['val' => md5(12345)]);
    self::assert(md5(12345), $val, 'String param test');
  }

  public static function test_read_callback() {
    $total = 0;
    $sum = 0;
    clickhousy::query('SELECT * FROM numbers(100000)', [], null, null, function($packet) use (&$total, &$sum) {
      $total += count($packet);
      foreach ( $packet as $r ) $sum += $r[0];
    });
    
    self::assert(100000, $total, 'Total rows read through callback');
    self::assert(clickhousy::col('SELECT sum(number) FROM numbers(100000)'), "{$sum}", 'Values read through callback');
  }

  public static function test_write() {
    # unnamed rows
    $data = [];
    for ( $i = 1; $i <= 1000; $i++ ) {
      $data[] = [$i, date('Y-m-d H:i:s', time() - $i)];
    }
    clickhousy::query('DROP TABLE IF EXISTS test');
    clickhousy::query('CREATE TABLE test (id UInt32, dt DateTime) Engine = MergeTree ORDER BY id');
    $res = clickhousy::insert('test', $data);
    self::assert(null, $res, 'Inserted unnamed rows');
    $rows = clickhousy::rows('SELECT * FROM test');
    self::assert(1000, count($rows), 'Fetched inserted rows');

    # named rows
    $data = [];
    for ( $i = 1; $i <= 1000; $i++ ) {
      $data[] = ['dt' => date('Y-m-d H:i:s', time() - $i), 'id' => $i];
    }
    clickhousy::query('DROP TABLE IF EXISTS test');
    clickhousy::query('CREATE TABLE test (id UInt32, dt DateTime) Engine = MergeTree ORDER BY id');
    $res = clickhousy::insert('test', $data);
    self::assert(null, $res, 'Inserted named rows');
    $rows = clickhousy::rows('SELECT * FROM test');
    self::assert(1000, count($rows), 'Fetched inserted rows');
  }

  public static function test_progress() {
    $history = [];
    $res = clickhousy::query('SELECT count(*) FROM (SELECT * FROM numbers(50000000) ORDER BY RAND())', [], null, function($progress) use (&$history) {
      echo round(100 * $progress['read_rows']/$progress['total_rows_to_read']) . '%' . "\n";
      $history[] = $progress;
    });

    self::assert('50000000', $res['data'][0]['count()'], 'Rows processed');
    self::assert(true, $history[0]['read_rows'] > 0, 'Progress tracking');
    self::assert(true, count($history) >= 5, 'Progress tracked');
  }
}


tests::run();