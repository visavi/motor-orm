# Motor ORM

Данный скрипт предоставляет ООП подход для работы текстовыми данными сохраненными в файловой системе

Структура данных CSV совместима, но с некоторыми изменения для более быстрой работы

## Возможности

### Builder
- Поиск по уникальному ключу
- Поиск по любым заданным условиям
- Возврат структуры файла
- Возврат количества записей в файле
- Возврат информации о существовании записи
- Сортировка строк
- Запись строки в файл с генерацией автоинкрементного ключа
- Обновление записей по любым условиям
- Удаление записей по любым условиям
- Приведение типов (Casts)
- Scope
- Очистка файла
- Жадная загрузка
- Связь один к одному
- Связь один ко многим
- Связь многие ко многим


### Collection
- Преобразование коллекции в массив
- Получение первой записи
- Получение последней записи
- Получение количества записей в коллекции
- Добавление записи в коллекцию
- Удаление записи из коллекции
- Установка значения в коллекции
- Проверка коллекции на пустоту
- Очистка коллекции
- Срез коллекции
- Обход с получением ключа и значения из коллекции

### Collection Paginate
- Расширяет класс Collection
- Получение текущей странице
- Получение количества страниц
- Получение массива со страницами

### Migration
- [Создание таблицы](#Создание-таблицы)
- [Удаление таблицы](#Удаление-таблицы)
- [Создание колонок](#Создание-колонок)
- [Переименовывание колонок](#Переименовывание-колонок)
- [Удаление колонок](#Удаление-колонок)
- [Проверка существования](#Проверка-существования-таблицыколонки)

Работы с изменениями в файле, в том числе и вставка выполняется с блокировкой файла для защиты от случайного удаления данных в случае если несколько пользователей одновременно пишут в файл

Первых столбец в файле считается уникальным

Может быть строковым и числовым

Если столбец строковой, то все вставки должны быть с уже заданным уникальным ключом

Если столбец числовой, то уникальный ключ будет генерироваться автоматически

## Запросы

Все запросы проводятся через модели в котором должен быть указан путь к файлу с данными
В самих моделях могут быть реализованы дополнительные методы

## Примеры

```php

# Create class
use MotorORM\Builder;

class TestModel extends Builder
{
    public string $table = __DIR__ . '/test.csv';
}

# Find by primary key
TestModel::query()->find(1);

# Find by name limit 1
TestModel::query()->where('name', 'Миша')->limit(1)->get();

# Find by name and first 1
TestModel::query()->where('name', 'Миша')->first();

# Find by name and title
TestModel::query()->where('name', 'Миша')->where('title', 'Заголовок10')->get();

# Get from condition
TestModel::query()->where('time', '>=', 1231231235)->get();

# Get by condition in
TestModel::query()->whereIn('id', [1, 3, 4, 7])->get();

# Get by condition not in
TestModel::query()->whereNotIn('id', [1, 2, 3, 4, 5, 6, 7, 8, 9, 10])->get();

# Get records by multiple conditions and pagination
TestModel::query()
    ->where(function(Builder $builder) {
        $builder->where('name', 'Миша');
        $builder->orWhere(function(Builder $builder) {
            $builder->where('name', 'Петя');
            $builder->where('title', '<>', '');
        });
    })
    ->paginate(10);

# Get count
TestModel::query()->where('time', '>', 1231231234)->count();

# Get lines 1 - 10
$lines = TestModel::query()->offset(0)->limit(10)->get();

# Get last 10 records
$lines = TestModel::query()->orderByDesc('created_at')->offset(0)->limit(10)->get();

# Get headers
TestModel::query()->headers();

# Get first line
TestModel::query()->first();

# Get first 3 lines
TestModel::query()->limit(3)->get();

# Get last 3 lines
TestModel::query()->orderByDesc('created_at')->limit(3)->get();

# Find by name and double sort (time desc, id asc)
Test::query()
    ->where('name', 'Миша')
    ->orderByDesc('time')
    ->orderBy('id')
    ->limit(3)
    ->get();

# Create string
TestModel::query()->create(['name' => 'Миша']);

# Update strings
TestModel::query()->where('name', 'Миша')->update(['text' => 'Новый текст']);

# Update string
$test = TestModel::query()->where('name', 'Миша')->first();
$test->text = 'Новый текст';
$test->save();

# Update strings
$testModel = TestModel::query()->find(17);
$affectedLines = $testModel->update(['text' => 'Новый текст']);

# Delete records
TestModel::query()->where('name', 'Миша')->delete();

# Delete records
$records = TestModel::query()->get();
foreach($records as $record) {
    $record->delete();
}

# Truncate file
TestModel::query()->truncate();
```

### Частичный поиск (Like)
Поиск по частичному совпадению

```php
// Строки начинающиеся на hi
$test = TestModel::query()->where('tag', 'like', 'hi%')->get();

// Строки заканчивающиеся на hi
$test = TestModel::query()->where('tag', 'like', '%hi')->get();

// Строки содержащие hi
$test = TestModel::query()->where('tag', 'like', '%hi%')->get();

// Этот запрос эквивалентен запросу выше
$test = TestModel::query()->where('tag', 'like', 'hi')->get();
```

### Нестрогий поиск (Lax)
Поиск по нестрогому совпадению

При поиске orm использует строгое сравнение, чтобы задействовать нестрогий режим, можно использовать lax
```php
// Будут найдено первое совпадение NAME, name, namE, Name итд
$user = User::query()->where('login', 'lax', 'name')->first();
```

### Приведение типов (Casts)
По умолчанию все поля полученные из файла строковые

За некоторыми исключениями
- Поле primary key - int
- Поля заканчивающиеся на _id и _at - int
- Пустые поля - null

Для переопределения используйте свойство casts

```php
class Story extends Model
{
    protected array $casts = [
        'rating' => 'int',
        'reads'  => 'int',
        'locked' => 'bool',
    ];
}
```
Поддерживаются следующие типы
- 'int', 'integer' => int
- 'real', 'float', 'double' => float
- 'string' => string
- 'bool', 'boolean' => bool
- 'object' => json_decode($value, false),
- 'array' => json_decode($value, true),

### Условия запросов (Scope)

Каждый scope — это обычный метод, который начинается с префикса scope. Именно по префиксу ORM понимает, что это scope. Внутрь scope передаётся запрос, на который можно навешивать дополнительные условия.

```php
class Story extends Model
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
```

Использование:

```php
Story::query()
    ->active()
    ->paginate($perPage);
```

#### Динамические условия
Некоторые scope зависят от параметров, передающихся в процессе составления запроса. Для этого достаточно описать эти параметры внутри scope после параметра $query:

```php
class Story extends Model
{
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
```

Использование:
```php
Story::query()
    ->ofType('new')
    ->paginate($perPage);
```

### Условные выражения (Conditional clauses)
Иногда вам может понадобиться, чтобы определенный запроса выполнялся на основе другого условия. Например, вы можете захотеть применить where оператор только в том случае, если заданное входное значение присутствует во входящем HTTP-запросе. Вы можете сделать это, используя when метод:

```php
$active = true;

$stories = Story::query()
    ->when($active, function (Story $query, $active) {
        $query->where('active', $active);
    })
    ->get();
```

Метод when выполняет данное замыкание только тогда, когда первый аргумент равен true. Если первый аргумент равен false, замыкание не будет выполнено.

Вы можете передать другое замыкание в качестве третьего аргумента when метода. Это замыкание будет выполняться только в том случае, если первый аргумент оценивается как false. Чтобы проиллюстрировать, как можно использовать эту функцию, мы будем использовать ее для настройки порядка запросов по умолчанию:

```php
$sortByVotes = 'sort_by_votes';

$users = Story::query()
    ->when($sortByVotes, function ($query, $sortByVotes) {
        $query->orderBy('votes');
    }, function ($query) {
        $query->orderBy('name');
    })
    ->get();
```

### Связи (Relations)
В данный момент поддерживается 3 вида связей
- hasOne - один к одному
- hasMany - один ко многим
- hasManyThrough - многие ко многим

#### Один к одному (hasOne)
3 параметра, имя класса, внешний и внутренний ключ

Внешний и внутренний ключ определяются автоматически, за исключением когда имена полей не совпадают с именем класса или если связь обратная belongsTo (Возможно в будущем это будет реализовано)
```php
// Прямая связь
class User extends Model
{
    public function story(): Builder
    {
        return $this->hasOne(Story::class);
    }
}

// Обратная связь
class Story extends Model
{
    
    public function user(): Builder
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }
}
```

#### Один ко многим (hasMany)
3 параметра, имя класса, внешний и внутренний ключ

Внешний и внутренний ключ определяются автоматически, за исключением когда имена полей не совпадают с именем класса
```php
class Story extends Model
{
    public function comments(): Builder
    {
        return $this->hasMany(Comment::class);
    }
}
```

#### Многие ко многим (hasManyThrough)
5 параметров, имя конечного класса, имя промежуточного класса, внешние и внутренние ключи

Внешние и внутренние ключи определяются автоматически, за исключением когда имена полей не совпадают с именами классов
```php
class Story extends Model
{
    public function tags(): Builder
    {
        return $this->hasManyThrough(Tag::class, TagStory::class);
    }
}
```

### Жадная загрузка (Eager load)
По умолчанию все связи с ленивой загрузкой (lazy load)

Связь не будет загружена до тех пор, пока явно не будет вызвана

Для того чтобы жадно загрузить данные необходимо вызвать метод with и передать имена связей, которые требуется жадно загрузить

```php
class StoryRepository implements RepositoryInterface

    public function getStories(int $perPage): CollectionPaginate
    {
        return Story::query()
            ->orderByDesc('locked')
            ->orderByDesc('created_at')
            ->with(['user', 'comments'])
            ->paginate($perPage);
    }
}
```

Жадная загрузка извлекает данные используя всего несколько запросов. Это позволяет избежать проблемы N + 1.

Представьте, что у вас есть этот код, который находит 10 сообщений, а затем отображает имя автора каждого сообщения.

```php
foreach ($storyRepository->getStories(10) as $story) {
    echo $story->user->login;
}
```

Без ленивой загрузки при каждой итерации цикла было бы обращение в файловую систему для получения данных, то есть 1 запрос на получение списка постов и 10 на получение пользователей

Жадная загрузка избавляет от этой проблемы, 1 запрос на получение списка постов и 1 на получение пользователей этих постов

### Миграции

Для вызова класса миграции необходимо в конструктор передать нужную нам модель
```php
$migration = new Migration(new Test());
```

#### Создание таблицы
Создание таблицы, пример создания файла test.csv с пятью полями

```php
$migration->createTable(function (Migration $table) {
    $table->create('id');
    $table->create('title');
    $table->create('text');
    $table->create('user_id');
    $table->create('created_at');
});
```

#### Удаление таблицы
```php
$migration->deleteTable();
```

#### Создание колонок
```php
$migration->changeTable(function (Migration $table) {
    // Создаст колонку text c текстом по умолчанию "Текст" после колонки title
    $table->create('text')->default('Текст')->after('title'); 
    
    // Создаст колонку test перед колонкой id
    $table->create('test')->before('id'); 
});
```

#### Переименовывание колонок
```php
$migration->changeTable(function (Migration $table) {
    // Переименует user_id в author_id
    $table->rename('user_id', 'author_id'); 
});
```

#### Удаление колонок
```php
$migration->changeTable(function (Migration $table) {
    // Удалит колонку title
    $table->delete('title');
});
```

#### Проверка существования таблицы/колонки
```php
// Проверит существование таблицы
$migration->hasTable();

// Проверит существование колонки
$migration->hasColumn('field');
```
