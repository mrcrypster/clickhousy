# Clickhousy
High performance Clickhouse PHP library featuring:
- Tiny memory footprint based on static class (hundreds of times less consumption than [smi2 client](#memory-usage-and-performance)).
- Curl based.
- High level methods to fetch rows, single row, array of scalar values or single value.
- Parametric queries (native Clickhouse SQL injection protection).
- Long queries progress update through callback function.
- Large result sets processing without memory overflow.
- HTTP native compression.
- Error handling.
- Batch data writing.


## Quick start
Clone or download library:

```bash
git clone https://github.com/mrcrypster/clickhousy.git
```

Import lib and use it:

```php
require 'clickhousy/clickhousy.php';
$data = clickhousy::rows('SELECT * FROM table LIMIT 5');
```


## Connection & database
`Clickhousy` works over [Clickhouse HTTP protocol](https://clickhouse.com/docs/en/interfaces/http/), so you just need to set connection url (by default it's `localhost:8123`):
```php
clickhousy::set_url('http://host:port/'); # no auth
clickhousy::set_url('http://user:password@host:port/'); # auth
```

And then select database (`default` by default):
```php
clickhousy::set_db('my_db');
```


## Data fetch
Use predefined methods to quickly fetch needed data:
```php
clickhousy::rows('SELECT * FROM table');         # -> returns array or associative arrays
clickhousy::row ('SELECT * FROM table LIMIT 1'); # -> returns single row associative array
clickhousy::cols('SELECT id FROM table');        # -> returns array of scalar values
clickhousy::col ('SELECT count(*) FROM table');  # -> returns single scalar value
```


## Reading large datasets
If your query returns many rows, use reading callback in order not to run out of memory:
```php
clickhousy::query('SELECT * FROM large_table', [], null, null, function($packet) {
  // $packet will contain small portion of returning data
  foreach ( $packet as $row ) {
    // do something with $row (array of each result row values)
    print_r($row) # [0 => 'col1 value', 1 => 'col2 value', ...]
  }
});
```


## Safe query execution
In order to fight SQL injections, you can use [parametric queries](https://clickhouse.com/docs/en/interfaces/http/#cli-queries-with-parameters):

```php
$count = clickhousy::col(
  'SELECT count(*) FROM table WHERE age > {age:UInt32} AND category = {cat:String}',
  ['age' => 30, 'cat' => 'Engineering']
);
```


## Writing data
It's not a good idea to insert large amounts of data into Clickhouse using single row inserts.
Consider using other technologies, like Kafka to ingest data into Clockhouse efficiently.
Avoid using client-library inserts in production environments.

With Clickhousy you can insert either named rows:
```php
clickhousy::insert('my_table', [
    ['id' => 1, 'date' => date('Y-m-d')],
    ['id' => 2, 'date' => date('Y-m-d')]
]);
```

Or unnamed rows (in this case, make sure that values order is the same as table columns order):
```php
clickhousy::insert('my_table', [
    [1, date('Y-m-d')],
    [2, date('Y-m-d')]
]);
```

### Inserting large datasets
If you still need (which you should avoid please) to insert large amounts of data using Clickhousy,
do it in batches of at least couple of thousands rows (but be sure to monitor RAM usage by PHP):
```php
$f = fopen('large.csv', 'r');
$batch = [];
while ( $row = fgetcsv($f) ) {
    $batch[] = $row;
    if ( count($batch) >= 5000 ) {
        clickhousy::insert('my_table', $batch);
        $batch = [];
    }
}

if ( $batch ) {
    clickhousy::insert('my_table', $batch);
}
```


## Custom queries
Generic `query` method is available for any read or write query:
```php
clickhousy::query('INSERT INTO table(id) VALUES(1)');
clickhousy::query('TRUNCATE TABLE table');
clickhousy::query('SELECT NOW()');
```

For `SELECT` queries it will return resultset together with system information:
```php
$result_set = clickhousy::query('SELECT * FROM numbers(5)');
print_r($result_set);
```

Will output:
```
Array
(
    [meta] => Array
        (
            [0] => Array
                (
                    [name] => number
                    [type] => UInt64
                )

        )

    [data] => Array
        (
            [0] => Array
                (
                    [number] => 0
                )
            ...
        )

    [rows] => 2
    [rows_before_limit_at_least] => 2
    [statistics] => Array
        (
            [elapsed] => 0.000237397
            [rows_read] => 2
            [bytes_read] => 16
        )

)
```

Method also supports parametric queries:
```php
$res = clickhousy::query('SELECT * FROM table WHERE age > {age:Int32}', ['age' => $_GET['age']]);
```


## Inserting data from files
You can insert data from files using `$post_buffer` argument pointing to a file:
```php
$res = clickhousy::query('INSERT INTO table', [], '/path/to/tsv.gz');
```

File should be of gzipped TSV format.


## Long query progress tracking
`$progress_callback` allows specifying callback function which will be called when query execution progress updates:
```php
clickhousy::query('SELECT uniq(id) FROM huge_table', [], null, function($progress) {
  print_r($progress);
});
```
If the query is long enough, progress function will be called multiple times:
```
Array
(
    [read_rows] => 4716360              # -> currently read rows 
    [read_bytes] => 37730880
    [written_rows] => 0
    [written_bytes] => 0
    [total_rows_to_read] => 50000000    # -> total rows to read
)
...
Array
(
    [read_rows] => 47687640
    [read_bytes] => 381501120
    [written_rows] => 0
    [written_bytes] => 0
    [total_rows_to_read] => 50000000
)
```

It's easy to calculate and print query execution progress:
```php
clickhousy::query('SELECT count(*) FROM large_table WHERE heavy_condition', [], null, function($progress) {
  echo round(100 * $progress['read_rows']/$progress['total_rows_to_read']) . '%' . "\n";
});
```
Which will result in:
```
7%
17%
28%
39%
50%
61%
72%
83%
94%
```


## Query execution summary
Latest query summary is available through:
```php
clickhousy::rows('SELECT * FROM numbers(100000)');
print_r(clickhousy::last_summary());
```
Which is an associative array of multiple metrics:
```
Array
(
    [read_rows] => 65505
    [read_bytes] => 524040
    [written_rows] => 0
    [written_bytes] => 0
    [total_rows_to_read] => 100000
)
```


## Errors handling and debug
By default, `clickhousy` will return response with `error` key on error, but will not throw any exceptions:
```php
$response = clickhousy::query('SELECT bad_query');  # sample query with error
print_r($response);
```
Which contains original Clickhouse error message:
```
Array
(
    [error] => Code: 47. DB::Exception: Missing columns: 'bad_query' while processing query: 'SELECT bad_query', required columns: 'bad_query'. (UNKNOWN_IDENTIFIER) (version 22.6.1.1696 (official build))

)
```

If you want exceptions functionality, you can extend `clickhousy` with your own class and override `error()` method:
```php
class my_clickhousy_exception extends Exception {};
class my_clickhousy extends clickhousy {
  public static function error($msg, $request_info) {
    throw new my_clickhousy_exception($msg);
  }
}
```

Then use your own class to get exceptions working:
```php
my_clickhousy::query('SELECT bad_query');   # bad query example
# PHP Fatal error:  Uncaught my_clickhousy_exception: Code: 47. DB::Exception: Missing columns: 'bad_query' ...
```


### Debugging response
If you need to access raw response from Clickhouse, you can find it here: 
```php
clickhouse::last_response();  # raw text response from Clickhouse after latest query
```

Curl (which is used for HTTP communication) response info can be accessed via:
```php
clickhouse::last_info();  # response from curl_getinfo() after latest query
```


### Logging
There is also `log()` method which can be overrided to allow custom logging:
```php
class my_clickhousy extends clickhousy {
  public static function log($raw_response, $curl_info) {
    error_log('Received response from Clickhouse: ' . $raw_response);
  }
}
```


## Memory usage and performance
Based on [performance testing](tests/smi2-test.php), `Clickhousy` lib is times faster
and hundreds of times less memory consuming than [smi2 lib](https://github.com/smi2/phpClickHouse):

```
Smi2: 
mem:                565       
time:               10.3      

Clickhousy: 
mem:                0.8       688.8x  better
time:               2.3       4.4x  better
``` 
