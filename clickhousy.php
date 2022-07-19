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

  public static function query($sql, $params = [], $post_buffer = null, $progress_callback = null, $read_callback = null) {
    $q = [
      'enable_http_compression' => 1,
      'send_progress_in_http_headers' => 1,
    ];

    if ( $post_buffer ) {
      $q['query'] = $sql;
    }

    foreach ( $params as $k => $v ) {
      $q['param_' . $k] = $v;
    }

    $endpoint = static::$url . '?' . http_build_query($q);


    if ( $post_buffer ) {
      # unfortunately, we have to call shell curl, cause PHP can't send binary
      # data from files without loading it into memory (which is stupid in our case)

      $cmd = 'curl -v -q ' . escapeshellarg($endpoint) .
             ' -H "X-ClickHouse-Database: ' . static::$db . '" ' .
             ' -H "Content-Encoding: gzip" ' .
             ' --data-binary @' . $post_buffer . ' 2>&1';
      $out = shell_exec($cmd);
      if ( preg_match('/X-ClickHouse-Summary: (.+?)\\n/misu', $out, $m) ) {
        return json_decode($m[1], 1);
      }
      else {
        # looks like error
        echo 'ERROR: ' . $out;
      }
    }


    $c = curl_init($endpoint);

    $request = static::prepare_request($sql, $progress_callback, $read_callback);

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

  protected static function prepare_request($sql, $progress_callback, $read_callback) {
    $request = [
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_POST => 1,
      CURLOPT_POSTFIELDS => $sql,
      CURLOPT_ENCODING => 'gzip',
      CURLOPT_HTTPHEADER => [
        'X-ClickHouse-Format: JSON',
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

  private static $buffers = [];
  public static function open_buffer($table) {
    if ( !isset($buffers[$table]) ) {
      $t = tempnam('/tmp', 'clickhousy-insert-buffer');
      static::$buffers[$table] = $t;
    }

    return static::$buffers[$table];
  }

  public static function insert_buffer($bid, $data) {
    $f = gzopen($bid, 'a');
    foreach ( $data as $row ) {
      fputcsv($f, $row);
    }
    fclose($f);
  }

  public static function flush_buffer($bid) {
    if ( is_file($bid) ) {
      $table = array_search($bid, static::$buffers);
      $data = static::query('INSERT INTO "' . $table . '" FORMAT TSV', [], $bid);
      unlink($bid);

      return $data;
    }
  }
}