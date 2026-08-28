# Motor ORM — an ORM for CSV

[![Packagist](https://img.shields.io/packagist/v/visavi/motor-orm.svg)](https://packagist.org/packages/visavi/motor-orm)
[![Tests](https://github.com/visavi/motor-orm/actions/workflows/tests.yml/badge.svg)](https://github.com/visavi/motor-orm/actions/workflows/tests.yml)
[![Coverage](https://coveralls.io/repos/github/visavi/motor-orm/badge.svg?branch=master)](https://coveralls.io/github/visavi/motor-orm?branch=master)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4.svg)](https://www.php.net/releases/8.5/)
[![Downloads](https://img.shields.io/packagist/dt/visavi/motor-orm.svg)](https://packagist.org/packages/visavi/motor-orm)
[![License](https://img.shields.io/packagist/l/visavi/motor-orm.svg)](https://github.com/visavi/motor-orm/blob/master/composer.json)

An ORM over plain CSV files: models, queries, relations, pagination and
migrations, without a database server.

The data lives in plain RFC 4180 csv: a quote inside a value is written twice
and nothing escapes. Such a file reads the same here and in any other parser.

[Русская версия](readme.ru.md)

## Requirements

- PHP 8.5 or newer
- `ext-mbstring`

## Installation

```bash
composer require visavi/motor-orm
```

## Quick start

The library is three layers, two of which you write against:

|               | what it is                                | what it carries                                                |
|---------------|-------------------------------------------|----------------------------------------------------------------|
| **Model**     | `class Article extends Model`, `$article` | the table, casts, scopes, relations — and the values of one row |
| **Query**     | `Article::query()`                        | conditions, sorting, pagination, writing                        |
| **the table** | `Table`, `CsvFile`, `RowMapper`, …        | bytes, columns, casts applied, rows filtered and sorted         |

A model declares the table and, once read, is a row of it: whatever a row can
answer is a method of the model. What it never carries is the query that found
it — conditions live in `Query`, so a row cannot read the table by accident.

Under the two of them the reading is split further, a class per job: `Table`
and `CsvFile` for the file, `RowMapper` between rows and values, `Conditions`,
`Sorter`, `KeySearch`, `TableWriter` and `RelationLoader` for the rest. You
never name them; they are what `Query` is made of.

```php
use MotorORM\Model;

class Article extends Model
{
    public string $table = __DIR__ . '/data/articles.csv';
}

$article = Article::query()->find(1);

echo $article->title;
```

Querying a table whose file is not there throws an `UnexpectedValueException`
rather than bringing an empty one into being. Tables are created by
[migrations](#migrations).

The first column of a file is the primary key. It may be numeric or a string.

A numeric key is generated automatically on insert. A string key must always be
passed explicitly, there is nothing to continue from.

Every write locks the file, so that concurrent writers cannot lose each other's
data. A write that changes existing rows builds the new table beside the old one
and puts it in place in a single atomic step, so a reader never sees a half
written table and an interrupted write leaves the original untouched.

### What it looks like together

Two tables, a relation between them and a page of a listing, which is all a
small section of a site comes down to:

```php
class Article extends Model
{
    public string $table = __DIR__ . '/data/articles.csv';

    protected array $casts = ['user_id' => 'int', 'views' => 'int'];

    public function user(): Relation
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function scopePublished(Query $query): Query
    {
        return $query->where('published', 1);
    }
}

$articles = Article::query()
    ->published()
    ->whereLike('title', '%orm%')
    ->orderByDesc('created_at')
    ->paginate(10);

foreach ($articles as $article) {
    printf('%s by %s, %d views', $article->title, $article->user->login, $article->views);
}

echo $articles->withPath('/articles')->links();
```

The author of each article costs no read of its own here: `$article->user` on
the first article reads the authors of all ten at once.

## Performance

A table of 50 000 rows (4 MB), PHP 8.5.7, best of five runs, each in its own
process. `raw php` is `fopen` + `fgetcsv` + `array_combine` in a loop: no
objects, no casts, no conditions to read, nothing but the file. It is the floor,
and what the orm costs is the distance to it:

| operation                          | Raw PHP           | Motor ORM         |           |
|------------------------------------|-------------------|-------------------|-----------|
| find a record by its key           | 67.7 ms, 0.6 MB   | 0.7 ms, 0.6 MB    | **x0.01** |
| count the rows a condition matches | 66.1 ms, 0.6 MB   | 71.4 ms, 0.6 MB   | x1.08     |
| read the rows a condition matches  | 67.3 ms, 0.6 MB   | 71.9 ms, 0.7 MB   | x1.07     |
| a page of ten rows                 | 0.1 ms, 0.6 MB    | 0.1 ms, 0.6 MB    | x1.32     |
| the last ten, sorted               | 132.5 ms, 33.1 MB | 97.3 ms, 0.6 MB   | **x0.73** |
| walk the whole table               | 66.4 ms, 0.6 MB   | 81.4 ms, 0.6 MB   | x1.23     |
| read the whole table               | 69.1 ms, 29.6 MB  | 93.7 ms, 35.4 MB  | x1.36     |

What the orm costs on a scan is under a tenth of the time, and it goes on what
it is taken for: reading the conditions, casting the values, objects instead of
arrays.

The lookup by key is out of that row: `find()` does not read the table at all,
it [halves the file](#finding-by-key), and a walk over every row loses to it by
two orders of magnitude.

The sorted row stands out: the orm is faster than the raw code and spends 0.6 MB
instead of 33. It holds the table and sorts all of it, while a query with a
`limit` carries only the rows that will be in the answer.

Time is measured warm, memory cold. Loading the classes of the orm costs about a
millisecond and is paid once per process: on a case that touches ten rows, that
is what would be measured otherwise.

To run it yourself:

```bash
php benchmarks/compare.php
php benchmarks/compare.php --rows=200000 --runs=5
```

### What it costs in memory

A csv row costs several times more in memory than in the file: an array of five
columns is about 440 bytes, the model holding it about 790. A file of 41 MB,
read whole, takes 377 MB.

So what runs out is not the size of the table but the size of the result:

|                                 | 500 000 rows, 41 MB |
|---------------------------------|---------------------|
| `cursor()` over the whole table | 797 ms, 0 MB        |
| `count()`                       | 62 ms, 0 MB         |
| `orderByDesc('id')->limit(10)`  | 987 ms, 0 MB        |
| `paginate(10)`                  | 64 ms, 0 MB         |
| `get()` of the whole table      | 377 MB              |

A table can be of any size as long as you do not ask for all of it at once. To
walk it there is [`cursor()`](#walking-a-large-table), to show it
[`paginate()`](#pagination).

The other ceiling is time: a pass costs about 1.1 us a row, so a `where` that
does not hit the head of the file takes about half a second on 500 000 rows. Indexes
are what saves you there, and there are none here.

## Contents

- [Performance](#performance)
- [Reading](#reading)
- [Conditions](#conditions)
- [Pattern match (Like)](#pattern-match-like)
- [Sorting, limit and offset](#sorting-limit-and-offset)
- [Walking a large table](#walking-a-large-table)
- [Writing](#writing)
- [Casts](#casts)
- [Scopes](#scopes)
- [Conditional clauses](#conditional-clauses)
- [Relations](#relations)
- [Loading relations](#loading-relations)
- [Collection](#collection)
- [Pagination](#pagination)
- [Migrations](#migrations)
- [Development](#development)

## Reading

```php
# By primary key
Article::query()->find(1);

# The first match, or null
Article::query()->where('name', 'Misha')->first();

# Every match as a Collection
Article::query()->where('name', 'Misha')->get();

# Whether anything matches, stops at the first hit
Article::query()->where('name', 'Misha')->exists();

# How many records match
Article::query()->where('created_at', '>', '2009-01-06 08:40:34')->count();

# The column names of the file
Article::query()->headers();

# The record as a plain array
Article::query()->find(1)->toArray();
```

`find()` and `first()` return the model or `null`. `get()` always returns a
[Collection](#collection) of them, empty if nothing matched.

A row holds its values and knows how to write itself back, but carries no
conditions and runs no queries of its own:

```php
$article = Article::query()->find(1);

$article->title;                 // a column
$article->title = 'New title';   // changed in memory
$article->save();                // and written back
$article->update(['text' => 'New text']);
$article->delete();
$article->fresh();               // read again, dropping the unsaved changes
$article->toArray();

$new = new Article();            // a row nothing has written yet
$new->title = 'New title';
$new->save();                    // inserted, and given the key of the table
```

`exists()` and `first()` stop reading at the first match, so they cost almost
nothing on a record near the top of the file.

### What a row can answer

Whatever a row of the table can say about itself is a method of the model, next
to the columns it reads:

```php
class Article extends Model
{
    public string $table = 'articles.csv';

    public function excerpt(int $words = 30): string
    {
        return implode(' ', array_slice(explode(' ', $this->text), 0, $words));
    }
}

Article::query()->find(1)->excerpt();
```

A relation is read into its own model the same way, so `$article->user` answers
everything `User` does.

### Finding by key

`find()` does not read the table. Keys are handed out one after another and a
rewrite keeps the rows in the order it read them, so the file normally lies
sorted by its first column, and the row can be reached by halving the file.
Some twenty reads instead of every row: on a table of 50 000 rows a record at
the end is found in 2.1 ms rather than 73.

Nothing is taken on trust. The row found has to carry the very key that was
asked for and to begin where a record begins. The second is settled exactly: a
value may hold a newline of its own, and then the line behind it looks like a
row without being one. A quote opens a value and the next one closes it, a
quote inside a value is written twice, so the quotes before the start of a
record are an even number of them. The bytes are only counted, not parsed, and
going over the whole file that way costs some fifty times less than reading it.

Anything that does not add up and `find()` quietly reads the table row by row,
as it always did. So no one has to keep the file sorted: keys out of order, a
key that is not a number, a broken row, quotes that do not close — each of them
means the old full walk and the old answer, only without the speedup. The
lookup cannot come back with the wrong row: a doubt costs speed, never
correctness.

Halving is for a bare lookup by key and nothing else. Conditions, an order or an
offset set before `find()` all mean the table has to be read anyway:

```php
Article::query()->find(1);                        // by halving the file
Article::query()->where('name', 'Bob')->find(1);  // by a full walk
```

A key that is not there costs a full walk too: a missing row and a file sorted
some other way look the same from outside, and the answer must not depend on
which of the two it is.

## Conditions

```php
# Equality
Article::query()->where('name', 'Misha')->get();

# An explicit operator: = != <> > >= < <=
Article::query()->where('created_at', '>=', '2009-01-06 08:40:35')->get();

# Or
Article::query()->where('id', 1)->orWhere('id', 2)->get();

# In and not in
Article::query()->whereIn('id', [1, 3, 4, 7])->get();
Article::query()->whereNotIn('id', range(1, 10))->get();
```

A closure groups conditions, and groups may be nested:

```php
Article::query()
    ->where('name', 'Misha')
    ->where(function (Query $query) {
        $query->where('id', 10)->orWhere('id', 11);
    })
    ->get();
```

Filtering by a column the file does not have throws an `UnexpectedValueException`.

## Pattern match (Like)

A `%` says the value may go on in that direction:

```php
# Starts with hi
Article::query()->whereLike('tag', 'hi%')->get();

# Ends with hi
Article::query()->whereLike('tag', '%hi')->get();

# Contains hi
Article::query()->whereLike('tag', '%hi%')->get();

# Exactly hi and nothing more
Article::query()->whereLike('tag', 'hi')->get();

# Everything that does not contain hi
Article::query()->whereNotLike('tag', '%hi%')->get();

# As an alternative to the condition before it
Article::query()->where('id', 1)->orWhereLike('tag', '%hi%')->get();
Article::query()->where('id', 1)->orWhereNotLike('tag', '%hi%')->get();
```

A pattern without wildcards is matched against the whole value, the way sql `LIKE` behaves.

The case is ignored by default, `caseSensitive` changes that:

```php
# Matches NAME, name, namE, Name and so on
User::query()->whereLike('login', 'name')->first();

# Only name
User::query()->whereLike('login', 'name', caseSensitive: true)->first();
```

An operator outside the list of comparisons throws an `InvalidArgumentException` — a typo such as `lke` fails on the spot instead of returning nothing.

## Sorting, limit and offset

```php
# Ascending, the default
Article::query()->orderBy('created_at')->get();

# Descending
Article::query()->orderByDesc('created_at')->get();
Article::query()->orderBy('created_at', SortOrder::Desc)->get();

# Several columns, applied in the order they were added
Article::query()
    ->orderByDesc('created_at')
    ->orderBy('id')
    ->limit(3)
    ->get();

# Records 11 to 20
Article::query()->offset(10)->limit(10)->get();
```

Sorting buffers the matching rows in memory, so prefer narrowing the query with
`where()` before ordering a large file.

The direction is a `SortOrder`, so a misspelt one cannot reach the query. When
it comes from the request, `tryFrom()` is the check — an unknown string becomes
`null` and the fallback takes over:

```php
$sort = SortOrder::tryFrom($_GET['dir'] ?? '') ?? SortOrder::Asc;

Article::query()->orderBy('created_at', $sort)->get();
```

`$sort->value` gives the string back, for the link that flips the direction:

```php
$articles->appends(['dir' => $sort->value]);
```

## Walking a large table

`get()` builds every matching record before handing them over. `cursor()` yields
them one at a time, so only the record being looked at is held in memory:

```php
foreach (Article::query()->where('active', 1)->cursor() as $article) {
    echo $article->title;
}
```

On a table of 50 000 rows that is 0 MB against the 33 MB `get()` needs, at the
same speed. Nothing is collected, so a cursor has no siblings to batch a relation
for: touching one inside the loop reads the related table once per record, and
`with()` has nothing to attach to. Use `get()` when the records are related.

## Writing

```php
# Insert, the key is generated when the column is numeric
Article::query()->create(['name' => 'Misha']);

# Insert with an explicit key
Setting::query()->create(['key' => 'theme', 'value' => 'dark']);

# Update every matching record, returns how many were changed
Article::query()->where('name', 'Misha')->update(['text' => 'New text']);

# Update a single record
$article = Article::query()->where('name', 'Misha')->first();
$article->text = 'New text';
$article->save();

# Delete every matching record, returns how many were removed
Article::query()->where('name', 'Misha')->delete();

# Delete a single record
Article::query()->find(17)->delete();

# Remove every record, keeping the column names
Article::query()->truncate();
```

`create()` throws an `UnexpectedValueException` when the key is already taken, and
when a string key was omitted and cannot be generated.

## Casts

A csv file carries no types, so every value read from it is a string, and an empty
value is `null`.

The primary key is the exception: the ORM generates it, so it reads it back as an
`int` when the value is a number. A key that is not a number stays a string, which
is why a table keyed by `theme` or `3f2a-9b` needs nothing declared.

Nothing else is guessed, least of all from a column name: a `created_at` holding
`2026-07-28 12:30:00` is that string, and a `uuid_id` holding `3f2a-9b` is that
string.

The model is the only place that knows what any other column means, so spell it
out in the `casts` property:

```php
class Story extends Model
{
    protected array $casts = [
        'user_id'    => 'int',
        'views'      => 'int',
        'rating'     => 'int',
        'locked'     => 'bool',
        'meta'       => 'array',
    ];
}
```

Declaring the primary key overrides what it would get, so a numeric key can be
kept as a string:

```php
protected array $casts = [
    'id' => 'string',
];
```

Conditions and sorting work on the raw values of the file, so a cast changes what
you read back and nothing else. `where('id', 1)` and `orderBy('id')` behave the
same whether or not `id` is declared.

Supported types:

| Cast                      | Result                       |
|---------------------------|------------------------------|
| `int`, `integer`          | `int`                        |
| `real`, `float`, `double` | `float`                      |
| `string`                  | `string`                     |
| `bool`, `boolean`         | `bool`                       |
| `object`                  | `json_decode($value, false)` |
| `array`                   | `json_decode($value, true)`  |

Arrays and objects are written to a column as json, whether or not a cast was
declared for it. A column that does not hold the json it was cast to is a broken
table, so reading it raises an `UnexpectedValueException` instead of giving back
a `null`. Writing a value json cannot carry (`NAN`, `INF`, broken UTF-8) raises
the same, and the table is left as it was.

## Scopes

A scope is a method prefixed with `scope`. The prefix is how the ORM tells it apart
from an ordinary method. The query is passed in, ready for more conditions:

```php
class Story extends Model
{
    public function scopeActive(Query $query): Query
    {
        return $query->where('active', true);
    }
}

Story::query()->active()->paginate($perPage);
```

Parameters declared after `$query` are filled from the call:

```php
class Story extends Model
{
    public function scopeOfType(Query $query, string $type): Query
    {
        return $query->where('type', $type);
    }
}

Story::query()->ofType('new')->paginate($perPage);
```

## Conditional clauses

`when()` applies a closure only if the first argument is truthy, which keeps
optional filters out of `if` blocks:

```php
$stories = Story::query()
    ->when($active, function (Query $query, $active) {
        $query->where('active', $active);
    })
    ->get();
```

A third argument runs when the value is falsy:

```php
$stories = Story::query()
    ->when(
        $sortByVotes,
        fn (Query $query) => $query->orderBy('votes'),
        fn (Query $query) => $query->orderBy('name'),
    )
    ->get();
```

## Relations

Three kinds are supported. Keys are derived from the class names, and only need to
be spelled out when the column names differ, or when the relation is inverse.

### One to one (hasOne)

Takes a class name, a foreign key and a local key.

```php
# Direct
class User extends Model
{
    public function story(): Relation
    {
        return $this->hasOne(Story::class);
    }
}

# Inverse
class Story extends Model
{
    public function user(): Relation
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }
}
```

A missing `hasOne` gives an empty model rather than `null`, so reading a column off
it is safe.

### One to many (hasMany)

Takes a class name, a foreign key and a local key.

```php
class Story extends Model
{
    public function comments(): Relation
    {
        return $this->hasMany(Comment::class);
    }
}
```

### Many to many (hasManyThrough)

Takes the target class, the intermediate class and, optionally, both pairs of keys.

```php
class Story extends Model
{
    public function tags(): Relation
    {
        return $this->hasManyThrough(Tag::class, TagStory::class);
    }
}
```

### Constrained relations

`constrain()` puts conditions on a relation that it is always loaded with. The
closure is given the query on the related table:

```php
class Story extends Model
{
    public function approvedComments(): Relation
    {
        return $this->hasMany(Comment::class)->constrain(
            static fn (Query $query) => $query->where('approved', 1)->orderByDesc('id')
        );
    }
}
```

Works on all three kinds of relation, and the same way on access as through
`with()`.

**A `limit()` or an `offset()` in a constraint applies to the whole read, not to
each record.** A relation is read once for the whole result, so `limit(1)` gives
one row for that result, and it goes to whichever record it belongs to:

```php
Story::query()->find(1)->lastComment;            // one story, one row, its own
Story::query()->with('lastComment')->get();      // three stories, one row between them
```

For the latest of each, sort the relation and take the first element of the
collection.

## Loading relations

Relations load on access:

```php
$story = Story::query()->find(1);

echo $story->user->login;
echo $story->tags->pluck('name')->all();
```

**A relation touched on one record loads for every record of the same result.**
The classic N + 1 never happens, even without asking for it:

```php
foreach (Story::query()->limit(10)->get() as $story) {
    echo $story->user->login;
}
```

The first `$story->user` reads the users of all ten stories with a single pass over
the file, and the other nine iterations are served from memory. Records fetched on
their own, through `find()` or `first()`, have no siblings to batch with and simply
load their own relation.

Once loaded, a relation is cached on the record. Ask for it again and you get the
same objects, not a fresh read.

**`with()` is optional.** It moves the loading to the query instead of the first
access, but the number of passes over the file is the same either way, so calling
it buys no speed. Reach for it when the result is handed to code that should not
touch the file system at all, or to say out loud which relations a query is for:

```php
Story::query()
    ->orderByDesc('created_at')
    ->with(['user', 'comments'])
    ->paginate($perPage);
```

It works on `get()`, `paginate()`, `first()` and `find()` alike.

### Narrowing a relation where it is loaded

A relation can be narrowed in `with()` itself: name it by the key and pass a
closure as the value. The closure is called with the query on the related table:

```php
Story::query()
    ->with([
        'user',
        'comments' => static fn (Query $query) => $query->where('approved', 1),
    ])
    ->get();
```

Plain names and narrowed relations travel in the same list.

The difference from `constrain()` is where the condition lives: `constrain()`
describes a relation that is **always** narrowed and holds on access too, while a
closure in `with()` narrows **one** read and says nothing about `$story->comments`
read without `with()`. Given both, the conditions stack — the declared one first,
what the query asks for on top.

`relationLoaded()` reports whether a relation is already in memory:

```php
$story->relationLoaded('user'); // false
$story->user;
$story->relationLoaded('user'); // true
```

## Collection

`get()` returns a `Collection`. It is countable, iterable and accessible as an
array.

```php
$articles = Article::query()->get();

$articles->all();                       // the underlying array
$articles->first();                     // the first item, or null
$articles->first(fn ($a) => $a->id > 5); // the first match
$articles->last();                      // the last item, or null
$articles->count();                     // how many items
$articles->isEmpty();
$articles->isNotEmpty();

$articles->get(0, $default);            // an item by key
$articles->has(0);
$articles->keys();
$articles->values();

$articles->pluck('title');              // one column as a Collection
$articles->pluck('title', 'id');        // the same, keyed by another column
$articles->keyBy('id');                 // the items themselves, keyed by a column
$articles->filter(fn ($a) => $a->id > 5);
$articles->slice(0, 10);
$articles->contains(fn ($a) => $a->id === 3);
$articles->search('needle');

$articles->put('key', $value);
$articles->push($value);
$articles->pull('key');                 // remove and return
$articles->forget('key');
$articles->clear();
```

`pluck()`, `keyBy()`, `filter()` and `slice()` return a new collection and leave
the original alone, unlike `put()`, `push()`, `pull()`, `forget()` and `clear()`,
which change the one they are called on. Dropping the result of a pure one is
almost always a mistake, so those are marked `#[\NoDiscard]` and PHP says so.

`keyBy()` also takes a closure, keeps the last item of a repeated key, and drops
items that have no such column:

```php
$articles->keyBy(fn ($a) => 'row' . $a->id);
```

## Pagination

`paginate()` returns a `Pagination`, a collection that knows about pages. The
page comes from the request, out of `?page=`:

```php
$articles = Article::query()->paginate(10);

foreach ($articles as $article) {
    echo $article->title;
}

echo $articles->currentPage();
echo $articles->lastPage();
echo $articles->perPage();
echo $articles->total();

if ($articles->hasPages()) {
    echo $articles->withPath('/articles')->appends(['sort' => 'new'])->links();
}
```

The rest tells you where the page stands among all the rows:

```php
# Showing 11 to 20 of 45
printf('Showing %d to %d of %d', $articles->firstItem(), $articles->lastItem(), $articles->total());

# null on both when nothing matched
$articles->firstItem();
$articles->lastItem();

# Where the page stands
$articles->onFirstPage();
$articles->onLastPage();

# The url of any page, not only of the ones on show
$articles->url($articles->lastPage());
```

A page out of range falls back to the nearest one, so an absurd number in the
request cannot produce an empty listing.

### Where the page comes from

From wherever the application says, and nowhere else: a library that reads csv
knows nothing about requests, so it reads no globals of its own. Until it is
told, the first page is meant.

`page()` says which page outright:

```php
Article::query()->page(3)->paginate(10);
```

`resolvePageUsing()` says where an untold query takes it from — once, in the
bootstrap of the application. The closure is given the name of the parameter,
and a value that is no number is taken for the first page:

```php
Pagination::resolvePageUsing(
    static fn (string $name) => $request->getQueryParams()[$name] ?? 1
);

/* the plainest one, for an application that lives on $_GET */
Pagination::resolvePageUsing(static fn (string $name) => $_GET[$name] ?? 1);
```

The name of the parameter is one for the whole application: it is what the
resolver is given and what the links are built with.

```php
Pagination::setPageName('p');   // ?p=3
```

Both settings are static and shared by `Pagination` and `SimplePagination`: the
page has to be known before a page of rows exists, so it cannot belong to one.
`resolvePageUsing(null)` takes the source away again.

### Pagination without counting

Knowing the total is what buys the numbered links, and it is paid for by a walk
over the whole table. On a table of 50 000 rows that is the entire cost of a
page: fetching the ten rows of page one takes 0.05 ms, counting the rest takes 6.

A count with nothing to match does not read the rows, only counts them, so what
it asks for is far less than it used to be. A walk is still a walk, though, and
on later pages, where the walk to the offset is added to it, the two paginations
come out close to each other.

`simplePaginate()` does not count. It reads one row past the page, and whether
that row was there is the whole answer:

```php
$articles = Article::query()->simplePaginate(10);

foreach ($articles as $article) {
    echo $article->title;
}

echo $articles->withPath('/articles')->links();
```

| on 50 000 rows       | first page | page 4 900 |
|----------------------|------------|------------|
| `paginate(10)`       | 7.75 ms    | 60.05 ms   |
| `simplePaginate(10)` | 1.45 ms    | 54.09 ms   |

Later pages still cost the walk to their offset, which nothing but an index can
avoid. What the counting bought is gone: there is no `total()` and no
`lastPage()`, and the navigation is two arrows instead of numbered pages. The
rest is the same — `currentPage()`, `perPage()`, `firstItem()`, `lastItem()`,
`onFirstPage()`, `onLastPage()`, `hasMorePages()`, `url()`, `links()`.

`simplePaginate()` returns a `SimplePagination`. The methods that need a total
are not on it at all, so asking for one fails where it is written rather than
deep inside.

Both are collections of the rows of their page: walk, count and slice them like
any other collection, and on top of that they know where the page stands among
the rest.

`links()` renders Bootstrap 5 markup. Pass a template of your own to get anything
else:

```php
echo $articles->onEachSide(3)->links(__DIR__ . '/views/pagination.php');
```

The template is given `$pages`, an array of `Page` objects:

```php
<?php foreach ($pages as $page): ?>
    <?php if ($page->separator): ?>
        …
    <?php elseif ($page->current): ?>
        <b><?= $page->name ?></b>
    <?php else: ?>
        <a href="<?= htmlspecialchars($page->url, ENT_QUOTES) ?>"><?= $page->name ?></a>
    <?php endif; ?>
<?php endforeach; ?>
```

`name` is what to print — a page number or an arrow, `url` is where it leads
(`null` on the current page and on a separator) and `number` is the page it
leads to. Escaping is the template's business, since the built-in one writes
html but yours might not.

## Migrations

Pass the model to the constructor:

```php
$migration = new Migration(new Article());
```

Columns are named one after another, and `createTable()` or `changeTable()` at the
end applies them to the file.

### Creating a table

```php
$migration
    ->create('id')
    ->create('title')
    ->create('text')
    ->create('user_id')
    ->create('created_at')
    ->createTable();
```

### Deleting a table

```php
$migration->deleteTable();
```

### Adding columns

```php
$migration
    // A column text holding "Text" by default, placed after title
    ->create('text')->default('Text')->after('title')

    // A column slug placed before text
    ->create('slug')->before('text')

    ->changeTable();
```

Nothing should be placed before the first column: it is the primary key of the
table, and a new column would take its place.

### Renaming columns

```php
$migration->rename('user_id', 'author_id')->changeTable();
```

### Deleting columns

```php
$migration->delete('title')->changeTable();
```

### Several changes at once

Changes collected before one `changeTable()` call are applied in a single pass over
the file, whatever their number. Positions resolve against the columns as they
change, so a column added earlier is visible to the next change:

```php
$migration
    ->create('column4')->default('four')->after('column1')
    ->create('column5')->default('five')->before('column3')
    ->rename('column2', 'renamed')
    ->delete('column3')
    ->changeTable();
```

### Checking existence

```php
$migration->hasTable();
$migration->hasColumn('field');
```

Neither creates anything.

## Development

### Tests

```bash
composer test
```

### Examples

Every feature demonstrated on the test data:

```bash
php examples/index.php
```

### Benchmark

Time and peak memory of the read and write operations. The table is generated into
`benchmarks/data` on the first run.

```bash
composer bench

# a table and a run count of your own
php benchmarks/bench.php --rows=200000 --runs=5

# only the operations you care about
php benchmarks/bench.php --filter=find
```

Each case runs in a process of its own, so the peak memory belongs to that case
alone. The last column compares the memory spent against the size of the table.

### Against raw php

The same operations written with `fopen` + `fgetcsv`, side by side with the
queries, which is [the table from the performance section](#performance):

```bash
composer compare

php benchmarks/compare.php --rows=200000 --runs=5
```

The table is the one `bench` uses, so generating it once serves both.

## License

MIT
