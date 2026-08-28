# Changelog

## 6.0.1

### Fixed

**A row whose key is empty no longer warns when its relations are read.** An
empty column reads back as `null`, and PHP 8.5 deprecates using `null` as an
array offset, so a guest message, an unassigned row, anything that leaves a
key blank made the relation loader warn once per row.

A null key matches nothing, which is what such a row got anyway: an empty
`hasOne`, an empty collection for the rest. It is now kept out of the lookup
instead of being looked up by.

## 6.0.0

### Breaking

**A model is the row it reads again, `Record` is gone.** 5.0 split the model in
three and left the behaviour of a row homeless: a column belonged to `Record`,
so anything a row could say about itself had to live in a class beside the
model. One table meant two files, and the smaller the model the more the split
cost.

A model declares the table and, once read, holds the values of one row.
Whatever a row can answer is a method of the model, next to the columns.

```php
class Article extends Model
{
    public string $table = 'articles.csv';

    public function excerpt(int $words = 30): string
    {
        return implode(' ', array_slice(explode(' ', $this->text), 0, $words));
    }
}

$article = Article::query()->find(1);   // Article
$article->excerpt();
```

What 5.0 gained is kept: a row still carries no conditions. `where()`,
`orderBy()`, `limit()` and the rest belong to `Query` alone, so a row cannot
read the table by accident, and a relation is still only a method that says it
returns `Relation`.

`find()`, `first()`, `get()`, `cursor()`, `create()` and every relation give
back the model. `Record::fresh()`, `save()`, `delete()`, `update()`, `key()`,
`toArray()`, `relationLoaded()` and `newQuery()` are the same methods on the
model. `RecordMapper` is `RowMapper`.

A row carries the declaration along with its values now, so it costs a little
more than a record did: about 790 bytes against 690 on five columns, 377 MB
against 347 for 500 000 rows read whole. Reading a page, walking with
`cursor()` or counting is unchanged.

**Nothing is read from the environment any more.** `resolveCurrentPage()` used
to reach into `$_GET`, so an orm that reads csv had an opinion about http, and
the answer depended on where the code ran — a console command, a queue worker
and a test all got whatever the last request left behind. The page now comes
from `page()` or from the resolver the application names, and with neither of
them it is the first page.

```php
/* once, in the bootstrap of the application */
Pagination::resolvePageUsing(static fn (string $name) => $_GET[$name] ?? 1);
```

**`links()` and the bundled Bootstrap 5 template are gone.** An orm that reads
csv shipped markup for a css framework, and rendering — including the output
buffering around it — lived in the library that holds the data. `pages()` gives
the navigation as `Page` objects and the application prints it:

```php
echo $view->render('pagination', ['pages' => $articles->pages()]);
```

Escaping goes with the rendering: `Page::$url` is a plain string now, escaped
by the template that prints it.

**`Collection::__toString()` is gone.** Printing a collection gave back
`Collection@0000...`, so `<?= $stories ?>` in a template quietly produced a
hash instead of failing.

### Added

- **`Table::primaryKey()`** — the column rows are known by, in the one class
  that owns the columns. `Query`, `TableWriter`, `RowMapper` and `Model` asked
  the file for its first column each on their own.

- **`save()` inserts a row that has no key yet**, so a model built by hand is
  written the same way as one that was read.

```php
$article = new Article();
$article->title = 'New title';
$article->save();               // inserted, and given the key of the table
```

- **`Model::newRow()`** — one row of the table holding the given values, and
  **`Model::primaryKey()`** — name of the column the keys live in.

### Fixed

**`pluck()` no longer loses rows whose column is empty.** `array_column()` asks
an object for `__isset()`, which answers false for null, so a row with an empty
value fell out of the result along with its key: a settings table of 43 rows,
one of them empty, gave back 42. The values are read directly now, and an empty
column keeps its place as null.

