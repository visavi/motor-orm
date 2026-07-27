<?php

/**
 * Benchmark of the read and write paths.
 *
 * php benchmarks/bench.php
 * php benchmarks/bench.php --rows=200000 --runs=5
 * php benchmarks/bench.php --filter=find
 *
 * Every case runs in its own process, so the reported peak memory belongs to
 * that case alone. Write cases work on a scratch copy of the table.
 */

declare(strict_types=1);

use MotorORM\Benchmarks\Models\Bench;
use MotorORM\Benchmarks\Models\BenchWrite;
use MotorORM\Builder;

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'MotorORM\\Benchmarks\\';

    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

const DATA_DIR    = __DIR__ . '/data';
const TABLE       = DATA_DIR . '/bench.csv';
const WRITE_TABLE = DATA_DIR . '/bench_write.csv';

$options = getopt('', ['rows::', 'runs::', 'filter::', 'case::']);
$rows    = (int) ($options['rows'] ?? 50000);
$runs    = max(1, (int) ($options['runs'] ?? 3));
$filter  = $options['filter'] ?? null;

/** Cases that only read the table */
$readCases = [
    'count() всей таблицы'        => static fn () => Bench::query()->count() . ' зап.',
    'count() с условием'          => static fn () => Bench::query()->where('name', 'Имя7')->count() . ' зап.',
    'exists()'                    => static fn () => Bench::query()->where('name', 'Имя7')->exists() ? 'да' : 'нет',
    'first() без условия'         => static fn () => Bench::query()->first()->title,
    'first() по условию'          => static fn () => Bench::query()->where('name', 'Имя7')->first()->title,
    'find() в начале файла'       => static fn () => Bench::query()->find(3)->title,
    'find() в конце файла'        => static fn () => Bench::query()->find(lastId())->title,
    'find() несуществующего'      => static fn () => Bench::query()->find(PHP_INT_MAX) === null ? 'null' : '?',
    'where + limit 10'            => static fn () => count(Bench::query()->where('name', 'Имя7')->limit(10)->get()) . ' зап.',
    'where -> get()'              => static fn () => count(Bench::query()->where('name', 'Имя7')->get()) . ' зап.',
    'get() всей таблицы'          => static fn () => count(Bench::query()->get()) . ' зап.',
    'orderByDesc + limit 10'      => static fn () => count(Bench::query()->orderByDesc('id')->limit(10)->get()) . ' зап.',
    'orderBy двойная сортировка'  => static fn () => count(Bench::query()->orderBy('time')->orderByDesc('id')->limit(10)->get()) . ' зап.',
    'paginate(10)'                => static fn () => count(Bench::query()->paginate(10)) . ' зап.',
    'whereIn 100 значений'        => static fn () => count(Bench::query()->whereIn('id', range(1, 100))->get()) . ' зап.',
    'like %подстрока%'            => static fn () => Bench::query()->where('title', 'like', '%овок499%')->count() . ' зап.',
    'три условия AND'             => static fn () => Bench::query()
        ->where('name', 'Имя7')
        ->where('time', '>=', 1231231234)
        ->where('id', '<', PHP_INT_MAX)
        ->count() . ' зап.',
    'группа условий (замыкание)'  => static fn () => Bench::query()
        ->where('name', 'Имя7')
        ->where(static function (Builder $query) {
            $query->where('id', 7)->orWhere('id', 107);
        })
        ->count() . ' зап.',
];

/** Cases that modify the table, each gets a fresh copy */
$writeCases = [
    'create() одной записи' => static fn () => BenchWrite::query()
        ->create(['name' => 'Новый', 'title' => 'Заголовок', 'text' => 'Текст', 'time' => 1])->id . ' id',
    'save() одной записи'   => static fn () => BenchWrite::query()->find(1)->save() ? 'ок' : 'нет',
    'update() одной записи' => static fn () => BenchWrite::query()->where('id', 1)->update(['title' => 'Обновлено']) . ' зап.',
    'delete() одной записи' => static fn () => BenchWrite::query()->where('id', 1)->delete() . ' зап.',
    'truncate()'            => static fn () => BenchWrite::query()->truncate() ? 'ок' : 'нет',
];

$cases = $readCases + $writeCases;

/* Child process: run one case and report its own numbers */
if (isset($options['case'])) {
    runCase($options['case'], $cases, isset($writeCases[$options['case']]));
}

generateTable($rows);

$fileSize = filesize(TABLE);

printf(
    'Таблица: %s строк, %.1f MB   PHP %s   лучшее из %d прогонов%s%s',
    number_format($rows, 0, '.', ' '),
    $fileSize / 1024 / 1024,
    PHP_VERSION,
    $runs,
    PHP_EOL,
    PHP_EOL
);

echo row('операция', 'время', 'память', 'к файлу', 'результат');
echo str_repeat('-', 84), PHP_EOL;

