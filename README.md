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

```
git clone https://github.com/mrcrypster/clickhousy.git
```

Import lib and use it:

```
require 'clickhousy/clickhousy.php';
$data = clickhousy::rows('SELECT count(*) FROM table');
```