# Changelog

## Unreleased

### Breaking

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

### Performance

**A read is about a tenth faster.** The rows of a table come out of one
generator over `fgetcsv` instead of an `SplFileObject` wrapped in a
`LimitIterator` and a `CallbackFilterIterator`, so a row costs one resume
rather than a call to every method of three iterators. On 50 000 rows, against
the same work in raw php: reading rows by a condition went from x1.24 to x1.07,
walking the whole table from x1.38 to x1.23, sorting the last ten from x0.80 to
x0.73.

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
