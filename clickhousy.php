<?php

class clickhousy {
  protected static $url = 'http://localhost:8123';
  protected static $db = 'default';
  protected static $last_response = [];
  protected static $last_info = [];
  protected static $last_summary = [];



  /* Settings */

  public static function set_url($url) {
    static::$url = $url;
  }

  public static function set_db($db) {
    static::$db = $db;
  }



  /* Send query and process Clickhouse response */

  public static function query($sql, $params = [], $post_data = null, $progress_callback = null, $read_callback = null) {
    $q = [
      'enable_http_compression' => 1,
      'send_progress_in_http_headers' => 1,
    ];

    foreach ( $params as $k => $v ) {
      $q['param_' . $k] = $v;
    }

    if ( $post_data ) {
      $q['query'] = $sql;
    }

    $endpoint = static::$url . '?' . http_build_query($q);

    $c = curl_init($endpoint);

    $request = static::prepare_request($sql, $post_data, $progress_callback, $read_callback);

    curl_setopt_array($c, $request);

    if ( $read_callback ) {
      curl_exec($c);
    }
    else {
      $res = curl_exec($c);
    }
    
    $info = curl_getinfo($c);
    if ( $info['http_code'] != 200 ) {
      return static::error($res, $info);
    }
    else if ( isset($res) ) {
      $json = json_decode($res, true);
      static::log($json, $info);
      return $json;
    }
  }

  protected static function prepare_request($sql, $post_data, $progress_callback, $read_callback) {
    $request = [
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_POST => 1,
      CURLOPT_POSTFIELDS => $post_data ?: $sql,
      CURLOPT_ENCODING => 'gzip',
      CURLOPT_HTTPHEADER => [
        'X-ClickHouse-Format: ' . ($post_data ? 'TSV' : 'JSON'),
        'X-ClickHouse-Database: ' . static::$db,
        'Accept-Encoding: gzip'
      ],
      CURLOPT_HEADERFUNCTION => function($curl, $header) use ($progress_callback) {
        # echo $header . "\n";

        $dots = strpos($header, ':');
        $header_name = substr($header, 0, $dots);
        
        if ( $header_name == 'X-ClickHouse-Summary' ) {
          static::$last_summary = json_decode(substr($header, $dots + 1), true);
        }
        else if ( $header_name == 'X-ClickHouse-Progress' ) {
          $progress = json_decode(substr($header, $dots + 1), true);
          if ( $progress_callback ) {
            $progress_callback($progress);
          }
        }

        return strlen($header);
      }
    ];

    if ( $read_callback ) {
      $request[CURLOPT_HTTPHEADER][0] = 'X-ClickHouse-Format: TSV';
      # $request[CURLOPT_FILE] = fopen($output_tsv, 'w');

      $request[CURLOPT_WRITEFUNCTION] = function($c, $packet) use ($read_callback) {
        static $part = '';

        $len = strlen($packet);
        $last_char = $packet[$len - 1];
        $lines = explode("\n", $part . $packet);

        if ( $last_char == "\n" ) {
          array_pop($lines);
          $part = '';
        }
        else {
          $part = array_pop($lines);
        }

        $tsv = [];
        foreach ( $lines as $row ) {
          $tsv[] = explode("\t", $row);
        }
        $read_callback($tsv);
        return $len;
      };
    }

    return $request;
  }



  /* Errors and logging */

  public static function error($msg, $request_info) {
    return ['error' => $msg];
  }

  public static function log($res, $info) {
    static::$last_response = $res;
    static::$last_info = $info;
  }

  public static function last_response() {
    return static::$last_response;
  }

  public static function last_info() {
    return static::$last_info;
  }

  public static function last_summary() {
    return static::$last_summary;
  }

  

  /* Ready to use row/col data methods */

  public static function rows($sql, $params = []) {
    $data = static::query($sql, $params);
    if ( !isset($data['data']) ) {
      return [];
    }

    $data = $data['data'];
    $rows = [];

    foreach ( $data as $r ) {
      $rows[] = $r;
    }

    return $rows;
  }

  public static function cols($sql, $params = []) {
    $rows = static::rows($sql, $params);

    if ( !$rows ) {
      return [];
    }

    $cols = [];
    foreach ( $rows as $row ) {
      $cols[] = array_shift($row);
    }

    return $cols;
  }

  public static function row($sql, $params = []) {
    $rows = static::rows($sql, $params);
    return $rows ? $rows[0] : null;
  }

  public static function col($sql, $params = []) {
    $row = static::row($sql, $params);
    return $row ? array_shift($row) : null;
  }



  /* Writes */

  public static function insert($table, $rows) {
    $insert = [];

    $cols = [];
    if ( !is_numeric(key($rows[0])) ) {
      foreach ( array_keys($rows[0]) as $k ) {
        $cols[] = '"' . str_replace('"', '\\"', $k) . '"';
      }
    }
    
    foreach ( $rows as $row ) {
      $row = array_map(function($v) { return str_replace('"', '\\"', $v); }, $row);
      $insert[] = implode("\t", array_values($row));
    }

    return self::query('INSERT INTO ' . $table .
                       ($cols ? '(' . implode(',', $cols) . ')' : '') .
                       ' FORMAT TSV', [], implode("\n", $insert));
  }
}

if ( isset($_ENV['clickhouse']) ) {
  clickhousy::set_url($_ENV['clickhouse']);
}
