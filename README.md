# Clickhousy (php lib)
High performance Clickhouse PHP library featuring:
- Tiny memory footprint based on static class
- Parametric queries (simple sql-injection protection).
- Long queries progress update through callback function.
- Huge datasets inserts without memory overflow.
- HTTP compression for traffic reduction.
- Curl based (shell curl command required for large inserts).


## Quick start
Clone or download library:

```bash
git clone https://github.com/mrcrypster/clickhousy.git
```

Import lib and use it:

```php
require 'clickhousy/clickhousy.php';
$data = clickhousy::rows('SELECT count(*) FROM table');
```


## Set connection & database
`Clickhousy` works over [Clickhouse HTTP protocol](https://clickhouse.com/docs/en/interfaces/http/), so you just need to set connection url:
```php
clickhousy::set_url('http://host:port/'); # no auth
clickhousy::set_url('http://user:password@host:port/'); # auth
```

And then select database (which is `default` by default):
```php
clickhousy::set_db('my_db');
```


## Data fetch
You can use 4 predefined functions to quickly fetch needed data:
```php
clickhousy::rows('SELECT * FROM table');        # -> returns array or associative arrays
clickhousy::row('SELECT * FROM table LIMIT 1'); # -> returns single row associative array
clickhousy::cols('SELECT id FROM table');       # -> returns array of scalar values
clickhousy::col('SELECT count(*) FROM table');  # -> returns single scalar value
```


## Reading large datasets
If your query returns many rows, you should use reading callback in order no to run out of memory:
```php
clickhousy::query('SELECT * FROM large_table', [], null, null, function($packet) {
  // $packet will contain small portion of returning data
  foreach ( $packet as $row ) {
    // do something with $row (array of each result row values)
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
Though writing also happens somewhere else (not on PHP side), `Clickhousy` has shell `curl` wrapper to write massive datasets to Clickhouse:

```php
$b = clickhousy::open_buffer('table');  # open insert buffer to insert data into "table" table
                                        # buffer is a tmp file on disk, so memory leaks aren't possible

for ( $k = 0; $k < 100; $k++ ) {        # repeat generation 100 times
  $rows = [];

  for ( $i = 0; $i < 1000000; $i++ ) {
    $rows[] = [md5($i)];                # generate 1 million rows 
  }

  clickhousy::insert_buffer($b, $rows); # insert generated 1 million rows into buffer
}

$result = clickhousy::flush_buffer($b); # sends buffer (100m rows) content to Clickhouse
```

After insert is executed, `$result` variable gets summary from Clickhouse:
```
Array
(
    [read_rows] => 10000
    [read_bytes] => 410000
    [written_rows] => 10000
    [written_bytes] => 410000
    [total_rows_to_read] => 0
)
```


## Custom queries
Generic `query` method is available for any query execution:
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

            [1] => Array
                (
                    [number] => 1
                )

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

File should be gzipped TSV.


## Long query progress tracking
`$progress_callback` allows specifying callback function which will be called on query execution progress change:
```php
clickhousy::query('SELECT count(*) FROM large_table WHERE heavy_condition', [], null, function($progress) {
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

You can calculate and print query execution progress like that:
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