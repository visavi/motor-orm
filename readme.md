# Motor ORM

Данный скрипт предоставляет ООП подход для работы текстовыми данными сохраненными в файловой системе

Структура данных CSV совместима, но с некоторыми изменения для более быстрой работы

### Возможности

#### Builder
- Поиск по уникальному ключу
- Поиск по любым заданным условиям
- Возврат структуры файла
- Возврат количества записей в файле
- Возврат информации о существовании записи
- Сортировка строк
- Запись строки в файл с генерацией автоинкрементного ключа
- Обновление записей по любым условиям
- Удаление записей по любым условиям
- Очистка файла
- Жадная загрузка
- Связь один к одному
- Связь один ко многим


#### Collection
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

#### Collection Paginate
- Расширяет класс Collection
- Получение текущей странице
- Получение количества страниц
- Получение массива со страницами

#### Migration
- Добавляет поле
- Удаляет поле
- Переименовывает поле

Работы с изменениями в файле, в том числе и вставка выполняется с блокировкой файла для защиты от случайного удаления данных в случае если несколько пользователей одновременно пишут в файл

Первых столбец в файле считается уникальным

Может быть строковым и числовым

Если столбец строковой, то все вставки должны быть с уже заданным уникальным ключом

Если столбец числовой, то уникальный ключ будет генерироваться автоматически

### Запросы

Все запросы проводятся через модели в котором должен быть указан путь к файлу с данными
В самих моделях могут быть реализованы дополнительные методы

### Примеры

```php

# Create class
use MotorORM\Builder;

class TestModel extends Builder
{
    public string $filePath = __DIR__ . '/test.csv';
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

# Delete strings
TestModel::query()->where('name', 'Миша')->delete();

# Truncate file
TestModel::query()->truncate();
```
