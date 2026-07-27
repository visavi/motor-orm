# Motor ORM

An object oriented way to work with text data stored in the file system.

The data format is CSV compatible, with a few deviations that make reading faster.

[Русская версия](readme.ru.md)

## Requirements

- PHP 8.0 or newer
- `ext-mbstring`

## Installation

```bash
composer require visavi/motor-orm
```

## Quick start

Every query goes through a model that points at a data file. Models may carry extra
methods of their own — casts, scopes and relations.

```php
use MotorORM\Builder;

class Article extends Builder
{
    public string $table = __DIR__ . '/data/articles.csv';
}

$article = Article::query()->find(1);

echo $article->title;
```

The first column of a file is the primary key. It may be numeric or a string.

A numeric key is generated automatically on insert. A string key must always be
passed explicitly, there is nothing to continue from.

Every write, including inserts, locks the file, so that concurrent writers cannot
lose each other's data.

## Contents

- [Reading](#reading)
- [Conditions](#conditions)
- [Partial match (Like)](#partial-match-like)
- [Loose match (Lax)](#loose-match-lax)
- [Sorting, limit and offset](#sorting-limit-and-offset)
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
Article::query()->where('time', '>', 1231231234)->count();

# The column names of the file
Article::query()->headers();

# The record as a plain array
Article::query()->find(1)->toArray();
```

`find()` and `first()` return the model or `null`. `get()` always returns a
[Collection](#collection), empty if nothing matched.

`exists()` and `first()` stop reading at the first match, so they cost almost
nothing on a record near the top of the file.

## Conditions

```php
# Equality
Article::query()->where('name', 'Misha')->get();

# An explicit operator: = != <> > >= < <= like not_like lax
Article::query()->where('time', '>=', 1231231235)->get();

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
    ->where(function (Builder $query) {
        $query->where('id', 10)->orWhere('id', 11);
    })
    ->get();
```

Filtering by a column the file does not have throws an `UnexpectedValueException`.

## Partial match (Like)

```php
# Starts with hi
Article::query()->where('tag', 'like', 'hi%')->get();

# Ends with hi
Article::query()->where('tag', 'like', '%hi')->get();

# Contains hi
Article::query()->where('tag', 'like', '%hi%')->get();

# Same as the query above
Article::query()->where('tag', 'like', 'hi')->get();

# Everything that does not contain hi
Article::query()->where('tag', 'not_like', '%hi%')->get();
```

## Loose match (Lax)

Comparison is strict by default. `lax` compares case insensitively:

```php
# Matches NAME, name, namE, Name and so on
User::query()->where('login', 'lax', 'name')->first();
```

## Sorting, limit and offset

```php
# Ascending, the default
Article::query()->orderBy('created_at')->get();

# Descending
Article::query()->orderByDesc('created_at')->get();

# Several columns, applied in the order they were added
Article::query()
    ->orderByDesc('time')
    ->orderBy('id')
    ->limit(3)
    ->get();

# Records 11 to 20
Article::query()->offset(10)->limit(10)->get();
```

Sorting buffers the matching rows in memory, so prefer narrowing the query with
`where()` before ordering a large file.

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

Every value read from a file is a string, except for:

- the primary key — `int`
- columns ending in `_id` and `_at` — `int`
- empty values — `null`

Declare the `casts` property to override this:

```php
class Story extends Builder
{
    protected array $casts = [
        'rating' => 'int',
        'reads'  => 'int',
        'locked' => 'bool',
        'meta'   => 'array',
    ];
}
```

Supported types:

| Cast | Result |
|---|---|
| `int`, `integer` | `int` |
| `real`, `float`, `double` | `float` |
| `string` | `string` |
| `bool`, `boolean` | `bool` |
| `object` | `json_decode($value, false)` |
| `array` | `json_decode($value, true)` |

## Scopes

A scope is a method prefixed with `scope`. The prefix is how the ORM tells it apart
from an ordinary method. The query is passed in, ready for more conditions:

```php
class Story extends Builder
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}

Story::query()->active()->paginate($perPage);
```

Parameters declared after `$query` are filled from the call:

```php
class Story extends Builder
{
    public function scopeOfType(Builder $query, string $type): Builder
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
    ->when($active, function (Builder $query, $active) {
        $query->where('active', $active);
    })
    ->get();
```

A third argument runs when the value is falsy:

```php
$stories = Story::query()
    ->when(
        $sortByVotes,
        fn (Builder $query) => $query->orderBy('votes'),
        fn (Builder $query) => $query->orderBy('name'),
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
class User extends Builder
{
    public function story(): Builder
    {
        return $this->hasOne(Story::class);
    }
}

# Inverse
class Story extends Builder
{
    public function user(): Builder
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
class Story extends Builder
{
    public function comments(): Builder
    {
        return $this->hasMany(Comment::class);
    }
}
```

### Many to many (hasManyThrough)

Takes the target class, the intermediate class and, optionally, both pairs of keys.

```php
class Story extends Builder
{
    public function tags(): Builder
    {
        return $this->hasManyThrough(Tag::class, TagStory::class);
    }
}
```

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
the original alone.

`keyBy()` also takes a closure, keeps the last item of a repeated key, and drops
items that have no such column:

```php
$articles->keyBy(fn ($a) => 'row' . $a->id);
```

## Pagination

`paginate()` returns a `CollectionPaginate`, a collection that knows about pages.
The current page is read from `$_GET['page']`.

```php
$articles = Article::query()->paginate(10);

foreach ($articles as $article) {
    echo $article->title;
}

echo $articles->currentPage();
echo $articles->total();
echo $articles->withPath('/articles')->appends(['sort' => 'new'])->links();
```

## Migrations

Pass the model to the constructor:

```php
$migration = new Migration(new Article());
```

### Creating a table

```php
$migration->createTable(function (Migration $table) {
    $table->create('id');
    $table->create('title');
    $table->create('text');
    $table->create('user_id');
    $table->create('created_at');
});
```

### Deleting a table

```php
$migration->deleteTable();
```

### Adding columns

```php
$migration->changeTable(function (Migration $table) {
    // A column text holding "Text" by default, placed after title
    $table->create('text')->default('Text')->after('title');

    // A column test placed before id
    $table->create('test')->before('id');
});
```

### Renaming columns

```php
$migration->changeTable(function (Migration $table) {
    $table->rename('user_id', 'author_id');
});
```

### Deleting columns

```php
$migration->changeTable(function (Migration $table) {
    $table->delete('title');
});
```

### Several changes at once

Changes declared in one `changeTable()` call are applied in a single pass over the
file, whatever their number. Positions resolve against the columns as they change,
so a column added earlier is visible to the next change:

```php
$migration->changeTable(function (Migration $table) {
    $table->create('column4')->default('four')->after('column1');
    $table->create('column5')->default('five')->before('column3');
    $table->rename('column2', 'renamed');
    $table->delete('column3');
});
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

## License

MIT