$separated = false;
foreach ($cases as $name => $case) {
    if ($filter !== null && ! str_contains(mb_strtolower($name), mb_strtolower($filter))) {
        continue;
    }

    if (! $separated && isset($writeCases[$name])) {
        echo str_repeat('-', 84), PHP_EOL;
        $separated = true;
    }

    [$ms, $memory, $result] = measure($name, $runs);

    echo row(
        $name,
        sprintf('%.2f ms', $ms),
        sprintf('%.1f MB', $memory / 1024 / 1024),
        sprintf('%.1fx', $fileSize > 0 ? $memory / $fileSize : 0),
        $result
    );
}

@unlink(WRITE_TABLE);

/**
 * Execute a single case in this process and print its measurement as json
 *
 * @param array<string, callable> $cases
 */
function runCase(string $name, array $cases, bool $isWrite): never
{
    if (! isset($cases[$name])) {
        fwrite(STDERR, sprintf('Неизвестный случай: %s%s', $name, PHP_EOL));
        exit(1);
    }

    if ($isWrite) {
        copy(TABLE, WRITE_TABLE);
    }

    $before = memory_get_peak_usage();
    $start  = hrtime(true);
    $result = $cases[$name]();
    $ms     = (hrtime(true) - $start) / 1_000_000;
    $memory = memory_get_peak_usage() - $before;

    if ($isWrite) {
        @unlink(WRITE_TABLE);
    }

    echo json_encode([
        'ms'     => $ms,
        'memory' => $memory,
        'result' => (string) $result,
    ], JSON_UNESCAPED_UNICODE);

    exit(0);
}

/**
 * Run a case in a subprocess several times and keep the best time
 *
 * @return array{0: float, 1: int, 2: string}
 */
function measure(string $name, int $runs): array
{
    $best   = INF;
    $memory = 0;
    $result = 'ошибка';

    for ($i = 0; $i < $runs; $i++) {
        $output = shell_exec(sprintf(
            '%s %s --case=%s 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__FILE__),
            escapeshellarg($name)
        ));

        $data = json_decode((string) $output, true);

        if (! is_array($data)) {
            return [0.0, 0, 'ошибка'];
        }

        if ($data['ms'] < $best) {
            $best   = $data['ms'];
            $memory = $data['memory'];
            $result = $data['result'];
        }
    }

    return [$best, $memory, $result];
}

/**
 * Format one table row, padding by characters rather than bytes
 */
function row(string $name, string $time, string $memory, string $ratio, string $result): string
{
    return pad($name, 30)
        . pad($time, 12, STR_PAD_LEFT)
        . pad($memory, 12, STR_PAD_LEFT)
        . pad($ratio, 10, STR_PAD_LEFT)
        . '   ' . $result
        . PHP_EOL;
}

/**
 * Multibyte aware padding
 */
function pad(string $value, int $width, int $type = STR_PAD_RIGHT): string
{
    $padding = str_repeat(' ', max(0, $width - mb_strlen($value)));

    return $type === STR_PAD_LEFT ? $padding . $value : $value . $padding;
}

/**
 * Build the fixture table, reusing it when the row count already matches
 */
function generateTable(int $rows): void
{
    if (! is_dir(DATA_DIR) && ! mkdir(DATA_DIR, 0777, true) && ! is_dir(DATA_DIR)) {
        fwrite(STDERR, 'Не удалось создать каталог ' . DATA_DIR . PHP_EOL);
        exit(1);
    }

    if (is_file(TABLE) && countLines(TABLE) - 1 === $rows) {
        return;
    }

    printf('Генерирую таблицу на %s строк...%s', number_format($rows, 0, '.', ' '), PHP_EOL);

    $file = new SplFileObject(TABLE, 'w');
    $file->setCsvControl(...Builder::CSV_CONTROL);
    $file->fputcsv(['id', 'name', 'title', 'text', 'time']);

    for ($i = 1; $i <= $rows; $i++) {
        $file->fputcsv([
            $i,
            'Имя' . ($i % 100),
            'Заголовок' . $i,
            'Текст текст текст',
            1231231234 + ($i % 1000),
        ]);
    }
}

/**
 * Count lines without loading the whole file
 */
function countLines(string $path): int
{
    $lines  = 0;
    $handle = fopen($path, 'rb');

    while (! feof($handle)) {
        $lines += substr_count((string) fread($handle, 1024 * 1024), "\n");
    }

    fclose($handle);

    return $lines;
}

/**
 * Highest primary key of the fixture, read straight from the tail of the file
 * so that measuring a case does not also measure this lookup
 */
function lastId(): int
{
    static $id = null;

    if ($id !== null) {
        return $id;
    }

    $handle = fopen(TABLE, 'rb');
    fseek($handle, max(-4096, -filesize(TABLE)), SEEK_END);
    $tail = (string) fread($handle, 4096);
    fclose($handle);

    $lines = array_filter(explode("\n", $tail));

    return $id = (int) strtok((string) end($lines), ',');
}
