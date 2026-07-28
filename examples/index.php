<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

use MotorORM\Query;
use MotorORM\Collection;
use MotorORM\Record;
use MotorORM\Tests\Models\Story;
use MotorORM\Tests\Models\Article;
use MotorORM\Tests\Models\User;

$cli = PHP_SAPI === 'cli';
$eol = $cli ? PHP_EOL : '<br>';

if (! $cli) {
    echo '<pre>';
}

/**
 * Print a section header
 */
$title = static function (string $text) use ($eol): void {
    echo $eol . '=== ' . $text . ' ===' . $eol;
};

/**
 * Print records as a compact table
 */
$show = static function (mixed $result) use ($eol): void {
    if ($result instanceof Record) {
        echo json_encode($result->toArray(), JSON_UNESCAPED_UNICODE) . $eol;

        return;
    }

    if ($result instanceof Collection) {
        if ($result->isEmpty()) {
            echo '(пусто)' . $eol;

            return;
        }

        foreach ($result as $row) {
            echo json_encode($row->toArray(), JSON_UNESCAPED_UNICODE) . $eol;
        }

        return;
    }

    echo var_export($result, true) . $eol;
};

$title('Поиск по первичному ключу');
$show(Article::query()->find(1));

$title('Поиск по имени, лимит 1');
$show(Article::query()->where('name', 'Миша')->limit(1)->get());

$title('Последняя запись с именем Миша');
$show(Article::query()->where('name', 'Миша')->orderByDesc('id')->first());

$title('Два условия');
$show(Article::query()->where('name', 'Миша')->where('title', 'Заголовок10')->get());

$title('Группа условий: Миша AND (id = 10 OR id = 11)');
$show(
    Article::query()
        ->where('name', 'Миша')
        ->where(static function (Query $query) {
            $query->where('id', 10)->orWhere('id', 11);
        })
        ->get()
);

$title('Условие со сравнением');
$show(Article::query()->where('created_at', '>=', '2009-01-06 08:40:35')->get());

$title('Поиск like');
$show(Article::query()->where('title', 'like', '%овок15')->get());

$title('Регистронезависимый поиск lax');
$show(Article::query()->where('name', 'lax', 'миша')->limit(2)->get());

$title('whereIn');
$show(Article::query()->whereIn('id', [1, 3, 4, 7])->get());

$title('whereNotIn');
$show(Article::query()->whereNotIn('id', range(1, 15))->get());

$title('Количество записей');
$show(Article::query()->where('created_at', '>', '2009-01-06 08:40:34')->count());

$title('Смещение и лимит');
$show(Article::query()->offset(0)->limit(3)->get());

$title('Последние 3 записи');
$show(Article::query()->orderByDesc('id')->limit(3)->get());

$title('Двойная сортировка (created_at asc, id desc)');
$show(Article::query()->where('name', 'Миша')->orderBy('created_at')->orderByDesc('id')->limit(3)->get());

$title('Заголовки таблицы');
$show(Article::query()->headers());

$title('Scope');
$show(Article::query()->misha()->limit(2)->get());

$title('Условный запрос when');
$show(
    Article::query()
        ->when(true, static fn (Query $query) => $query->where('name', 'Миша'))
        ->limit(2)
        ->get()
);

$title('Связь hasOne: автор истории');
$show(Story::query()->find(1)->user);

$title('Связь hasMany: истории пользователя');
$show(User::query()->find(1)->stories);

$title('Связь hasManyThrough: теги истории');
$show(Story::query()->find(1)->tags);

$title('Связь в цикле без with, файл читается один раз на всю выборку');
foreach (Story::query()->get() as $story) {
    printf('%s — автор %s%s', $story->title, $story->user->login, $eol);
}

$title('Жадная загрузка with, тот же результат загруженный заранее');
foreach (Story::query()->with(['user', 'tags'])->get() as $story) {
    printf(
        '%s — автор %s, теги: %s%s',
        $story->title,
        $story->user->login,
        $story->tags->isEmpty() ? '—' : implode(', ', $story->tags->pluck('name')->all()),
        $eol
    );
}

$title('Пагинация');
$paginate = Article::query()->paginate(5, (int) ($_GET['page'] ?? 2));
printf(
    'страница %d из %d, показаны %d–%d из %d%s',
    $paginate->currentPage(),
    $paginate->lastPage(),
    $paginate->firstItem(),
    $paginate->lastItem(),
    $paginate->total(),
    $eol,
);
$show($paginate);
echo $paginate->withPath('/list')->links() . $eol;

$title('Пагинация без подсчёта');
$simple = Article::query()->simplePaginate(5, (int) ($_GET['page'] ?? 2));
printf(
    'страница %d, показаны %d–%d, дальше %s%s',
    $simple->currentPage(),
    $simple->firstItem(),
    $simple->lastItem(),
    $simple->hasMorePages() ? 'есть' : 'ничего',
    $eol,
);
echo $simple->withPath('/list')->links() . $eol;
