# Motor ORM

An object oriented way to work with text data stored in the file system.

The data format is CSV compatible, with a few deviations that make reading faster.

[Русская версия](readme.ru.md)

## Requirements

- PHP 8.5 or newer
- `ext-mbstring`

## Installation

```bash
composer require visavi/motor-orm
```

## Quick start

The library is three things. A **model** says where the data lives, what the
columns mean and what the table is related to. A **query** reads and writes it. A
**record** holds one row.

```
Article::query()   ->   Query   ->   Record
   Model                                │
     └── casts, scopes, relations       └── values, save, delete
```

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

## Contents

- [Reading](#reading)
- [Conditions](#conditions)
- [Partial match (Like)](#partial-match-like)
- [Loose match (Lax)](#loose-match-lax)
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
Article::query()->where('time', '>', 1231231234)->count();

# The column names of the file
Article::query()->headers();

# The record as a plain array
Article::query()->find(1)->toArray();
```

`find()` and `first()` return a `Record` or `null`. `get()` always returns a
[Collection](#collection) of records, empty if nothing matched.

A record holds the values of one row and knows how to write itself back, but it
carries no conditions and runs no queries of its own:

```php
$article = Article::query()->find(1);

$article->title;                 // a column
$article->title = 'New title';   // changed in memory
$article->save();                // and written back
$article->update(['text' => 'New text']);
$article->delete();
$article->fresh();               // read again, dropping the unsaved changes
$article->toArray();
```

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
    ->where(function (Query $query) {
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
Article::query()->orderBy('created_at', SortOrder::Desc)->get();

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
        'created_at' => 'int',
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
the original alone, unlike `put()`, `push()`, `pull()`, `forget()` and `clear()`,
which change the one they are called on. Dropping the result of a pure one is
almost always a mistake, so those are marked `#[\NoDiscard]` and PHP says so.

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