**`SplFileObject` is gone, `MotorORM\CsvFile` stands in its place.** Php 8.6
deprecates `SplFileObject::fgetcsv()`, `fputcsv()`, `setCsvControl()` and
`getCsvControl()`, and csv is all the orm ever opened a file for. `CsvFile` is
a `SeekableIterator` over a plain handle, reading and writing through `fgetcsv`
and `fputcsv`, which are not deprecated.

```php
// 5.0
$file = $model->file();            // SplFileObject

// next
$file = $model->file();            // MotorORM\CsvFile
$file->fputcsv(['id', 'title']);   // the same call
foreach ($file->rows(1) as $line => $row) {} // header aside, line number => row
```

`Model::file()`, `Model::createFile()` and `Table::file()` return it, and the
csv control belongs to the constructor rather than to a setter:
`new CsvFile($path, 'a+b', ...Model::CSV_CONTROL)`.

**`Table::rewrite()` hands the closure a line number, not the file being read.**
The rows come out of a generator now, and the line a row came from is what the
closure ever asked the source for.

```php
// 5.0
$table->rewrite(function (array &$current, SplFileObject $target, SplFileObject $source) {
    if ($source->key() === 0) { /* header */ }
});

// next
$table->rewrite(function (array &$current, CsvFile $target, int $line) {
    if ($line === 0) { /* header */ }
});
```

**`paginate()` and `simplePaginate()` no longer take a page.** The page is the
one `page()` was told, or the one being asked for — read from `?page=` of the
request.

```php
// 5.0
Article::query()->paginate(10, (int) ($_GET['page'] ?? 1));

// next
Article::query()->paginate(10);          // takes the page from the request
Article::query()->page(3)->paginate(10); // says it outright
```

`page()` sits beside `limit()` and `offset()` and is never below the first page.
Spelling out the page beats the request.

**`setPageName()` is static and returns nothing.** The page has to be known
before a page of rows exists, so the name of the parameter carrying it cannot
belong to that page. It names the parameter in the built urls and in the request
alike, for `Pagination` and `SimplePagination` both.

```php
// 5.0
$articles->setPageName('p')->links();

// next
Pagination::setPageName('p');
```

### Performance

**`find()` no longer reads the table.** Keys are handed out one after another
and a rewrite keeps the rows in the order it read them, so a table normally
lies sorted by its first column, and the row can be reached by halving the file
instead of walking it. On 50 000 rows a record at the end of the file went from
73.6 ms to 2.1 ms, and against the same lookup in raw php from x1.08 to x0.01.

Nothing is taken on trust: the row found has to carry the key that was asked for
and to begin where a record begins, which is settled by counting the quotes
before it rather than by parsing anything. Anything that does not add up — keys
out of order, a key that is not a whole number, a row of the wrong width, quotes
that do not close — and the table is read row by row as before, for the same
answer. A key that is not in the table costs that full walk too.

Values holding newlines are no obstacle: a halving that lands inside one steps
on to the row that follows.

Halving is for a bare lookup: conditions, an order or an offset set before
`find()` all send the query back to reading the table.

```php
Article::query()->find(1);                        // by halving the file
Article::query()->where('name', 'Bob')->find(1);  // by a full walk
```

`CsvFile` gained the reads this is built on: `rowFrom()` for the first whole row
at or after a byte, `startsRecord()` for whether a record begins at one, `tell()`
for where the file stands and `size()` for how long it is.

**A count with nothing to match no longer reads the rows.** Counting has no use
for the values, and building an array out of every line is most of what reading
one costs. `count()` without conditions now walks the lines and counts the rows
they begin, which on 50 000 rows took it from 54.5 ms to 7.3 ms and `paginate()`
from 55.1 ms to 7.8 ms; on 500 000 rows, from 551 ms to 64 ms. A count that has
conditions reads the rows as before, since it has to look at them.

A line begins a row unless a value left open on an earlier line runs through it,
which the quotes tell — the same rule the rest of the reading follows. `CsvFile`
carries it as `countRows()`, `Table` as `countRecords()`.

