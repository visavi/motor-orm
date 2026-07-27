# Motor ORM

ООП подход для работы с текстовыми данными, сохранёнными в файловой системе.

Формат данных CSV совместим, но с некоторыми отступлениями ради более быстрого чтения.

[English version](readme.md)

## Требования

- PHP 8.0 и выше
- `ext-mbstring`

## Установка

```bash
composer require visavi/motor-orm
```

## Быстрый старт

Все запросы идут через модель, в которой указан путь к файлу с данными. В самих
моделях могут быть объявлены приведения типов, scope и связи.

```php
use MotorORM\Builder;

class Article extends Builder
{
    public string $table = __DIR__ . '/data/articles.csv';
}

$article = Article::query()->find(1);

echo $article->title;
```

Первый столбец файла считается уникальным ключом. Он может быть числовым или строковым.

Числовой ключ генерируется при вставке автоматически. Строковый всегда передаётся
явно — продолжать в нём нечего.

Любая запись в файл, включая вставку, выполняется с блокировкой, чтобы
одновременно пишущие процессы не затирали данные друг друга.

## Содержание

- [Чтение](#чтение)
- [Условия](#условия)
- [Частичный поиск (Like)](#частичный-поиск-like)
- [Нестрогий поиск (Lax)](#нестрогий-поиск-lax)
- [Сортировка, лимит и смещение](#сортировка-лимит-и-смещение)
- [Запись](#запись)
- [Приведение типов (Casts)](#приведение-типов-casts)
- [Условия запросов (Scope)](#условия-запросов-scope)
- [Условные выражения](#условные-выражения)
- [Связи](#связи)
- [Загрузка связей](#загрузка-связей)
- [Коллекции](#коллекции)
- [Пагинация](#пагинация)
- [Миграции](#миграции)
- [Разработка](#разработка)

## Чтение

```php
# По уникальному ключу
Article::query()->find(1);

# Первое совпадение или null
Article::query()->where('name', 'Миша')->first();

# Все совпадения в виде коллекции
Article::query()->where('name', 'Миша')->get();

# Есть ли хоть одно совпадение, останавливается на первом
Article::query()->where('name', 'Миша')->exists();

# Сколько записей подходит под условия
Article::query()->where('time', '>', 1231231234)->count();

# Заголовки колонок файла
Article::query()->headers();

# Запись в виде обычного массива
Article::query()->find(1)->toArray();
```

`find()` и `first()` возвращают модель или `null`. `get()` всегда возвращает
[коллекцию](#коллекции), пустую если ничего не нашлось.

`exists()` и `first()` прекращают чтение на первом совпадении, поэтому на записи
в начале файла стоят почти ничего.

## Условия

```php
# Равенство
Article::query()->where('name', 'Миша')->get();

# Явный оператор: = != <> > >= < <= like not_like lax
Article::query()->where('time', '>=', 1231231235)->get();

# Или
Article::query()->where('id', 1)->orWhere('id', 2)->get();

# Вхождение и его отрицание
Article::query()->whereIn('id', [1, 3, 4, 7])->get();
Article::query()->whereNotIn('id', range(1, 10))->get();
```

Замыкание группирует условия, группы можно вкладывать друг в друга:

```php
Article::query()
    ->where('name', 'Миша')
    ->where(function (Builder $query) {
        $query->where('id', 10)->orWhere('id', 11);
    })
    ->get();
```

Фильтрация по колонке, которой нет в файле, бросает `UnexpectedValueException`.

## Частичный поиск (Like)

```php
# Строки, начинающиеся на hi
Article::query()->where('tag', 'like', 'hi%')->get();

# Строки, заканчивающиеся на hi
Article::query()->where('tag', 'like', '%hi')->get();

# Строки, содержащие hi
Article::query()->where('tag', 'like', '%hi%')->get();

# Эквивалентно запросу выше
Article::query()->where('tag', 'like', 'hi')->get();

# Всё, что не содержит hi
Article::query()->where('tag', 'not_like', '%hi%')->get();
```

## Нестрогий поиск (Lax)

По умолчанию сравнение строгое. `lax` сравнивает без учёта регистра:

```php
# Найдёт NAME, name, namE, Name и так далее
User::query()->where('login', 'lax', 'name')->first();
```

## Сортировка, лимит и смещение

```php
# По возрастанию, поведение по умолчанию
Article::query()->orderBy('created_at')->get();

# По убыванию
Article::query()->orderByDesc('created_at')->get();

# Несколько колонок, в порядке добавления
Article::query()
    ->orderByDesc('time')
    ->orderBy('id')
    ->limit(3)
    ->get();

# Записи с 11 по 20
Article::query()->offset(10)->limit(10)->get();
```

Сортировка держит подходящие строки в памяти, поэтому на большом файле сначала
имеет смысл сузить выборку через `where()`.

## Запись

```php
# Вставка, ключ генерируется если колонка числовая
Article::query()->create(['name' => 'Миша']);

# Вставка с явным ключом
Setting::query()->create(['key' => 'theme', 'value' => 'dark']);

# Обновление всех подходящих записей, возвращает количество изменённых
Article::query()->where('name', 'Миша')->update(['text' => 'Новый текст']);

# Обновление одной записи
$article = Article::query()->where('name', 'Миша')->first();
$article->text = 'Новый текст';
$article->save();

# Удаление всех подходящих записей, возвращает количество удалённых
Article::query()->where('name', 'Миша')->delete();

# Удаление одной записи
Article::query()->find(17)->delete();

# Очистка файла с сохранением заголовков
Article::query()->truncate();
```

`create()` бросает `UnexpectedValueException`, если ключ уже занят, а также если
строковый ключ не передан и сгенерировать его нельзя.

## Приведение типов (Casts)

Все значения, прочитанные из файла, строковые, за исключением:

- уникального ключа — `int`
- колонок, заканчивающихся на `_id` и `_at` — `int`
- пустых значений — `null`

Для переопределения используйте свойство `casts`:

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

Поддерживаемые типы:

| Тип | Результат |
|---|---|
| `int`, `integer` | `int` |
| `real`, `float`, `double` | `float` |
| `string` | `string` |
| `bool`, `boolean` | `bool` |
| `object` | `json_decode($value, false)` |
| `array` | `json_decode($value, true)` |

## Условия запросов (Scope)

Scope — это обычный метод с префиксом `scope`. Именно по префиксу ORM понимает,
что перед ней scope. Внутрь передаётся запрос, на который можно навесить условия:

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

Параметры, объявленные после `$query`, заполняются из вызова:

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

## Условные выражения

`when()` выполняет замыкание, только если первый аргумент истинный. Это избавляет
от `if` вокруг необязательных фильтров:

```php
$stories = Story::query()
    ->when($active, function (Builder $query, $active) {
        $query->where('active', $active);
    })
    ->get();
```

Третий аргумент выполняется, когда значение ложно:

```php
$stories = Story::query()
    ->when(
        $sortByVotes,
        fn (Builder $query) => $query->orderBy('votes'),
        fn (Builder $query) => $query->orderBy('name'),
    )
    ->get();
```

## Связи

Поддерживаются три вида. Ключи выводятся из имён классов, указывать их явно нужно
только когда имена колонок не совпадают или связь обратная.

### Один к одному (hasOne)

Принимает имя класса, внешний и внутренний ключ.

```php
# Прямая связь
class User extends Builder
{
    public function story(): Builder
    {
        return $this->hasOne(Story::class);
    }
}

# Обратная связь
class Story extends Builder
{
    public function user(): Builder
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }
}
```

Отсутствующая `hasOne` даёт пустую модель, а не `null`, поэтому обращение к её
колонке безопасно.

### Один ко многим (hasMany)

Принимает имя класса, внешний и внутренний ключ.

```php
class Story extends Builder
{
    public function comments(): Builder
    {
        return $this->hasMany(Comment::class);
    }
}
```

### Многие ко многим (hasManyThrough)

Принимает конечный класс, промежуточный класс и, по желанию, обе пары ключей.

```php
class Story extends Builder
{
    public function tags(): Builder
    {
        return $this->hasManyThrough(Tag::class, TagStory::class);
    }
}
```

## Загрузка связей

Связь загружается при обращении:

```php
$story = Story::query()->find(1);

echo $story->user->login;
echo $story->tags->pluck('name')->all();
```

**Связь, затронутая на одной записи, загружается сразу для всех записей той же
выборки.** Классической проблемы N + 1 не возникает, даже если о ней не думать:

```php
foreach (Story::query()->limit(10)->get() as $story) {
    echo $story->user->login;
}
```

Первое обращение `$story->user` вычитывает пользователей всех десяти историй за
один проход по файлу, остальные девять итераций берут данные из памяти. Записи,
полученные поодиночке через `find()` или `first()`, объединять не с кем, поэтому
они грузят только свою связь.

Загруженная связь кешируется на записи. Повторное обращение вернёт те же объекты,
а не новое чтение.

**`with()` вызывать необязательно.** Он переносит загрузку с первого обращения на
момент запроса, но число проходов по файлу в обоих случаях одинаково, поэтому
скорости он не добавляет. Он нужен, когда результат отдаётся коду, которому вообще
нельзя ходить в файловую систему, либо чтобы явно обозначить, ради каких связей
делается запрос:

```php
Story::query()
    ->orderByDesc('created_at')
    ->with(['user', 'comments'])
    ->paginate($perPage);
```

Работает одинаково на `get()`, `paginate()`, `first()` и `find()`.

`relationLoaded()` сообщает, загружена ли связь:

```php
$story->relationLoaded('user'); // false
$story->user;
$story->relationLoaded('user'); // true
```

## Коллекции

`get()` возвращает `Collection`. Она считаема, обходится циклом и доступна как
массив.

```php
$articles = Article::query()->get();

$articles->all();                       // исходный массив
$articles->first();                     // первый элемент или null
$articles->first(fn ($a) => $a->id > 5); // первое совпадение
$articles->last();                      // последний элемент или null
$articles->count();                     // количество элементов
$articles->isEmpty();
$articles->isNotEmpty();

$articles->get(0, $default);            // элемент по ключу
$articles->has(0);
$articles->keys();
$articles->values();

$articles->pluck('title');              // одна колонка в виде коллекции
$articles->pluck('title', 'id');        // то же, с ключами из другой колонки
$articles->keyBy('id');                 // сами элементы с ключами из колонки
$articles->filter(fn ($a) => $a->id > 5);
$articles->slice(0, 10);
$articles->contains(fn ($a) => $a->id === 3);
$articles->search('needle');

$articles->put('key', $value);
$articles->push($value);
$articles->pull('key');                 // удалить и вернуть
$articles->forget('key');
$articles->clear();
```

`pluck()`, `keyBy()`, `filter()` и `slice()` возвращают новую коллекцию и не
трогают исходную.

`keyBy()` принимает и замыкание, при повторе ключа оставляет последний элемент,
а элементы без такой колонки выбрасывает:

```php
$articles->keyBy(fn ($a) => 'row' . $a->id);
```

## Пагинация

`paginate()` возвращает `CollectionPaginate` — коллекцию, знающую о страницах.
Текущая страница читается из `$_GET['page']`.

```php
$articles = Article::query()->paginate(10);

foreach ($articles as $article) {
    echo $article->title;
}

echo $articles->currentPage();
echo $articles->total();
echo $articles->withPath('/articles')->appends(['sort' => 'new'])->links();
```

## Миграции

В конструктор передаётся модель:

```php
$migration = new Migration(new Article());
```

### Создание таблицы

```php
$migration->createTable(function (Migration $table) {
    $table->create('id');
    $table->create('title');
    $table->create('text');
    $table->create('user_id');
    $table->create('created_at');
});
```

### Удаление таблицы

```php
$migration->deleteTable();
```

### Создание колонок

```php
$migration->changeTable(function (Migration $table) {
    // Создаст колонку text с текстом по умолчанию "Текст" после колонки title
    $table->create('text')->default('Текст')->after('title');

    // Создаст колонку test перед колонкой id
    $table->create('test')->before('id');
});
```

### Переименование колонок

```php
$migration->changeTable(function (Migration $table) {
    $table->rename('user_id', 'author_id');
});
```

### Удаление колонок

```php
$migration->changeTable(function (Migration $table) {
    $table->delete('title');
});
```

### Несколько изменений сразу

Изменения, объявленные в одном вызове `changeTable()`, применяются за один проход
по файлу, сколько бы их ни было. Позиции считаются по колонкам в том виде, в каком
они есть на момент изменения, поэтому добавленная ранее колонка видна следующему
изменению:

```php
$migration->changeTable(function (Migration $table) {
    $table->create('column4')->default('four')->after('column1');
    $table->create('column5')->default('five')->before('column3');
    $table->rename('column2', 'renamed');
    $table->delete('column3');
});
```

### Проверка существования

```php
$migration->hasTable();
$migration->hasColumn('field');
```

Ни то, ни другое ничего не создаёт.

## Разработка

### Тесты

```bash
composer test
```

### Примеры

Демонстрация всех возможностей на тестовых данных:

```bash
php examples/index.php
```

### Бенчмарк

Замер времени и пиковой памяти на операциях чтения и записи. Таблица генерируется
автоматически в `benchmarks/data` при первом запуске.

```bash
composer bench

# своё количество строк и прогонов
php benchmarks/bench.php --rows=200000 --runs=5

# только интересующие операции
php benchmarks/bench.php --filter=find
```

Каждый случай выполняется в отдельном процессе, поэтому пиковая память относится
именно к нему. Последняя колонка показывает отношение потраченной памяти к размеру
таблицы.

## Лицензия

MIT
