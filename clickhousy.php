<?php

class clickhousy {
  protected static $url = 'http://localhost:8123';
  protected static $db = 'default';
  protected static $last_response = [];
  protected static $last_info = [];
  protected static $last_summary = [];



  /* Send query and process Clickhouse response */

  public static function query($sql, $params = [], $post_buffer = null, $progress_callback = null, $output_tsv = null) {
    $q = [
      'enable_http_compression' => 1,
      'send_progress_in_http_headers' => 1,
      'query' => $sql
    ];

    $endpoint = self::$url . '?' . http_build_query($q);


    if ( $post_buffer ) {
      # unfortunately, we have to call shell curl, cause PHP can't send binary
      # data from files without loading it into memory (which is stupid in our case)

      $cmd = 'curl -v -q ' . escapeshellarg($endpoint) .
             ' -H "X-ClickHouse-Database: ' . self::$db . '" ' .
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

    $request = [
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_ENCODING => 'gzip',
      CURLOPT_HTTPHEADER => [
        'X-ClickHouse-Database: ' . self::$db,
        'X-ClickHouse-Format: JSON',
        'Accept-Encoding: gzip'
      ],
      CURLOPT_HEADERFUNCTION => function($curl, $header) use ($progress_callback) {
        # echo $header . "\n";

        $dots = strpos($header, ':');
        $header_name = substr($header, 0, $dots);
        
        if ( $header_name == 'X-ClickHouse-Summary' ) {
          self::$last_summary = json_decode(substr($header, $dots + 1), true);
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

    if ( $output_tsv ) {
      $request[CURLOPT_HTTPHEADER][1] = 'X-ClickHouse-Format: TSV';
      $request[CURLOPT_FILE] = fopen($output_tsv, 'w');

      /*$request[CURLOPT_WRITEFUNCTION] = function($c, $packet) use ($output_tsv) {
        $len = strlen($packet);
        file_put_contents($output_tsv, $packet, FILE_APPEND);
        return $len;
      };*/
    }

    curl_setopt_array($c, $request);

    if ( $output_tsv ) {
      curl_exec($c);
    }
    else {
      $res = curl_exec($c);
    }
    
    $info = curl_getinfo($c);
    if ( $info['http_code'] != 200 ) {
      return self::error($res, $info);
    }
    else if ( isset($res) ) {
      $json = json_decode($res, true);
      self::log($json, $info);
      return $json;
    }
  }



  /* Errors and logging */

  public static function error($msg, $request_info) {
    return ['error' => $msg];
  }

  public static function log($res, $info) {
    self::$last_response = $res;
    self::$last_info = $info;
  }

  public static function last_response() {
    return self::$last_response;
  }

  public static function last_info() {
    return self::$last_info;
  }

  public static function last_summary() {
    return self::$last_summary;
  }

  

  /* Ready to use row/col data methods */

  public static function rows($sql, $params = []) {
    $data = self::query($sql, $params)['data'];
    $rows = [];

    foreach ( $data as $r ) {
      $rows[] = $r;
    }

    return $rows;
  }

  public static function cols($sql, $params = []) {
    $rows = self::rows($sql, $params);

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
    $rows = self::rows($sql, $params);
    return $rows ? $rows[0] : null;
  }

  public static function col($sql, $params = []) {
    $row = self::row($sql, $params);
    return $row ? array_shift($row) : null;
  }



  /* Writes */

  private static $buffers = [];
  public static function open_buffer($table) {
    if ( !isset($buffers[$table]) ) {
      $t = tempnam('/tmp', 'clickhousy-insert-buffer');
      self::$buffers[$table] = $t;
    }

    return self::$buffers[$table];
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
      $table = array_search($bid, self::$buffers);
      $data = self::query('INSERT INTO "' . $table . '" FORMAT TSV', [], $bid);
      unlink($bid);

      return $data;
    }
  }
}