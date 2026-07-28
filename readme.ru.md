# Motor ORM

[![Packagist](https://img.shields.io/packagist/v/visavi/motor-orm.svg)](https://packagist.org/packages/visavi/motor-orm)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4.svg)](https://www.php.net/releases/8.5/)
[![Загрузки](https://img.shields.io/packagist/dt/visavi/motor-orm.svg)](https://packagist.org/packages/visavi/motor-orm)
[![Лицензия](https://img.shields.io/packagist/l/visavi/motor-orm.svg)](https://github.com/visavi/motor-orm/blob/master/composer.json)

ООП подход для работы с текстовыми данными, сохранёнными в файловой системе.

Данные лежат в обычных CSV по RFC 4180: кавычка внутри значения удваивается,
экранирующего символа нет. Такой файл одинаково прочитают и эта библиотека, и
любой другой парсер.

[English version](readme.md)

## Требования

- PHP 8.5 и выше
- `ext-mbstring`

## Установка

```bash
composer require visavi/motor-orm
```

## Быстрый старт

Библиотека состоит из трёх вещей:

|            | что это                       | что на ней держится                          |
|------------|-------------------------------|----------------------------------------------|
| **Модель** | `class Article extends Model` | путь к файлу, касты, scope, связи            |
| **Запрос** | `Article::query()`            | условия, сортировка, пагинация, запись       |
| **Запись** | `$article`                    | значения строки, `save()`, `delete()`, связи |

Модель ничего не читает, пока её не спросят. Запрос открывает файл на первом
чтении, а не при создании. Запись знает только свою строку и запрос, из которого
она пришла.

```php
use MotorORM\Model;

class Article extends Model
{
    public string $table = __DIR__ . '/data/articles.csv';
}

$article = Article::query()->find(1);

echo $article->title;
```

Запрос к таблице, файла которой нет, бросает `UnexpectedValueException`, а не
создаёт пустую. Таблицы создаются [миграциями](#миграции).

Первый столбец файла считается уникальным ключом. Он может быть числовым или строковым.

Числовой ключ генерируется при вставке автоматически. Строковый всегда передаётся
явно — продолжать в нём нечего.

Любая запись выполняется с блокировкой, чтобы одновременно пишущие процессы не
затирали данные друг друга. Запись, меняющая существующие строки, собирает новую
таблицу рядом со старой и подставляет её одним атомарным шагом, поэтому читающий
никогда не видит недописанную таблицу, а прерванная запись оставляет исходную
нетронутой.

### Как это выглядит целиком

Две таблицы, связь между ними и страница со списком — всё, что нужно небольшому
разделу сайта:

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
    ->whereLike('title', '%орм%')
    ->orderByDesc('created_at')
    ->paginate(10, (int) ($_GET['page'] ?? 1));

foreach ($articles as $article) {
    printf('%s — %s, %d просмотров', $article->title, $article->user->login, $article->views);
}

echo $articles->withPath('/articles')->links();
```

Автор каждой статьи здесь не стоит отдельного чтения файла: `$article->user`
на первой статье вычитывает авторов всех десяти сразу.

## Производительность

Таблица на 50 000 строк (4 МБ), PHP 8.5.7, лучшее из пяти прогонов, каждый в
отдельном процессе. `raw php` — это `fopen` + `fgetcsv` + `array_combine` в цикле:
без объектов, без приведения типов, без разбора условий, только файл. Это пол, а
цена ORM — расстояние до него:

| операция                       | Raw PHP           | Motor ORM         |           |
|--------------------------------|-------------------|-------------------|-----------|
| найти запись по ключу          | 67.1 мс, 0.6 МБ   | 81.5 мс, 0.6 МБ   | ×1.22     |
| посчитать строки по условию    | 66.6 мс, 0.6 МБ   | 81.8 мс, 0.6 МБ   | ×1.23     |
| выбрать строки по условию      | 66.8 мс, 0.6 МБ   | 83.0 мс, 0.6 МБ   | ×1.24     |
| страница из десяти строк       | 0.1 мс, 0.6 МБ    | 0.1 мс, 0.6 МБ    | ×1.47     |
| десять последних с сортировкой | 133.6 мс, 33.1 МБ | 107.4 мс, 0.6 МБ  | **×0.80** |
| обойти всю таблицу             | 66.0 мс, 0.6 МБ   | 90.9 мс, 0.6 МБ   | ×1.38     |
| прочитать таблицу целиком      | 67.7 мс, 29.6 МБ  | 102.4 мс, 35.3 МБ | ×1.51     |

Цена ORM на сканировании — около четверти времени, и она уходит на то, ради чего
её берут: разбор условий, приведение типов, объекты вместо массивов.

Строка с сортировкой выбивается: ORM быстрее сырого кода, а памяти тратит
0.6 МБ против 33. Наивный код собирает таблицу в память и сортирует целиком, а
запрос с `limit` несёт только те строки, которые попадут в ответ.

Время замерено с прогревом, память — с холодного запуска. Подключение классов
ORM стоит около миллисекунды и платится один раз на процесс: на кейсе, который
трогает десять строк, без прогрева измерялось бы именно оно.

Повторить у себя:

```bash
php benchmarks/compare.php
php benchmarks/compare.php --rows=200000 --runs=5
```

### Чего это стоит по памяти

Строка csv в памяти PHP занимает в разы больше, чем в файле: массив на пять
колонок — это около 440 байт, объект `Record` поверх него — около 690. Файл в
41 МБ, прочитанный целиком, займёт 347 МБ.

Поэтому упирается не размер таблицы, а размер результата:

|                                | 500 000 строк, 41 МБ |
|--------------------------------|----------------------|
| `cursor()` по всей таблице     | 890 мс, 0 МБ         |
| `count()`                      | 633 мс, 0 МБ         |
| `orderByDesc('id')->limit(10)` | 1095 мс, 0 МБ        |
| `paginate(10)`                 | 638 мс, 0 МБ         |
| `get()` всей таблицы           | 347 МБ               |

Таблица может быть какого угодно размера, пока вы не просите её целиком. Для
обхода есть [`cursor()`](#обход-большой-таблицы), для показа —
[`paginate()`](#пагинация).

Второй потолок — время: проход стоит около 1.3 мкс на строку, и `where` без
попадания в начало файла на 500 000 строк займёт около секунды. От этого спасают
индексы, которых здесь нет.

## Содержание

- [Производительность](#производительность)
- [Чтение](#чтение)
- [Условия](#условия)
- [Поиск по шаблону (Like)](#поиск-по-шаблону-like)
- [Сортировка, лимит и смещение](#сортировка-лимит-и-смещение)
- [Обход большой таблицы](#обход-большой-таблицы)
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
Article::query()->where('created_at', '>', '2009-01-06 08:40:34')->count();

# Заголовки колонок файла
Article::query()->headers();

# Запись в виде обычного массива
Article::query()->find(1)->toArray();
```

`find()` и `first()` возвращают `Record` или `null`. `get()` всегда возвращает
[коллекцию](#коллекции) записей, пустую если ничего не нашлось.

Запись держит значения одной строки и умеет записать себя обратно, но не несёт
условий и сама запросов не выполняет:

```php
$article = Article::query()->find(1);

$article->title;                    // колонка
$article->title = 'Новый заголовок';  // изменено в памяти
$article->save();                   // и записано обратно
$article->update(['text' => 'Новый текст']);
$article->delete();
$article->fresh();                  // перечитать, отбросив несохранённое
$article->toArray();
```

`exists()` и `first()` прекращают чтение на первом совпадении, поэтому на записи
в начале файла стоят почти ничего.

## Условия

```php
# Равенство
Article::query()->where('name', 'Миша')->get();

# Явный оператор: = != <> > >= < <=
Article::query()->where('created_at', '>=', '2009-01-06 08:40:35')->get();

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
    ->where(function (Query $query) {
        $query->where('id', 10)->orWhere('id', 11);
    })
    ->get();
```

Фильтрация по колонке, которой нет в файле, бросает `UnexpectedValueException`.

## Поиск по шаблону (Like)

Знак `%` говорит, что значение может продолжаться в эту сторону:

```php
# Строки, начинающиеся на hi
Article::query()->whereLike('tag', 'hi%')->get();

# Строки, заканчивающиеся на hi
Article::query()->whereLike('tag', '%hi')->get();

# Строки, содержащие hi
Article::query()->whereLike('tag', '%hi%')->get();

# Ровно hi и ничего больше
Article::query()->whereLike('tag', 'hi')->get();

# Всё, что не содержит hi
Article::query()->whereNotLike('tag', '%hi%')->get();

# Как альтернатива предыдущему условию
Article::query()->where('id', 1)->orWhereLike('tag', '%hi%')->get();
Article::query()->where('id', 1)->orWhereNotLike('tag', '%hi%')->get();
```

Шаблон без подстановок сравнивается со всем значением целиком — так же ведёт себя `LIKE` в sql.

Регистр по умолчанию не учитывается, `caseSensitive` это меняет:

```php
# Найдёт NAME, name, namE, Name и так далее
User::query()->whereLike('login', 'name')->first();

# Только name
User::query()->whereLike('login', 'name', caseSensitive: true)->first();
```

Оператор, которого нет в списке сравнений, бросает `InvalidArgumentException` — опечатка вроде `lke` падает сразу, а не возвращает пустой результат.

## Сортировка, лимит и смещение

```php
# По возрастанию, поведение по умолчанию
Article::query()->orderBy('created_at')->get();

# По убыванию
Article::query()->orderByDesc('created_at')->get();
Article::query()->orderBy('created_at', SortOrder::Desc)->get();

# Несколько колонок, в порядке добавления
Article::query()
    ->orderByDesc('created_at')
    ->orderBy('id')
    ->limit(3)
    ->get();

# Записи с 11 по 20
Article::query()->offset(10)->limit(10)->get();
```

Сортировка держит подходящие строки в памяти, поэтому на большом файле сначала
имеет смысл сузить выборку через `where()`.

Направление — это `SortOrder`, поэтому опечатка до запроса не доедет. Когда оно
приходит из запроса, проверкой служит `tryFrom()`: неизвестная строка становится
`null`, и срабатывает запасной вариант:

```php
$sort = SortOrder::tryFrom($_GET['dir'] ?? '') ?? SortOrder::Asc;

Article::query()->orderBy('created_at', $sort)->get();
```

`$sort->value` возвращает строку обратно — для ссылки, переключающей направление:

```php
$articles->appends(['dir' => $sort->value]);
```

## Обход большой таблицы

`get()` строит все подходящие записи и только потом их отдаёт. `cursor()` выдаёт
их по одной, поэтому в памяти живёт только та, на которую сейчас смотрят:

```php
foreach (Article::query()->where('active', 1)->cursor() as $article) {
    echo $article->title;
}
```

На таблице в 50 000 строк это 0 МБ против 33 МБ у `get()`, при той же скорости.
Ничего не накапливается, поэтому у курсора нет соседей, для которых можно
загрузить связь пачкой: обращение к связи внутри цикла читает связанную таблицу
на каждой записи, а `with()` не к чему привязаться. Если записи связанные —
берите `get()`.

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

Csv не хранит типов, поэтому все прочитанные из файла значения строковые, а пустое
значение — `null`.

Исключение — первичный ключ: его генерирует сама ORM, поэтому он читается как
`int`, если значение числовое. Нечисловой ключ остаётся строкой, так что таблице
с ключом вида `theme` или `3f2a-9b` объявлять ничего не нужно.

Больше ничего не угадывается, и уж точно не по имени колонки: `created_at` со
значением `2026-07-28 12:30:00` останется этой строкой, `uuid_id` со значением
`3f2a-9b` — этой.

Единственное место, которое знает смысл остальных колонок, — модель, поэтому типы
задаются явно в свойстве `casts`:

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

Объявление первичного ключа перекрывает то, что он получил бы сам, поэтому
числовой ключ можно оставить строкой:

```php
protected array $casts = [
    'id' => 'string',
];
```

Условия и сортировка работают с сырыми значениями файла, поэтому приведение меняет
только то, что вы читаете, и больше ничего. `where('id', 1)` и `orderBy('id')`
ведут себя одинаково независимо от того, объявлен `id` в `casts` или нет.

Поддерживаемые типы:

| Тип                       | Результат                    |
|---------------------------|------------------------------|
| `int`, `integer`          | `int`                        |
| `real`, `float`, `double` | `float`                      |
| `string`                  | `string`                     |
| `bool`, `boolean`         | `bool`                       |
| `object`                  | `json_decode($value, false)` |
| `array`                   | `json_decode($value, true)`  |

Массивы и объекты пишутся в колонку как json, независимо от того, объявлен ли
для неё каст. Колонка, в которой лежит не тот json, за который её выдали, — это
сломанная таблица, поэтому чтение падает с `UnexpectedValueException`, а не
возвращает `null`. Запись значения, которое json не умеет нести (`NAN`, `INF`,
битый UTF-8), падает так же, и таблица остаётся нетронутой.

## Условия запросов (Scope)

Scope — это обычный метод с префиксом `scope`. Именно по префиксу ORM понимает,
что перед ней scope. Внутрь передаётся запрос, на который можно навесить условия:

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

Параметры, объявленные после `$query`, заполняются из вызова:

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

## Условные выражения

`when()` выполняет замыкание, только если первый аргумент истинный. Это избавляет
от `if` вокруг необязательных фильтров:

```php
$stories = Story::query()
    ->when($active, function (Query $query, $active) {
        $query->where('active', $active);
    })
    ->get();
```

Третий аргумент выполняется, когда значение ложно:

```php
$stories = Story::query()
    ->when(
        $sortByVotes,
        fn (Query $query) => $query->orderBy('votes'),
        fn (Query $query) => $query->orderBy('name'),
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
class User extends Model
{
    public function story(): Relation
    {
        return $this->hasOne(Story::class);
    }
}

# Обратная связь
class Story extends Model
{
    public function user(): Relation
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
class Story extends Model
{
    public function comments(): Relation
    {
        return $this->hasMany(Comment::class);
    }
}
```

### Многие ко многим (hasManyThrough)

Принимает конечный класс, промежуточный класс и, по желанию, обе пары ключей.

```php
class Story extends Model
{
    public function tags(): Relation
    {
        return $this->hasManyThrough(Tag::class, TagStory::class);
    }
}
```

### Ограничения связи (constrain)

`constrain()` добавляет к связи условия, с которыми она всегда загружается.
Замыкание получает запрос к связанной таблице:

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

Работает на всех трёх видах связей и одинаково при загрузке по обращению и через
`with()`.

**`limit()` и `offset()` в ограничении относятся ко всему чтению, а не к каждой
записи.** Связь читается один раз на всю выборку, поэтому `limit(1)` вернёт одну
строку на весь результат, и достанется она той записи, которой принадлежит:

```php
Story::query()->find(1)->lastComment;            // одна история, одна строка, её
Story::query()->with('lastComment')->get();      // три истории, одна строка на всех
```

Для «последнего у каждого» сортируйте связь и берите первый элемент коллекции.

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
трогают исходную — в отличие от `put()`, `push()`, `pull()`, `forget()` и
`clear()`, которые меняют ту, на которой вызваны. Выбросить результат чистого
метода почти всегда ошибка, поэтому они помечены `#[\NoDiscard]`, и PHP об этом
предупредит.

`keyBy()` принимает и замыкание, при повторе ключа оставляет последний элемент,
а элементы без такой колонки выбрасывает:

```php
$articles->keyBy(fn ($a) => 'row' . $a->id);
```

## Пагинация

`paginate()` возвращает `Pagination` — коллекцию, знающую о страницах.
Какую страницу показывать, решаете вы: библиотека не читает запрос:

```php
$articles = Article::query()->paginate(10, (int) ($_GET['page'] ?? 1));

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

Остальное говорит, где страница стоит среди всех строк:

```php
# Показаны 11–20 из 45
printf('Показаны %d–%d из %d', $articles->firstItem(), $articles->lastItem(), $articles->total());

# Оба null, если ничего не нашлось
$articles->firstItem();
$articles->lastItem();

# Где мы находимся
$articles->onFirstPage();
$articles->onLastPage();

# Ссылка на любую страницу, не только на показанные
$articles->url($articles->lastPage());
```

Страница за пределами диапазона приводится к ближайшей, поэтому нелепое число
в запросе не даст пустой список.

### Пагинация без подсчёта

Общее число строк — это то, что покупает нумерованные ссылки, и платят за него
чтением всей таблицы. На таблице в 50 000 строк в этом и состоит вся стоимость
страницы: выбрать десять строк первой страницы стоит 0.05 ms, посчитать
остальные — 64.

`simplePaginate()` не считает. Он читает на одну строку больше, чем нужно, и то,
была ли она, — это весь ответ:

```php
$articles = Article::query()->simplePaginate(10, (int) ($_GET['page'] ?? 1));

foreach ($articles as $article) {
    echo $article->title;
}

echo $articles->withPath('/articles')->links();
```

| на 50 000 строк      | первая страница | страница 4 900 |
|----------------------|-----------------|----------------|
| `paginate(10)`       | 64.09 ms        | 128.05 ms      |
| `simplePaginate(10)` | 1.10 ms         | 62.07 ms       |

Поздние страницы по-прежнему стоят прохода до своего смещения — от этого спасёт
только индекс. Пропало то, что покупалось подсчётом: нет `total()` и
`lastPage()`, а навигация состоит из двух стрелок вместо нумерованных страниц.
Остальное на месте — `currentPage()`, `perPage()`, `firstItem()`, `lastItem()`,
`onFirstPage()`, `onLastPage()`, `hasMorePages()`, `url()`, `links()`.

`simplePaginate()` возвращает `SimplePagination`. Методов, которым нужен
подсчёт, у него просто нет, поэтому обращение к ним падает там, где написано, а
не в глубине библиотеки.

Оба — коллекции строк своей страницы и делят общего предка `Paginator`, в
котором лежит всё, что у них одинаково.

`links()` печатает разметку Bootstrap 5. Для любой другой передайте свой шаблон,
остальное настраивается через `setPageName()` и `onEachSide()`:

```php
echo $articles->setPageName('p')->onEachSide(3)->links(__DIR__ . '/views/pagination.php');
```

В шаблон приходит `$pages` — массив объектов `Page`:

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

`name` — что печатать, номер страницы или стрелка, `url` — куда ведёт (`null` у
текущей страницы и у разделителя), `number` — номер страницы, на которую ведёт.
Экранирование — дело шаблона: встроенный печатает html, а ваш может и не печатать.

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

### Сравнение с сырым PHP

Те же операции, написанные через `fopen` + `fgetcsv`, рядом с запросами ORM —
это [таблица из раздела про производительность](#производительность):

```bash
composer compare

php benchmarks/compare.php --rows=200000 --runs=5
```

Таблица берётся та же, что и у `bench`, поэтому сгенерированная один раз она
годится для обоих.

## Лицензия

MIT