**A query opens the table once, not twice.** A file stands at the line naming
the columns as soon as it is opened, and `Table::records()` used to walk past it
and leave the columns to be read through a second opening. It keeps them now, so
the opening a query spent on the header alone is gone. On a read of ten rows,
where that opening was a quarter of the whole cost, the distance to raw php went
from x1.68 to x1.32; on a read that scans the table it was never worth measuring.

`Table::headersFrom()` is the column names of a file already open, and what
`headers()` falls back on when nothing has opened the table yet.

**A read is about a tenth faster.** The rows of a table come out of one
generator over `fgetcsv` instead of an `SplFileObject` wrapped in a
`LimitIterator` and a `CallbackFilterIterator`, so a row costs one resume
rather than a call to every method of three iterators. On 50 000 rows, against
the same work in raw php: reading rows by a condition went from x1.24 to x1.07,
walking the whole table from x1.38 to x1.23, sorting the last ten from x0.80 to
x0.73.

### Added

- **`Query::page()`** — the page to paginate, said outright.
- **`PagedCollection::resolvePageUsing()`** — where the current page comes from.
  The closure is given the name of the page parameter; `null` puts the query
  string of the request back.

```php
Pagination::resolvePageUsing(
    static fn (string $name) => $request->getQueryParams()[$name] ?? 1
);
```

- **`PagedCollection::resolveCurrentPage()`** — the page being asked for, never
  below the first one. A value that is no number is taken for the first page.
- **`PagedCollection::pageName()`** — name of the page parameter.

- **`Model::$record`** — the class the rows of a table are read into, `Record`
  unless the model names another one. Behaviour a row of the table has belongs
  to that class, and a query gives it back wherever it makes a record — a
  lookup, a whole result, a cursor, an insert, or a relation that found nobody.

```php
class Article extends Model
{
    protected string $record = ArticleRecord::class;
}

Article::query()->find(1);   // ArticleRecord
```

- **`Model::newRecord()` and `Model::recordClass()`** — one record of the table,
  and the name of the class it is read into, checked once per model.

- **`with()` takes a closure per relation.** A relation named by the key of a
  closure is narrowed for that one read, the way `constrain()` narrows every read
  of it. Given both, the conditions stack — the declared one first.

```php
Story::query()
    ->with([
        'user',
        'comments' => static fn (Query $query) => $query->where('approved', 1),
    ])
    ->get();
```

  Naming a relation the old way is unchanged. A value that is not a closure, and
  a key that names nothing, raise `InvalidArgumentException`.

## 5.0.0

A model is no longer the record it reads. The library is now three things — a
model that declares the table, a query that reads it and a record that holds the
values — and everything below follows from that.

### Requirements

- PHP 8.5 or above, up from 8.0.

### Breaking

**`Builder` is gone, replaced by `Model`, `Query` and `Record`.** A model no
longer doubles as the row it returns.

```php
// 4.x
class Article extends Builder
{
    public string $table = __DIR__ . '/articles.csv';
}

$article = Article::query()->find(1);
$article->title = 'Title';
$article->save();

// 5.0
class Article extends Model
{
    public string $table = __DIR__ . '/articles.csv';
}

$article = Article::query()->find(1);   // Record
$article->title = 'Title';
$article->save();
```

The model keeps `$table`, `$tableDir` and `$casts`; the query keeps the
conditions; the record keeps the values, `save()`, `delete()`, `update()` and
`toArray()`. `refresh()` on a record is now `fresh()`.

**Relations are declared on the model and say so in their return type.** Only a
method returning `Relation` is treated as one, so reading a property can never
run a method that does something else.

```php
// 4.x
public function comments(): mixed
{
    return $this->hasMany(Comment::class);
}

// 5.0
public function comments(): Relation
{
    return $this->hasMany(Comment::class);
}
```

**Patterns are methods of their own.** The `like` and `not_like` operators are
no longer accepted by `where()`, and an operator that is not known now throws
instead of quietly matching nothing.

```php
// 4.x
Article::query()->where('title', 'like', '%php%')->get();

// 5.0
Article::query()->whereLike('title', '%php%')->get();
```

Also available: `whereNotLike()`, `orWhereLike()`, `orWhereNotLike()`. Matching
ignores case unless the last argument says otherwise.

**Sorting takes an enum.** `Builder::SORT_ASC` and `SORT_DESC` are gone.

```php
Article::query()->orderBy('title', SortOrder::Desc)->get();
Article::query()->orderByDesc('title')->get();          // the same, shorter
```

**Pagination is rebuilt and no longer reads the request.** `CollectionPaginate`
is replaced by `Pagination` and `SimplePagination`, both collections of the rows
of their page. The page number is passed in — where it came from is the
application's business.

```php
// 4.x — the page was taken from $_GET inside the library
$articles = Article::query()->paginate(10);

// 5.0
$page     = (int) ($_GET['page'] ?? 1);
$articles = Article::query()->paginate(10, $page);

echo $articles->links();
```

`simplePaginate()` is new: it reads one row past the page instead of counting
the table, and has no `total()` or `lastPage()` at all. `paginate(0)` throws
`InvalidArgumentException` rather than dividing by zero.

**Migrations are written as a chain.** `createTable()` and `changeTable()` no
longer take a closure.

```php
// 4.x
$migration->createTable(function (Migration $table) {
    $table->create('id');
    $table->create('title');
});

// 5.0
$migration
    ->create('id')
    ->create('title')
    ->createTable();
```

Changes collected before one call are still applied in a single pass over the
file. The migration takes a model: `new Migration(new Article())`.

**A broken cast is an error.** A column cast to `array` or `object` that does
not hold json used to read back as `null`; it now throws
`UnexpectedValueException`, as does a value that cannot be written as json.

**`Collection` lost `key()`, `next()` and `current()`.** It is walked with
`foreach` and cut with `slice()`, `filter()`, `pluck()` and `keyBy()`.

**The csv escape character is gone.** Files are written to RFC 4180: a quote
inside a value is doubled, nothing is escaped with a backslash. A table written
by 4.x whose values end in a backslash may read differently — check such tables
before upgrading. Any other csv parser reads these files as they are.

### Added

- `cursor()` — walks the rows one at a time, holding one record at a time
  instead of the whole result.
- `simplePaginate()` — a page without counting the table.
- `whereLike()` and its family.
- `constrain()` on a relation — narrows what an eager load reads.
- `Record::toArray()`, `Record::fresh()`, `Record::relationLoaded()`.
- `Collection::keyBy()`, and `search()`/`contains()` now take a closure as well
  as a value. `pluck()`, `slice()` and `filter()` return collections.
- `Pagination::onEachSide()`, `setPageName()`, `withPath()`, `appends()`, and
  `links()` renders through a template of your own when given one.

### Fixed

- A relation read from a record kept the result the record came from, not
  whatever its query read last.
- Writing a table is atomic: rows go to a sibling file that replaces the table
  in one step, so a reader never sees a half written table and a failed write
  leaves the original alone.
- A write takes a lock, and a lock taken on a file that was replaced meanwhile
  is taken again.
- `count()` with a condition counted the first row of the table twice.
- A read started while another one was going on cut the first one short.
- `limit(0)` returned nothing instead of throwing `OutOfBoundsException`.
- `first()` reads from where `offset()` says, as `get()` and `cursor()` do; it
  used to read from the start of the table whatever the offset was.

### Performance

Measured on a table of 50 000 rows, against the same work written with `fopen`
and `fgetcsv` (`composer compare`):

- A sorted page (`orderByDesc('id')->limit(10)`) holds the rows of the page
  instead of the table: 0.6 MB against 33 MB, and faster than raw php.
- Reading a whole table with `cursor()` holds one record at a time.
- Inserting a row no longer collects the keys of the table, updating and
  deleting no longer read the file twice.

## 4.2.2 and earlier

See the commit history.
